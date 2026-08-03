# 03 — Local Development

Three pieces: **Herd** serves the app, **Docker Compose** provides backing services, **cloudflared**
exposes the app publicly so Meta and ioTec can reach our webhooks.

We deliberately do **not** use Laravel Sail. Herd is faster on macOS and already provides PHP 8.4.23.

---

## 1. Herd

The project lives at `/Users/sirshadrack/Herd/luminous-agent`, which is inside Herd's parked
directory, so it is served automatically at:

```
https://luminous-agent.test
```

Confirm and secure it (Herd issues a local CA cert, needed because Meta requires HTTPS end-to-end):

```bash
herd secure luminous-agent
```

Pin PHP for this site:

```bash
herd isolate php@8.4
```

**Herd does not run queue workers or schedulers.** Run those yourself:

```bash
php artisan horizon          # all queues
php artisan schedule:work    # cron-driven jobs (analytics pulls, token refresh, recrawls)
```

---

## 2. Docker Compose — backing services only

`docker/compose.dev.yml`. Ports are deliberately non-standard so they never collide with Herd's own
bundled MySQL/Redis or anything else on the machine.

| Service | Image | Host port | Purpose |
|---|---|---|---|
| postgres | `postgres:17-alpine` | 55432 | System of record |
| redis | `redis:8-alpine` | 56379 | Queues, cache, locks, rate limiters |
| meilisearch | `getmeili/meilisearch:latest` | 57700 | Message & contact search |
| minio | `minio/minio` | 59000 (API), 59001 (console) | S3-compatible media storage |
| mailpit | `axllent/mailpit` | 58025 (UI), 51025 (SMTP) | Catch outbound mail |

```yaml
# docker/compose.dev.yml
name: luminous-dev

services:
  postgres:
    image: postgres:17-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: luminous
      POSTGRES_USER: luminous
      POSTGRES_PASSWORD: secret
    ports: ["55432:5432"]
    volumes:
      - pgdata:/var/lib/postgresql/data
      - ./postgres/init:/docker-entrypoint-initdb.d:ro
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U luminous -d luminous"]
      interval: 5s
      retries: 10

  redis:
    image: redis:8-alpine
    restart: unless-stopped
    command: ["redis-server", "--appendonly", "yes", "--maxmemory-policy", "noeviction"]
    ports: ["56379:6379"]
    volumes: [redisdata:/data]
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      retries: 10

  meilisearch:
    image: getmeili/meilisearch:latest
    restart: unless-stopped
    environment:
      MEILI_MASTER_KEY: masterKeyChangeMe
      MEILI_ENV: development
    ports: ["57700:7700"]
    volumes: [meilidata:/meili_data]

  minio:
    image: minio/minio
    restart: unless-stopped
    command: server /data --console-address ":9001"
    environment:
      MINIO_ROOT_USER: luminous
      MINIO_ROOT_PASSWORD: secretsecret
    ports: ["59000:9000", "59001:9001"]
    volumes: [miniodata:/data]

  minio-init:
    image: minio/mc
    depends_on: [minio]
    entrypoint: >
      /bin/sh -c "
      mc alias set local http://minio:9000 luminous secretsecret &&
      mc mb --ignore-existing local/luminous-media &&
      mc anonymous set download local/luminous-media/public
      "

  mailpit:
    image: axllent/mailpit
    restart: unless-stopped
    ports: ["58025:8025", "51025:1025"]

volumes:
  pgdata:
  redisdata:
  meilidata:
  miniodata:
```

`docker/postgres/init/01-extensions.sql`:

```sql
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "btree_gin";
```

`docker/postgres/init/02-roles.sql` — **required for RLS to actually be enforced locally.** The
bootstrap user (`luminous`) is a superuser and bypasses row-level security entirely; the runtime
app must connect as a non-superuser role or the tenant-isolation tests pass without testing
anything (`06-testing-strategy.md` §2):

```sql
-- Runtime role: subject to RLS. This is what the app connects as.
CREATE ROLE luminous_app LOGIN PASSWORD 'secret' NOSUPERUSER NOCREATEDB NOCREATEROLE;
GRANT CONNECT ON DATABASE luminous TO luminous_app;
GRANT USAGE ON SCHEMA public TO luminous_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO luminous_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO luminous_app;

-- Migration / system-job role: bypasses RLS (05-security-multitenancy.md §1 layer 2).
CREATE ROLE luminous_migrator LOGIN PASSWORD 'secret' NOSUPERUSER BYPASSRLS;
GRANT CONNECT ON DATABASE luminous TO luminous_migrator;
GRANT ALL ON SCHEMA public TO luminous_migrator;
```

Laravel gets two connections: the default `pgsql` uses `luminous_migrator` **only** in
`config/database.php`'s `migrations` connection; runtime uses `luminous_app`. Tables are created by
the migrator, so `FORCE ROW LEVEL SECURITY` (which binds owners too) stays meaningful.

