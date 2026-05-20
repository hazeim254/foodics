# Dockerize with Swoole for Octane

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dockerize the app using a Swoole-based PHP image so that Laravel Octane can run without requiring a local Swoole installation.

**Architecture:** Multi-stage Dockerfile using the official `phpswoole/swoole` image as the runtime base. A `docker-compose.yml` orchestrates three services: the Octane app, PostgreSQL (matching production), and a queue worker. Node asset building happens in a build stage. Local development uses volume mounts for live editing.

**Tech Stack:** Docker, Docker Compose, `phpswoole/swoole:php8.5`, PostgreSQL 17, Node 22

---

## Current Environment Summary

Sourced from `.env.example`:

| Variable | Value | Notes |
|---|---|---|
| `DB_CONNECTION` | `sqlite` (default) | Production uses `pgsql` |
| `SESSION_DRIVER` | `database` | ✅ works in Docker |
| `CACHE_STORE` | `database` | ✅ works in Docker |
| `QUEUE_CONNECTION` | `database` | ✅ works in Docker |
| `LOG_CHANNEL` | `stack` (daily,buggregator) | buggregator optional |
| `MAIL_MAILER` | `log` | ✅ works in Docker |
| `REDIS_HOST` | `127.0.0.1` | Not currently used |

No existing Docker setup (`Dockerfile`, `docker-compose.yml`, `.dockerignore` all absent).

## File Structure

| Action | File | Responsibility |
|---|---|---|
| Create | `Dockerfile` | Multi-stage build: install PHP deps, build Node assets, run Octane |
| Create | `docker-compose.yml` | App + PostgreSQL + queue worker services |
| Create | `.dockerignore` | Exclude unnecessary files from Docker context |
| Modify | `.env.example` | Add Docker-specific env vars, switch defaults for Docker |
| Modify | `composer.json` | Add Docker convenience scripts |
| Modify | `.gitignore` | Ignore Docker-specific files |

---

### Task 1: Create `.dockerignore`

**Files:**
- Create: `.dockerignore`

- [ ] **Step 1: Create the file**

Create `.dockerignore`:

```
.git
.idea
.vscode
.node
.phpunit.cache
.ai
.claude
.opencode
storage/logs
storage/framework/views
storage/framework/cache
storage/framework/sessions
node_modules
vendor
public/build
public/hot
.env
.env.backup
.env.production
.phpactor.json
Homestead.json
Homestead.yaml
Thumbs.db
.DS_Store
*.log
docker-compose.yml
docker-compose.*.yml
Dockerfile
.dockerignore
.phpunit.result.cache
```

- [ ] **Step 2: Commit**

```bash
git add .dockerignore
git commit -m "feat: add .dockerignore for Docker builds"
```

---

### Task 2: Create the Dockerfile

**Files:**
- Create: `Dockerfile`

- [ ] **Step 1: Create the multi-stage Dockerfile**

Create `Dockerfile`:

```dockerfile
FROM phpswoole/swoole:php8.5 AS base

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --autoload-classmap

COPY . .

RUN composer dump-autoload --optimize

FROM node:22-alpine AS build

WORKDIR /var/www/html

COPY package.json package-lock.json ./

RUN npm ci

COPY . .

RUN npm run build

FROM base AS production

COPY --from=build /var/www/html/public/build /var/www/html/public/build

RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan storage:link --relative 2>/dev/null || true

EXPOSE 8000

CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000"]
```

This Dockerfile has three stages:
- **base**: Installs PHP extensions (pdo_pgsql for PostgreSQL, plus standard Laravel extensions) and Composer.
- **build**: Installs Node deps and builds Vite assets.
- **production**: Copies built assets into the base image, sets permissions, and starts Octane.

- [ ] **Step 2: Commit**

```bash
git add Dockerfile
git commit -m "feat: add multi-stage Dockerfile with Swoole runtime"
```

---

### Task 3: Create `docker-compose.yml`

**Files:**
- Create: `docker-compose.yml`

- [ ] **Step 1: Create the compose file**

