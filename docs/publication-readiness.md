# Public repository preparation

Prepared on 29 October 2026. All changes remain local for review and the first commit.

## Changes

- Expanded compressed Vue, TypeScript and CSS into consistent formatting. Added explicit control-flow blocks and clearer names for request cancellation and cache timestamps. Extracted game selection and update messages from nested expressions, and moved the cover error handler into a named function.
- Clarified PHP image processing, gallery ownership validation, IGDB background filtering, release-date ordering and backup paths. Added comments only for nested locking, SQLite WAL snapshots and asynchronous UI ordering. Application routes, response shapes, migrations, interactions and interface text remain unchanged.
- Added frontend formatting configuration. No application dependencies were added or upgraded; Composer package metadata and its lockfile content hash were synchronized without resolving new package versions.
- Replaced the operational README with public English documentation, accurate features, installation steps and the unmodified collection screenshot from the existing Sites landing. The previous README and design QA notes remain local and ignored.
- Documented credentials, optional IGDB configuration, service roles, persistent volumes, networking, updates, scheduled backups, export and recovery. Compose can select a separate private environment file and image tag for an isolated instance; existing defaults and volume names are preserved. The application timezone now uses the configured value, with the existing Europe/Rome default.
- Kept Docker base-image digests and dependency lockfiles. Expanded Dockerfile/entrypoint commands for readability and installed the existing PCNTL extension together with the other PHP extensions.
- Initialized Git on `main` with origin `https://github.com/metalogico/nolife.git`. A read-only remote check returned no branch refs. No commits, staging, pushes, releases, hosted publications or production deployments were performed.

## Verification

| Check | Result |
| --- | --- |
| Backend before and after revision | 67 Pest tests, 292 assertions passed |
| PHP style | Pint passed with the project's required options |
| Frontend types | `npm run typecheck` passed |
| Production assets | Vite build passed locally and inside Docker |
| Frontend style | Prettier 3.6.2 check passed; formatter used from a temporary cache |
| Template equivalence | All 13 compiled Vue templates matched the originals after accounting for the unchanged cover error assignments extracted to a function |
| Stylesheet equivalence | Canonical formatting of the original CSS exactly matched the resulting stylesheet |
| Composer metadata | Strict validation passed with the application license set to MIT |
| Docker build and startup | Final source built successfully; app, worker and scheduler started in an isolated project |
| Initial setup | Documented key and interactive password-hash helpers worked; quoted hash survived Compose interpolation and authenticated successfully |
| Application smoke check | Empty initial collection, protected API, login, manual game creation, cover upload, rating and playtime updates passed |
| Persistence | Container recreation retained the fixture, rating, zero expected hours, one played hour and identical SHA-256 of the cover |
| Backup | Archive creation, export and isolated extraction passed; scheduler listed 03:30 and 15:30 |
| Recovery | Changed the fixture, stopped background services, entered maintenance, restored the archive, migrated and resumed; original values and cover hash recovered |
| Browser | Login, collection and game detail rendered; browser console had no warnings or errors |
| Publication contents | Local secret values and credential patterns were absent from Git publication candidates; private infrastructure references were absent |
| Runtime image | Real environment files, local databases and private operational notes were absent |
| Documentation | Local links and the screenshot reference resolved |

The Docker checks ran on the workstation's native Linux/ARM64 engine with a synthetic game and a generated test cover. They did not access the existing library, production credentials, external IGDB account or backup disks. A fresh AMD64 build and real IGDB requests were not repeated in this preparation. Provider behavior remains covered by the existing mocked backend tests.

The font build emits the existing optional `fontaine` optimization notice; assets still build successfully and no dependency was added to silence it. No automated browser interaction suite was added.

## Private material and retained test data

`.env`, `.env.local-access`, SQLite files, runtime storage, caches, local agent configuration, operational notes and archives are ignored by Git. Docker's build context separately excludes the private environment, storage, databases, notes and archives. Upstream dependency author metadata in lockfiles and existing third-party asset notices remain intact. The requested screenshot is the same image already on the public landing.

The temporary `nolife-publication-check` containers and network were removed after verification. Its three named volumes and the exported synthetic archive were retained. No existing library, history or backup was deleted. Shared project documentation was read but not edited.

## License decision

On 29 October 2026, the owner selected MIT for the NoLife application. The repository now includes the complete MIT text in `LICENSE`, declares `MIT` in Composer metadata and links the license from the README. Existing RomM/platform asset notices and dependency licenses were retained separately.

No publication decisions remain open from this preparation. After reviewing the files, the owner can stage, commit and push them personally. Since this was a source directory without Git history, all publishable files currently appear as untracked.