Start it:

```bash
docker compose -f docker/compose.dev.yml up -d
```

> **Important:** `--maxmemory-policy noeviction` on Redis. The default `allkeys-lru` will silently
> evict queued jobs under memory pressure, which means losing customer messages.

---

## 3. Cloudflare Tunnel — a **named** tunnel, not a quick tunnel

Meta and ioTec both require a **stable** public HTTPS URL:

| Consumer | Why the URL must be stable |
|---|---|
| Meta webhooks | Configured once per app in the Developer Portal; changing it means re-verifying |
| **ioTec callbacks** | Configured **per wallet in the ioTec portal UI**, not per API request. A changing URL means logging into the portal on every restart. |
| MBA connectors | Registered with Meta as the connector `base_url` |
| Embedded Signup | OAuth redirect must match a registered URL |

Quick tunnels (`cloudflared tunnel --url ...`) issue a **random** `*.trycloudflare.com` hostname on
every start. Do not use them. Create a named tunnel bound to a hostname on a domain in your
Cloudflare account.

The zone is `luminouscrm.com` (already on Cloudflare, `cloudflared` already logged in); the dev
hostname is **`platform.luminouscrm.com`**:

```bash
cloudflared tunnel create luminous-dev
cloudflared tunnel route dns luminous-dev platform.luminouscrm.com
```

`~/.cloudflared/luminous-dev.yml`:

```yaml
tunnel: <TUNNEL_UUID>
credentials-file: /Users/sirshadrack/.cloudflared/<TUNNEL_UUID>.json

ingress:
  - hostname: platform.luminouscrm.com
    service: https://luminous-agent.test
    originRequest:
      # Herd uses a locally-trusted CA that cloudflared does not know about
      noTLSVerify: true
      # Force the Host header Herd expects, or Herd will not match the site
      httpHostHeader: luminous-agent.test
      connectTimeout: 30s
  - service: http_status:404
```

Run it:

```bash
cloudflared tunnel run --config ~/.cloudflared/luminous-dev.yml luminous-dev
```

### Making Laravel aware it is behind a proxy

Because the app is reached through Cloudflare, Laravel must trust the forwarded headers or every
generated URL will be `http://luminous-agent.test` instead of the public HTTPS hostname.

In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO);
})
```

Set `APP_URL` to the **public tunnel hostname**. Everything that generates a callback URL
(`route()`, signed URLs, MBA connector base URL) then produces a reachable address.

Local browsing still works at `https://luminous-agent.test`; only URL generation uses `APP_URL`.

---

## 4. `.env` for local development

```dotenv
APP_NAME="Luminous Commerce"
APP_ENV=local
APP_KEY=                              # php artisan key:generate
APP_DEBUG=true
APP_URL=https://platform.luminouscrm.com   # the TUNNEL hostname, not .test
APP_LOCAL_URL=https://luminous-agent.test

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=55432
DB_DATABASE=luminous
DB_USERNAME=luminous_app              # runtime role — subject to RLS (docker/postgres/init/02-roles.sql)
DB_PASSWORD=secret
DB_MIGRATOR_USERNAME=luminous_migrator # BYPASSRLS role used by the `migrations` connection only
DB_MIGRATOR_PASSWORD=secret

REDIS_HOST=127.0.0.1
REDIS_PORT=56379
REDIS_CLIENT=phpredis

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
BROADCAST_CONNECTION=reverb          # live inbox updates

# ---- Reverb (runs as `php artisan reverb:start`, part of `composer dev`) ----
REVERB_APP_ID=luminous
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=localhost
REVERB_PORT=58085                     # 58080 is taken by the parity app container
REVERB_SCHEME=http
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:57700
MEILISEARCH_KEY=masterKeyChangeMe

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=luminous
AWS_SECRET_ACCESS_KEY=secretsecret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=luminous-media
AWS_ENDPOINT=http://127.0.0.1:59000
AWS_USE_PATH_STYLE_ENDPOINT=true

MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=51025

# ---- Meta ----
META_GRAPH_VERSION=v26.0
META_APP_ID=
META_APP_SECRET=
META_WEBHOOK_VERIFY_TOKEN=            # any long random string; must match the Developer Portal
META_ES_CONFIG_ID=                    # Embedded Signup configuration ID
META_SOLUTION_ID=                     # only if using a Multi-Partner Solution

# ---- Meta Business Agent ----
# Prefix only. The URL registered with Meta is per tenant:
# ${MBA_CONNECTOR_BASE_URL}/{tenant} — the {tenant} segment is mandatory and must match the
# connector token's tenant_id (05-security-multitenancy.md §3).
MBA_CONNECTOR_BASE_URL="${APP_URL}/connectors/v1"

# ---- ioTec Pay ----
IOTEC_TOKEN_URL=https://id.iotec.io/connect/token
IOTEC_BASE_URL=https://pay.iotec.io
IOTEC_CLIENT_ID=
IOTEC_CLIENT_SECRET=
IOTEC_WALLET_ID_UGX=
IOTEC_WALLET_ID_ITX=                  # sandbox wallet
IOTEC_CALLBACK_HEADER_NAME=X-Iotec-Signature
IOTEC_CALLBACK_HEADER_VALUE=          # long random string, also set in the ioTec portal
IOTEC_DEFAULT_CURRENCY=UGX
```

