# Building and publishing NoLife

## Workflow

Run tests locally before merging. A push to `main` triggers `.github/workflows/publish.yml`, which builds images for `linux/amd64` and `linux/arm64` and publishes:

- `ghcr.io/metalogico/nolife:latest`: the current published main build.
- `ghcr.io/metalogico/nolife:sha-<full commit>`: the specific source commit, useful for pinned installations and recovery.

The SHA is the commit on `main`, also recorded in the image revision label. Pin an image digest when you need the exact build: rebuilding the same commit can produce a different digest.

Pushes to other branches, pull requests, Git tags and GitHub Releases do not trigger publication. Git version tags do not create corresponding Docker tags. The workflow does not run tests or deploy to a server.

## GitHub setup

Enable Actions and allow the pinned actions in `publish.yml`. The workflow uses `GITHUB_TOKEN` with `contents: read` and `packages: write`. The GHCR package must grant the repository Actions write access.

Set the GHCR package to **Public** to allow anonymous pulls. Wait for the workflow to finish before updating an installation.

## Local checks before merging

Use a development checkout with its own environment and test data. Local checks require PHP 8.4 with the extensions listed in the Dockerfile, Composer, Node 24 and Python 3. Fetch `origin/main` and stable Git tags before running the migration guard.

```sh
python3 .github/scripts/check-migrations.py
composer install
npm ci
npm run typecheck
npm run build
vendor/bin/pint --dirty --format agent
php artisan test --compact
```

Build and exercise the image-only installation locally:

```sh
docker build -t nolife:ci .
python3 .github/scripts/smoke-install.py
python3 .github/scripts/smoke-mcp.py --image nolife:ci
```

Both smoke scripts use temporary Compose projects and volumes, then remove them. They check startup and persistence; the MCP script also checks authentication, permissions, key management and tools. To check another architecture, build with `--platform linux/amd64` or `--platform linux/arm64` and run that image.

## Source builds for development

```sh
docker compose -f compose.yaml -f compose.build.yaml build
docker compose -f compose.yaml -f compose.build.yaml up -d --wait
```

Keep both `-f` options for subsequent commands. The override uses `nolife:dev`; use the same image for all services sharing the development database. See [Separate installations](installation.md#separate-installations) for isolated configuration and storage.

## Database migrations

Released migration filenames and contents are immutable. Add schema changes as new timestamped Laravel migrations in `database/migrations/`. App startup runs `migrate --force`; it must never reset or seed the database.

The migration guard checks `.github/migrations-v1.json` and migrations from `origin/main` and stable Git tags. Do not change the baseline hashes to bypass a failure. Test new migrations against a fresh database and an upgrade from the previous release with existing records and media. Verify data preservation and recovery after failure. Document restore requirements when a schema change prevents running an older image.

See [UPGRADING.md](UPGRADING.md) for update and rollback procedures.