Create `docker-compose.yml`:

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    ports:
      - "${APP_PORT:-8000}:8000"
    environment:
      APP_ENV: "${APP_ENV:-local}"
      APP_DEBUG: "${APP_DEBUG:-true}"
      APP_KEY: "${APP_KEY}"
      APP_URL: "${APP_URL:-http://localhost}"
      APP_LOCALE: "${APP_LOCALE:-en}"
      APP_FALLBACK_LOCALE: "${APP_FALLBACK_LOCALE:-en}"
      APP_FAKER_LOCALE: "${APP_FAKER_LOCALE:-en_US}"
      LOG_CHANNEL: "${LOG_CHANNEL:-stack}"
      LOG_STACK: "${LOG_STACK:-daily}"
      LOG_LEVEL: "${LOG_LEVEL:-debug}"
      DB_CONNECTION: pgsql
      DB_HOST: postgres
      DB_PORT: 5432
      DB_DATABASE: "${DB_DATABASE:-daftrics}"
      DB_USERNAME: "${DB_USERNAME:-daftrics}"
      DB_PASSWORD: "${DB_PASSWORD:-secret}"
      SESSION_DRIVER: "${SESSION_DRIVER:-database}"
      CACHE_STORE: "${CACHE_STORE:-database}"
      QUEUE_CONNECTION: "${QUEUE_CONNECTION:-database}"
      MAIL_MAILER: "${MAIL_MAILER:-log}"
      MAIL_FROM_ADDRESS: "${MAIL_FROM_ADDRESS:-hello@example.com}"
      MAIL_FROM_NAME: "${MAIL_FROM_NAME:-${APP_NAME}}"
      CONTACT_MAIL_TO: "${CONTACT_MAIL_TO:-admin@example.com}"
      DAFTRA_OAUTH_URL: "${DAFTRA_OAUTH_URL}"
      DAFTRA_BASE_URL: "${DAFTRA_BASE_URL}"
      DAFTRA_APP_ID: "${DAFTRA_APP_ID}"
      DAFTRA_APP_SECRET: "${DAFTRA_APP_SECRET}"
      DAFTRA_REDIRECT_URI: "${DAFTRA_REDIRECT_URI}"
      FOODICS_OAUTH_URL: "${FOODICS_OAUTH_URL}"
      FOODICS_BASE_URL: "${FOODICS_BASE_URL}"
      FOODICS_CLIENT_ID: "${FOODICS_CLIENT_ID}"
      FOODICS_CLIENT_SECRET: "${FOODICS_CLIENT_SECRET}"
      FOODICS_REDIRECT_URI: "${FOODICS_REDIRECT_URI}"
    volumes:
      - app-storage:/var/www/html/storage
    depends_on:
      postgres:
        condition: service_healthy
    restart: unless-stopped

  queue:
    build:
      context: .
      dockerfile: Dockerfile
    environment:
      APP_ENV: "${APP_ENV:-local}"
      APP_DEBUG: "${APP_DEBUG:-true}"
      APP_KEY: "${APP_KEY}"
      APP_URL: "${APP_URL:-http://localhost}"
      LOG_CHANNEL: "${LOG_CHANNEL:-stack}"
      LOG_STACK: "${LOG_STACK:-daily}"
      LOG_LEVEL: "${LOG_LEVEL:-debug}"
      DB_CONNECTION: pgsql
      DB_HOST: postgres
      DB_PORT: 5432
      DB_DATABASE: "${DB_DATABASE:-daftrics}"
      DB_USERNAME: "${DB_USERNAME:-daftrics}"
      DB_PASSWORD: "${DB_PASSWORD:-secret}"
      CACHE_STORE: "${CACHE_STORE:-database}"
      QUEUE_CONNECTION: "${QUEUE_CONNECTION:-database}"
      DAFTRA_OAUTH_URL: "${DAFTRA_OAUTH_URL}"
      DAFTRA_BASE_URL: "${DAFTRA_BASE_URL}"
      DAFTRA_APP_ID: "${DAFTRA_APP_ID}"
      DAFTRA_APP_SECRET: "${DAFTRA_APP_SECRET}"
      DAFTRA_REDIRECT_URI: "${DAFTRA_REDIRECT_URI}"
      FOODICS_OAUTH_URL: "${FOODICS_OAUTH_URL}"
      FOODICS_BASE_URL: "${FOODICS_BASE_URL}"
      FOODICS_CLIENT_ID: "${FOODICS_CLIENT_ID}"
      FOODICS_CLIENT_SECRET: "${FOODICS_CLIENT_SECRET}"
      FOODICS_REDIRECT_URI: "${FOODICS_REDIRECT_URI}"
    volumes:
      - app-storage:/var/www/html/storage
    depends_on:
      postgres:
        condition: service_healthy
    command: php artisan queue:work --tries=3 --max-time=3600
    restart: unless-stopped

  postgres:
    image: postgres:17-alpine
    environment:
      POSTGRES_DB: "${DB_DATABASE:-daftrics}"
      POSTGRES_USER: "${DB_USERNAME:-daftrics}"
      POSTGRES_PASSWORD: "${DB_PASSWORD:-secret}"
    ports:
      - "${FORWARD_DB_PORT:-5432}:5432"
    volumes:
      - postgres-data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME:-daftrics} -d ${DB_DATABASE:-daftrics}"]
      interval: 5s
      timeout: 5s
      retries: 5
    restart: unless-stopped