---

## 5. Day-to-day commands

```bash
docker compose -f docker/compose.dev.yml up -d
```

```bash
cloudflared tunnel run --config ~/.cloudflared/luminous-dev.yml luminous-dev
```

```bash
php artisan horizon
```

```bash
npm run dev
```

```bash
php artisan schedule:work
```

Suggested `composer dev` script to run app-side processes together:

```json
"dev": [
  "Composer\\Config::disableProcessTimeout",
  "npx concurrently -c '#93c5fd,#fb7185,#fdba74,#a7f3d0' \"php artisan horizon\" \"php artisan schedule:work\" \"php artisan reverb:start\" \"npm run dev\" --names=horizon,schedule,reverb,vite"
]
```

---

## 6. Production-parity check with Docker

Separate from dev. Used to verify the app works containerised before deploying — this is the
"does it work in production" harness, run on demand, not all day.

`docker/compose.parity.yml` includes the dev services (so `postgres`/`redis` exist for
`depends_on`) and adds app containers. The shared environment lives in a top-level `x-app-env`
anchor so the worker and scheduler reuse it:

```yaml
name: luminous-parity

include:
  - compose.dev.yml

x-app-env: &app-env
  APP_ENV: production
  APP_DEBUG: "false"
  DB_HOST: postgres
  DB_USERNAME: luminous_app
  REDIS_HOST: redis
  MEILISEARCH_HOST: http://meilisearch:7700
  AWS_ENDPOINT: http://minio:9000
  OCTANE_SERVER: frankenphp

services:
  app:
    build:
      context: ..
      dockerfile: docker/app/Dockerfile
    environment: *app-env
    ports: ["58080:8000"]
    depends_on:
      postgres: {condition: service_healthy}
      redis: {condition: service_healthy}

  worker:
    build: {context: .., dockerfile: docker/app/Dockerfile}
    command: ["php", "artisan", "horizon"]
    environment: *app-env
    depends_on: [app]

  scheduler:
    build: {context: .., dockerfile: docker/app/Dockerfile}
    command: ["php", "artisan", "schedule:work"]
    environment: *app-env
    depends_on: [app]
```

`docker/app/Dockerfile` — multi-stage: Node builds assets, then `dunglas/frankenphp` with PHP 8.4,
`pdo_pgsql`, `redis`, `intl`, `bcmath`, `gd`/`imagick`, opcache tuned, running Laravel Octane.

Run it, then point the tunnel at `http://localhost:58080` instead of the Herd URL to test webhooks
against the containerised app.

**Parity checklist before any deploy:**

| Check | Why |
|---|---|
| Migrations run clean from empty DB | Catches ordering bugs |
| `config:cache`, `route:cache`, `view:cache`, `event:cache` all succeed | Closures in config break production |
| Octane boots and survives 1k requests | Catches state leaking between requests (a real risk with tenancy) |
| Horizon processes all 8 queues | Missing queue config = silently stuck jobs |
| Webhook round-trip works through the tunnel to the container | The whole point |
| Media upload lands in MinIO and is retrievable | Disk config differences |

> Octane + multi-tenancy is the highest-risk pairing in this stack. Any tenant state held in a
> singleton leaks across requests. `06-testing-strategy.md` covers the test that catches this.

---

## 7. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Tunnel returns 502 | Herd cert not trusted by cloudflared | `noTLSVerify: true` in `originRequest` |
| Tunnel returns Herd's default page | Host header not forwarded | set `httpHostHeader: luminous-agent.test` |
| Meta webhook verification fails | `hub.verify_token` mismatch, or route is behind `web` middleware (CSRF) | Register webhook routes outside the `web` group |
| Signature verification always fails | Reading the parsed body instead of the raw body | Use `$request->getContent()` before any JSON decoding |
| ioTec callbacks stop arriving | Tunnel hostname changed | Use a named tunnel; re-check the wallet's Callback URLs card in the ioTec portal |
| Jobs vanish under load | Redis evicting keys | `maxmemory-policy noeviction` |
| `pgsql` driver missing | Herd PHP lacks the extension | Enable `pdo_pgsql` in Herd's PHP settings |
