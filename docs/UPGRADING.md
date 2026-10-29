# Updates and rollback

Use `https://github.com/metalogico/nolife/archive/COMMIT.tar.gz` for a source archive matching a pinned image, replacing COMMIT with the full published main commit SHA.

## Before every update

1. Review the target commit and migration requirements. Retain the current image tag or digest and installation files.
2. Create and verify a [backup](installation.md#backup-and-restore), export it off the host, and preserve `.env`. Use the backup command rather than copying a live SQLite file.
3. Download the matching source archive into a separate directory. Compare its Compose and example environment with your installation, then apply the required configuration changes. Keep your private `.env` and override files.
4. Set `NOLIFE_IMAGE=ghcr.io/metalogico/nolife:sha-COMMIT` in `.env`, replacing COMMIT with the full published `main` commit SHA. Use `latest` if you do not need a pinned version.

Use the same `-p`, `--env-file` and `-f` options on every command when your installation uses them. The commands below assume the default files and project name:

```sh
docker compose config --quiet
docker compose pull
docker compose stop
docker compose up -d --wait
docker compose ps
```

Pull before stopping to reduce downtime. The app applies migrations before becoming healthy; the worker and scheduler start afterward. If startup or a migration fails, stop the stack and inspect its logs before restarting. After an update, sign in, check existing games and media, and verify that editing and backups work.

Container recreation preserves persistent storage. **Do not run `down -v`, `migrate:fresh` or volume pruning during an update.** Keep the existing application key and credentials.

## Switching from source builds

Keep the existing Compose project name, environment file and data mounts when switching to a published image. A different project name selects new empty volumes.

Test the target image with a restored backup in a separate project, using copied data and a different port. Older development databases may differ from the released schema; resolve differences with a tested migration or import before updating the live installation. Do not edit migration history to force startup.

Replace the local image reference with the GHCR reference and remove `compose.build.yaml` from the command or `COMPOSE_FILE`. Preserve any backup override and disk marker, then follow the update procedure above.

## Rollback

An older image may be incompatible with a migrated database. Recovery requires the previous image, its configuration and the matching pre-update backup, keeping the same `APP_KEY`. Test the [restore procedure](installation.md#restore-the-active-collection) with that image in an isolated project before applying it to the live collection. Stop background writers and use maintenance mode during the restore.