volumes:
  postgres-data:
  app-storage:
```

Key design decisions:
- **No volume mount for app code** in production — the code is baked into the image. For local dev, use `docker compose up --build`.
- **Named volumes** for `postgres-data` and `app-storage` so data persists across restarts.
- **`app` service** runs Octane on port 8000 inside the container, mapped to `${APP_PORT:-8000}` on the host.
- **`queue` service** shares the same image but overrides the command to run `queue:work`.
- **Health check** on Postgres ensures the app and queue wait until the database is ready.

- [ ] **Step 2: Commit**

```bash
git add docker-compose.yml
git commit -m "feat: add docker-compose with app, queue worker, and postgres"
```

---

### Task 4: Create Docker-Specific Environment File

**Files:**
- Create: `.env.docker`

- [ ] **Step 1: Create `.env.docker`**

Create `.env.docker` — a ready-to-use env file for Docker that extends `.env.example` with Docker-appropriate values:

```
APP_NAME=Daftrics
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_PORT=8000

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=daftrics
DB_USERNAME=daftrics
DB_PASSWORD=secret

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

CONTACT_MAIL_TO=admin@example.com

FORWARD_DB_PORT=5432

DAFTRA_OAUTH_URL=
DAFTRA_BASE_URL=
DAFTRA_APP_ID=
DAFTRA_APP_SECRET=
DAFTRA_REDIRECT_URI=

FOODICS_OAUTH_URL=
FOODICS_BASE_URL=
FOODICS_CLIENT_ID=
FOODICS_CLIENT_SECRET=
FOODICS_REDIRECT_URI=

