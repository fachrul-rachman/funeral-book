# Docker deployment

## Topology

The Compose project is `funeral-book`. It uses four named containers:

| Container | Responsibility |
| --- | --- |
| `funeral-book-web` | Nginx, compiled assets and HTTP routing |
| `funeral-book-app` | Laravel through PHP-FPM |
| `funeral-book-worker` | Existing WhatsApp and visitation queue |
| `funeral-book-scheduler` | Existing recovery and maintenance schedules |

PostgreSQL remains outside Docker. No database, Redis, second ClamAV daemon or SSR service is created. The network is `funeral-book-network`; persistent runtime storage is `funeral-book-storage`. Do not delete this volume during updates. Existing host storage is not automatically imported into it.

The web port binds only to `127.0.0.1:8088` by default. Existing host Nginx handles the domain and TLS, then forwards to this port. PHP-FPM has no published host port. Only this deployment's containers join its network.

## Build and local checks

Run from the project directory with the existing `.env`:

```sh
docker compose config --quiet
docker compose build app web
docker compose up -d app web
```

Visit `http://localhost:8088/masuk` and `/up`. Start only `app web` while verifying locally; worker and scheduler can send real messages when enabled in the environment. Local form testing must also use a matching Turnstile hostname. Building images does not run migrations, seed data, generate application keys or start delivery jobs.

Secrets are supplied at runtime through `.env`, excluded from the build context along with uploaded documents. Node and Composer are used only in build stages. A source change requires a rebuild; this setup intentionally does not include development hot reload. Never publish full `docker compose config` output because it includes secrets.

## External database

Keep the existing database settings. Leave `DOCKER_DB_HOST` empty to preserve `DB_HOST`; set it only when the database address must differ inside the PHP containers. On Docker Desktop use `host.docker.internal` for PostgreSQL running on the host. A database's `127.0.0.1` address points at the container itself and cannot be used unchanged.

On Linux, select the actual reachable database hostname/address after inspecting its current setup. If PostgreSQL is on the Docker host, a host-gateway mapping can be added to the PHP services, with PostgreSQL listening and access rules limited to the deployment network. Do not expose PostgreSQL publicly or relax authentication. The VPS database address, listening interface and firewall/access rules must be verified before startup.

## Persistent files and scanner

Use `FUNERAL_DOCUMENT_DISK=s3` with the existing private R2 configuration for production, per [06-INTEGRATIONS.md](../docs/06-INTEGRATIONS.md) when the local specifications are available. R2 credentials stay in `.env`. Records already referring to local storage still need their original private files; changing the default disk does not migrate those records. Import those files deliberately before retiring a host-based installation. Back up the database and persistent volume independently.

The PHP image includes `/usr/bin/clamdscan`, but the daemon is intentionally external. On the VPS, connect the existing ClamAV daemon with its configuration and private socket mounted into the app, and give the app user access to that socket. `--stream` sends the upload contents, so the daemon does not need the container's temporary upload path. Set `FUNERAL_PDF_SCANNER=/usr/bin/clamdscan` only after this connection is verified. PDF uploads fail closed when scanning is unavailable. Local Docker setup alone does not establish access to the VPS daemon.

## Production activation

Check production environment settings first: `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, session settings, dedicated database, private R2, Turnstile hostname, integration endpoints/tokens and template names. Preserve the application key on updates. Do not reuse local credentials or overwrite another application's configuration.

Use `docker/host-nginx.conf.example` as a single-site reverse-proxy starting point; it does not configure TLS. Its forwarded IP and scheme headers must overwrite incoming values. Compose trusts that private proxy chain for IP and HTTPS only, not forwarded host or port. Do not expose the container port publicly without changing this trust boundary. If Cloudflare is used, configure verified Cloudflare address ranges on host Nginx before trusting its client IP header.

Before starting background work: verify database access, explicitly apply required migrations with `docker compose exec app php artisan migrate --force`, configure TLS and scanning, verify storage and log in through the public domain. Only then start the worker and scheduler:

```sh
docker compose up -d worker scheduler
```

These process queue entries already present in the configured database. Do not run an additional host worker or scheduler for this installation. The queue's reservation timeout must exceed the worker's 60-second timeout; retain the existing 90-second reservation or greater. Container shutdown allows 90 seconds for running jobs.

For an update, build the new images, apply only reviewed migrations, and recreate this project's services. The volume persists across recreation. `docker compose down` stops this project and retains its named volume; never use `down -v` as an update step. Review image/base dependency updates regularly; version tags receive upstream patch updates.
