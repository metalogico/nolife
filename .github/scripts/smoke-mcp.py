"""Check the production MCP endpoint using an image and disposable Compose data."""

import argparse
import base64
import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[2]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image", default="nolife:ci", help="Already-built local NoLife image")
    args = parser.parse_args()
    project = "nolife-mcp-smoke-" + uuid.uuid4().hex[:10]
    environment = dict(os.environ)
    for key in list(environment):
        if key.startswith(("COMPOSE_", "NOLIFE_", "APP_", "DB_", "SESSION_", "IGDB_")):
            environment.pop(key)

    with tempfile.TemporaryDirectory(prefix="nolife-mcp-smoke-") as directory:
        work = Path(directory)
        shutil.copyfile(ROOT / "compose.yaml", work / "compose.yaml")
        values = {
            "APP_KEY": "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
            "NOLIFE_IMAGE": args.image,
            "NOLIFE_BIND_IP": "127.0.0.1",
            "NOLIFE_PORT": "0",
            "NOLIFE_MCP_ENABLED": "false",
            "NOLIFE_USERNAME": "smoke-user",
            "NOLIFE_PASSWORD_HASH": subprocess.check_output([
                "docker", "run", "--rm", "--entrypoint", "php", args.image,
                "-r", 'echo password_hash("disposable-mcp-smoke-password", PASSWORD_BCRYPT);',
            ], text=True).strip(),
        }

        def write_environment():
            (work / ".env").write_text("".join(f"{key}='{value}'\n" for key, value in values.items()))
            (work / ".env").chmod(0o600)

        write_environment()
        command = ["docker", "compose", "--project-directory", str(work), "--env-file", str(work / ".env"),
                   "-p", project, "-f", str(work / "compose.yaml")]

        def compose(*arguments):
            return subprocess.check_output(command + list(arguments), text=True, env=environment).strip()

        def start():
            compose("up", "-d", "--force-recreate", "--wait", "--wait-timeout", "180", "--pull", "never")
            return "http://" + compose("port", "app", "80").splitlines()[0]

        address = ""

        def rpc(method, params=None, token=None):
            headers = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream"}
            if token:
                headers["Authorization"] = "Bearer " + token
            body = json.dumps({"jsonrpc": "2.0", "id": 1, "method": method, "params": params or {}}).encode()
            request = urllib.request.Request(address + "/mcp", data=body, headers=headers, method="POST")
            try:
                response = urllib.request.urlopen(request, timeout=15)
                return response.status, json.load(response)
            except urllib.error.HTTPError as error:
                return error.code, json.load(error)

        def tool(name, arguments=None, token=None):
            status, result = rpc("tools/call", {"name": name, "arguments": arguments or {}}, token)
            assert status == 200 and not result.get("result", {}).get("isError", True), (status, result)
            return result["result"]["structuredContent"]

        try:
            compose("config", "--quiet")
            address = start()
            assert rpc("tools/list")[0] == 404, "MCP should be disabled by default"
            browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            csrf = ""

            def settings_request(path, method="GET", data=None):
                headers = {"Accept": "application/json", "Content-Type": "application/json"}
                if csrf:
                    headers["X-CSRF-TOKEN"] = csrf
                body = json.dumps(data).encode() if data is not None else (b"{}" if method == "POST" else None)
                response = browser.open(urllib.request.Request(address + "/api/v1/" + path, data=body, headers=headers, method=method), timeout=15)
                assert "no-store" in response.headers.get("Cache-Control", "") or path == "session"
                return json.load(response)

            csrf = settings_request("session")["csrf_token"]
            csrf = settings_request("session", "POST", {"username": "smoke-user", "password": "disposable-mcp-smoke-password"})["csrf_token"]
            assert settings_request("settings/mcp")["enabled"] is False
            tokens = {}
            for access in ("read", "write"):
                generated = settings_request("settings/mcp/tokens/" + access, "POST")
                tokens[access] = generated["token"]
                assert generated["settings"]["keys"][access]["configured"]
                assert tokens[access] not in json.dumps(settings_request("settings/mcp"))
            settings_request("settings/mcp", "PATCH", {"enabled": True})
            assert rpc("tools/list")[0] == 401
            assert rpc("tools/list", token="invalid")[0] == 401
            status, initialized = rpc("initialize", {
                "protocolVersion": "2025-06-18", "capabilities": {},
                "clientInfo": {"name": "NoLife Docker smoke", "version": "1.0"},
            }, tokens["read"])
            assert status == 200 and initialized["result"]["serverInfo"]["name"] == "NoLife Library"
            for access, count in (("read", 3), ("write", 5)):
                status, listed = rpc("tools/list", token=tokens[access])
                assert status == 200 and len(listed["result"]["tools"]) == count
            assert tool("get_library_summary", token=tokens["read"])["total"] == 0
            created = []
            for title, rating, length, played in (
                ("Assassin’s Creed smoke A", 7, 600, 30),
                ("ASSASSIN'S CREED smoke B", 8, 0, 120),
                ("Unknown smoke game", None, None, None),
            ):
                created.append(tool("add_game", {
                    "title": title, "platform": "PC", "format": "digital", "rating": rating,
                    "duration_minutes": length, "total_played_minutes": played,
                }, tokens["write"])["game"])
            selected = created[0]
            updated = tool("update_game", {"id": selected["id"], "status": "completed", "rating": 9}, tokens["write"])["game"]
            assert updated["duration_minutes"] == 600 and updated["total_played_minutes"] == 30
            status, denied = rpc("tools/call", {"name": "update_game", "arguments": {"id": selected["id"], "rating": 1}}, tokens["read"])
            assert status >= 400 or denied.get("result", {}).get("isError")
            first = tool("search_games", {"query": "assassin's creed", "per_page": 1}, tokens["read"])
            second = tool("search_games", {"query": "assassin's creed", "per_page": 1, "page": 2}, tokens["read"])
            assert first["meta"]["total"] == 2 and first["meta"]["next_page"] == 2 and second["meta"]["next_page"] is None
            assert {first["games"][0]["id"], second["games"][0]["id"]} == {game["id"] for game in created[:2]}
            for sort in ("rating", "duration_minutes", "total_played_minutes"):
                ranked = tool("search_games", {"sort_by": sort}, tokens["read"])
                assert ranked["meta"]["missing_sort_values"] == 1 and ranked["meta"]["total"] == 2
            assert tool("get_game", {"id": selected["id"]}, tokens["read"])["game"] == updated
            assert tool("get_library_summary", token=tokens["read"])["total"] == 3
            old_read = tokens["read"]
            tokens["read"] = settings_request("settings/mcp/tokens/read", "POST")["token"]
            assert rpc("tools/list", token=old_read)[0] == 401
            assert rpc("tools/list", token=tokens["read"])[0] == 200
            settings_request("settings/mcp/tokens/read", "DELETE")
            assert rpc("tools/list", token=tokens["read"])[0] == 401
            assert rpc("tools/list", token=tokens["write"])[0] == 200
            tokens["read"] = settings_request("settings/mcp/tokens/read", "POST")["token"]
            settings_request("settings/mcp", "PATCH", {"enabled": False})
            assert rpc("tools/list", token=tokens["write"])[0] == 404
            settings_request("settings/mcp", "PATCH", {"enabled": True})
            assert rpc("tools/list", token=tokens["write"])[0] == 200
            compose("stop")
            address = start()
            assert settings_request("settings/mcp")["enabled"] is True
            assert tool("get_game", {"id": selected["id"]}, tokens["read"])["game"] == updated
            assert tool("get_library_summary", token=tokens["read"])["total"] == 3
            print("MCP production image: browser settings, one-time keys, immediate enable/disable/rotation/revocation, handshake, permissions, all five tools and persistence passed.")
        except Exception:
            print(compose("logs", "--tail", "40"))
            raise
        finally:
            # The random project and all volumes belong only to this test invocation.
            compose("down", "--volumes", "--remove-orphans")


if __name__ == "__main__":
    main()