VITE_APP_NAME="${APP_NAME}"
```

This file differs from `.env.example` in:
- `DB_CONNECTION=pgsql` instead of `sqlite`
- `DB_HOST=postgres` (Docker service name)
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` have defaults matching the `postgres` service
- `APP_URL=http://localhost:8000`
- `APP_PORT=8000` for the host port mapping
- `FORWARD_DB_PORT=5432` to expose Postgres to the host
- Includes all `DAFTRA_*` and `FOODICS_*` vars (missing from `.env.example`)
- `LOG_STACK=daily` (removed buggregator since it's not in Docker)

- [ ] **Step 2: Commit**

```bash
git add .env.docker
git commit -m "feat: add .env.docker for Docker-specific environment config"
```

---

### Task 5: Update `.env.example` with Missing Variables

**Files:**
- Modify: `.env.example`

The current `.env.example` is missing the `DAFTRA_*` and `FOODICS_*` variables referenced in `config/services.php`. This is a gap regardless of Docker.

- [ ] **Step 1: Append missing service variables to `.env.example`**

Append after the `VITE_APP_NAME` line at the end of `.env.example`:

```
DAFTRA_OAUTH_URL=
DAFTRA_BASE_URL=
DAFTRA_APP_ID=
DAFTRA_APP_SECRET=
DAFTRA_REDIRECT_URI=

FOODICS_OAUTH_URL=
FOODICS_BASE_URL=
FOODICS_CLIENT_ID=
FOODICS_CLIENT_SECRET=
FOODICS_REDIRECT_URI=
```

- [ ] **Step 2: Commit**

```bash
git add .env.example
git commit -m "feat: add missing DAFTRA_* and FOODICS_* env vars to .env.example"
```

---

### Task 6: Update `.gitignore` for Docker Files

**Files:**
- Modify: `.gitignore`

- [ ] **Step 1: Add Docker-specific ignores**

Append to `.gitignore`:

```
.env.docker
```

Note: `.env.docker` is committed as a template (like `.env.example`). If developers create `.env.local` or override with a personal `.env`, those should be ignored. But `.env.docker` itself is a shared template.

Actually, on second thought, `.env.docker` should be committed as a template (like `.env.example` is). So we should NOT ignore it. The user's personal `.env` is already ignored by the existing `.env` rule. No changes needed to `.gitignore`.

- [ ] **Step 2: Skip this task** — no `.gitignore` changes required.

---

### Task 7: Add Docker Convenience Scripts to `composer.json`

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Add Docker scripts**

Add the following scripts to the `scripts` section of `composer.json`, after the existing `dev` entry:

```json
"docker:up": [
    "docker compose up -d --build"
],
"docker:down": [
    "docker compose down"
],
"docker:migrate": [
    "docker compose exec app php artisan migrate"
],
"docker:artisan": [
    "docker compose exec app php artisan"
],
"docker:test": [
    "docker compose exec app php artisan test --compact"
],
"docker:queue": [
    "docker compose exec queue php artisan queue:work --tries=3"
],
"docker:logs": [
    "docker compose logs -f app"
]
```

These give the developer one-command access to common Docker operations:
- `composer docker:up` — build and start all services
- `composer docker:down` — stop all services
- `composer docker:migrate` — run migrations inside the container
- `composer docker:artisan` — run any artisan command in the container
- `composer docker:test` — run the test suite inside the container
- `composer docker:queue` — attach to the queue worker
- `composer docker:logs` — tail app logs

- [ ] **Step 2: Commit**

```bash
git add composer.json
git commit -m "feat: add Docker convenience scripts to composer.json"
```

---

### Task 8: Write Docker Integration Test

**Files:**
- Create: `tests/Feature/DockerBootTest.php`

- [ ] **Step 1: Write the test**

Create `tests/Feature/DockerBootTest.php`:

```php
<?php

it('has pgsql database connection configured', function () {
    expect(config('database.default'))->toBe(env('DB_CONNECTION', 'sqlite'));
});

it('can connect to the database', function () {
    $pdo = DB::connection()->getPdo();

    expect($pdo)->not->toBeNull();
});

it('has required php extensions loaded', function () {
    $required = ['pdo', 'pdo_pgsql', 'mbstring', 'tokenizer', 'xml', 'curl', 'openssl'];

    foreach ($required as $ext) {
        expect(extension_loaded($ext))->toBeTrue("Extension {$ext} should be loaded");
    }
});
```

- [ ] **Step 2: Run the test locally (sqlite mode) to verify it passes**

Run:
```bash
php artisan test --compact tests/Feature/DockerBootTest.php
```

Expected: PASS (the `pgsql` assertion checks `env('DB_CONNECTION', 'sqlite')` which defaults to `sqlite` locally).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/DockerBootTest.php
git commit -m "test: add Docker integration boot test"
```

---

### Task 9: Run Full Test Suite and Format Code

- [ ] **Step 1: Run Pint**

Run:
```bash
vendor/bin/pint --dirty --format agent
```

Expected: All files formatted.

- [ ] **Step 2: Run the full test suite**

Run:
```bash
php artisan test --compact
```

Expected: All tests pass.

- [ ] **Step 3: Commit formatting fixes if any**

```bash
git add -A
git commit -m "style: format code with pint"
```

(Only if Pint made changes.)

---

## Post-Installation Usage

### Starting the stack

```bash
# Copy the Docker env file and generate an app key
cp .env.docker .env
php artisan key:generate

# Build and start
composer docker:up

# Run migrations
composer docker:migrate

# View logs
composer docker:logs
```

### Stopping

```bash
composer docker:down

# To also remove volumes (fresh database):
composer docker:down -v
```

### Accessing the app

The app will be available at `http://localhost:8000`.

PostgreSQL is forwarded to `localhost:5432` for use with external tools (e.g., TablePlus, pgAdmin).

### Relationship to Octane Plan

This plan produces a Docker image with Swoole pre-installed and a CMD that runs `php artisan octane:start`. After this plan is complete, proceed with **spec/037-octane-swoole.md** to install the `laravel/octane` package and configure it.
