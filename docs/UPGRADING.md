# Updates and rollback

Use `https://github.com/metalogico/nolife/archive/COMMIT.tar.gz` for a source archive matching a pinned image, replacing COMMIT with the full published main commit SHA.

## Before every update

1. Review the target commit and migration requirements. Retain the current image tag or digest and installation files.
2. Download the matching source archive into a separate directory. Compare its Compose and example environment with your installation, then apply the required configuration changes. Keep your private `.env` and override files.
3. Set `NOLIFE_IMAGE=ghcr.io/metalogico/nolife:sha-COMMIT` in `.env`, replacing COMMIT with the full published `main` commit SHA. Use `latest` if you do not need a pinned version.

Use the same `-p`, `--env-file` and `-f` options on every command when your installation uses them. The commands below assume the default files and project name:

```sh
docker compose config --quiet
docker compose pull
docker compose stop
docker compose up -d --wait
docker compose ps
```

Pull before stopping to reduce downtime. The app applies migrations before becoming healthy; the worker starts afterward. If startup or a migration fails, stop the stack and inspect its logs before restarting. After an update, sign in, check existing games and media, and verify that editing works.

Container recreation preserves persistent storage. **Do not run `down -v`, `migrate:fresh` or volume pruning during an update.** Keep the existing application key and credentials.

## Switching from source builds

Keep the existing Compose project name, environment file and data mounts when switching to a published image. A different project name selects new empty volumes.

Test the target image in a separate project with a different port. Older development databases may differ from the released schema; resolve differences with a tested migration or import before updating the live installation. Do not edit migration history to force startup.

Replace the local image reference with the GHCR reference and remove `compose.build.yaml` from the command or `COMPOSE_FILE`, then follow the update procedure above.

## Rollback

An older image may be incompatible with a migrated database. Only switch to an older image after verifying that it supports the current schema. Keep the same `APP_KEY`, credentials and data mounts, and stop both services before changing the image. Do not reverse released migrations to force compatibility.
