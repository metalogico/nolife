# NoLife MCP server

NoLife exposes its library to agents through [Laravel MCP](https://laravel.com/framework/docs/mcp). The agent's MCP client connects to the existing NoLife web service using Streamable HTTP. Its choice of language model does not change the server. Ollama supplies a model; the application running that model must supply MCP support and any web-search tools.

MCP is optional and disabled by default. It adds no model runtime, series catalog or extra Docker service. A dedicated settings table stores its enabled state and key hashes alongside the library database. Internet research, identifying series chapters and comparing them with owned games remain the agent's responsibility.

## Manage MCP in Settings

Use an application image built from a source revision that includes MCP settings. Its normal startup applies the new incremental migration without changing existing games or media.

1. Sign in to NoLife and open **Settings** using the gear button at the bottom of the sidebar. On mobile, open the navigation menu first.
2. In **MCP server**, use **Generate key** under **Read only** or **Read & write**, depending on the access your agent needs.
3. Copy the key and save it in the agent's private credentials, then select **Done**. Each generated key is shown only once; NoLife stores only its SHA-256 hash.
4. Enable the **MCP server** switch and copy the **Server URL** into your agent's MCP connection.

Read-only keys permit the three consultation tools. Read & write keys also permit insertion and updates. Each level has its own key. **Regenerate key** replaces only that level's current key and immediately invalidates it for existing agents. **Revoke** removes that key's access immediately. Both actions explain their effect before confirmation.

Disabling the server immediately blocks MCP requests while preserving the keys. Re-enabling it restores access for keys that have not been revoked. These actions take effect on the next request; no terminal commands, environment edits or container restarts are needed.

The setting and hashes persist in SQLite across container recreation and are included in database backups. The plaintext keys are never saved in NoLife's database, configuration, browser storage or subsequent settings responses. Closing Settings clears the generated key from the interface. Copying uses the browser clipboard when available; otherwise the text is selected so you can copy it with your keyboard.

Only the authenticated browser account can manage these settings, with the normal CSRF protection. An agent's MCP bearer token cannot access the management API or sign in to the browser interface.

### Existing environment-based installations

For compatibility, `NOLIFE_MCP_ENABLED` and the two `NOLIFE_MCP_*_TOKEN_HASH` values are honored while no saved settings row exists. Opening Settings does not change them. The first settings change adopts the existing values into SQLite; from then on, the saved settings are authoritative. Revoked keys do not reappear from `.env` after a restart.

The legacy `nolife:mcp-token` command remains available for existing headless environment configurations. It does not update web-managed keys. New installations should use Settings for generation, activation, rotation and revocation.

## Connect an agent

Configure the following in an MCP client that supports Streamable HTTP and a bearer header. Individual clients use different configuration key names.

| Setting | Value |
| --- | --- |
| Transport | Streamable HTTP |
| URL | Your existing NoLife base URL followed by `/mcp` |
| HTTP header | `Authorization: Bearer YOUR_MCP_TOKEN` |

For example, a server reachable at `http://games.home:8093` exposes `http://games.home:8093/mcp`. Use the address reachable from the agent's machine over LAN or Tailscale. No additional NoLife port or service is required. HTTPS can use the existing reverse proxy configuration.

No OAuth discovery, public exposure or browser sign-in flow is introduced. Protect the agent's token as a credential; the write token can change the collection. An optional browser `Origin` header must match the configured `NOLIFE_URL`; normal native clients can omit it. Access is limited to 120 HTTP requests per minute per valid credential, or per source IP for invalid/missing credentials. HTTP 429 includes `Retry-After`.

A minimal protocol handshake can be tested with a client that handles the token privately:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize",
  "params": {
    "protocolVersion": "2025-06-18",
    "capabilities": {},
    "clientInfo": {"name": "my-local-agent", "version": "1.0"}
  }
}
```

Send it as an HTTP POST to `/mcp`, with the bearer header and `Content-Type: application/json`. The MCP client normally performs initialization, notifications, tool discovery and subsequent calls itself. Opening `/mcp` in a browser tab is not a protocol test: authenticated GET requests return HTTP 405 because this server accepts protocol messages via POST.

## Tools

| Name | Access | Behavior |
| --- | --- | --- |
| `search_games` | Read or write | Searches titles, filters and ranks copies, with bounded pagination |
| `get_game` | Read or write | Reads one copy by its exact library ID, including metadata and image IDs |
| `get_library_summary` | Read or write | Returns totals, counts by status, platforms, genres and supported status/format codes |
| `add_game` | Write | Creates a copy, optionally importing a known IGDB ID through the existing flow |
| `update_game` | Write | Changes only supplied fields on the specified copy ID |

Read credentials do not discover write tools and cannot invoke them by name. Permissions are checked for each request and again by the tool handler. The server does not expose deletion, arbitrary SQL, filesystem access, image uploads, internet search or tools for managing credentials.

Results are available as MCP `structuredContent`, mirrored as JSON text for clients that consume text content. Operational and validation failures return MCP tool errors with `isError: true`; they do not count as successful writes. Protocol/authentication failures use their corresponding HTTP/JSON-RPC errors. Raw credentials and internal exception details are not returned.

### Search and rankings

`search_games` accepts:

| Argument | Meaning |
| --- | --- |
| `query` | Optional partial title, maximum 255 characters; omit it to browse the library |
| `status` | `playing`, `planned`, `completed`, `tried` or `dropped` |
| `platform` | Exact owned platform name from the summary |
| `format` | `physical` or `digital` |
| `genre` | Exact genre name from the summary |
| `sort_by` | `title`, `rating`, `duration_minutes`, `total_played_minutes`, `release_year` or `created_at` |
| `sort_direction` | `asc` or `desc`; defaults to ascending for titles and descending for other fields |
| `page` | Page number, starting at 1 |
| `per_page` | 1–100 copies, default 25 |

Title matching folds Unicode case and treats straight and typographic apostrophes alike without changing stored titles. `%`, `_` and backslashes are literal search characters. Filters can be combined. Sorting has deterministic title/ID tie breakers. Every platform/format copy remains separate, including in rankings; the agent can group copies when that is what the user asks.

The response contains `games` and `meta`. `meta.next_page` is the next page number or null. Keep all other search arguments unchanged while following pages. `meta.total` counts returned candidates across all pages; `meta.matching_total` counts matches before excluding missing ranking values. `meta.missing_sort_values` counts matches excluded because the selected rating, duration or release year is unknown. There is no silent top-N truncation beyond the requested page size.

Durations are **integer minutes**: `duration_minutes` is Expected length, while `total_played_minutes` is Total played. Neither is an automatic estimate from the web. Null means missing; zero is a recorded value and remains eligible for a ranking. Personal ratings are integer scores from 1 to 10, independent of provider scores.

Examples of tool arguments:

```json
{"sort_by": "rating", "per_page": 10}
```

```json
{"sort_by": "duration_minutes", "per_page": 1}
```

```json
{"query": "assassin's creed", "per_page": 100}
```

For the final example, the agent retrieves all matching pages, then uses its own web tools to identify other chapters and compare them with the library. NoLife makes no claim that a title search establishes complete series membership. Titles and descriptions returned by the library are user data, not instructions for the agent.

### Insert and update

`add_game` requires `title`, `platform` and `format`. Status defaults to Planned; rating and playtime start unset unless supplied. It accepts the existing scalar game fields: status, rating, both time fields, summary, release year, developer, publisher and header image ID. A known `igdb_id` may be supplied during creation; this uses the existing backend integration and queues image downloads. Provider credentials remain private to NoLife. Without an IGDB ID, manual insertion makes no provider request.

`update_game` requires `id` plus at least one field to change. Search or read the copy first; titles alone cannot distinguish platforms and formats. Fields omitted from the request retain their values. Explicit null clears nullable values. A header must refer to an artwork/screenshot belonging to that same copy. Unknown fields, IGDB reassociation during update, and image uploads are rejected. Genres remain managed by the existing metadata flow.

Both tools share the application's validation and persistence service. Duplicate copies return an error; insertion never silently updates an existing copy. For example:

```json
{"title": "Example game", "platform": "PC", "format": "digital"}
```

```json
{"id": 42, "status": "completed", "rating": 9}
```

```json
{"id": 42, "duration_minutes": null, "total_played_minutes": 0}
```

## Local verification

The feature tests use isolated SQLite data and mocked provider responses:

```sh
php artisan test --compact tests/Feature/LibraryMcpTest.php tests/Feature/GenerateMcpTokenTest.php tests/Feature/McpSettingsTest.php
```

The Docker check exercises the production image without development dependencies, the default-off configuration, browser-authenticated key management with CSRF, both credential types, handshake, all five tools, Unicode search, rankings and persistence after container recreation:

```sh
docker build -t nolife:mcp-check .
python3 .github/scripts/smoke-mcp.py --image nolife:mcp-check
```

It also verifies immediate key rotation/revocation and persistence of the web settings while the environment defaults remain disabled. The script copies only Compose configuration into a temporary directory, uses a random project name and port, scrubs inherited application settings, and creates synthetic game records. Cleanup removes only that test's containers and disposable volumes. Existing configurations, libraries and backups are not used. Tests stay local; the image publication workflow remains unchanged.

Verified locally on 29 October 2026: the full Laravel suite passed (140 tests, 709 assertions), along with Pint, Vue/TypeScript checking, the production build and the Docker smoke test. Browser checks covered desktop and 390 px mobile layouts, generation of both key types, confirmation before regeneration, and disappearance of plaintext keys after dismissal and reopening Settings. The mobile layout had no horizontal overflow, and the browser reported no console warnings or errors.

