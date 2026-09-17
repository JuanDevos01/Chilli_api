# Chilli API

Backend for Chilli — a mobile app for couples that surfaces mutual intimate preferences via a private questionnaire, revealing only yes/yes matches without ever exposing individual answers.

**Stack:** Laravel 13.8 · PHP 8.3+ · SQLite · Laravel Sanctum · `spatie/laravel-event-sourcing` v7

Architected with Event Sourcing — see [`docs/POC_EVENT_SOURCING.md`](docs/POC_EVENT_SOURCING.md) for the full write-up.

## Requirements

- PHP 8.3 or newer (`php --version`)
- Composer (`composer --version`)
- SQLite (bundled with PHP)

## First-time setup

```bash
git clone <repo-url> chilli-api
cd chilli-api
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
```

`php artisan migrate --seed` creates the schema and inserts the seed question catalog.

## Run the dev server

**For local browser/CLI testing only:**

```bash
php artisan serve
```

Server runs on `http://127.0.0.1:8000`.

**To reach the API from the Chilli mobile client** (phone on the same wifi), bind to all interfaces:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Find your Mac's LAN IP with `ipconfig getifaddr en0` and confirm the API responds from another device:

```bash
curl http://<your-lan-ip>:8000/api/questionnaire
```

If curl works from your Mac but not from the phone, macOS Firewall is likely blocking PHP — allow it in *System Settings → Network → Firewall*.

## Run the test suite

```bash
php artisan test
```

Should report **25 tests / 145 assertions green** in under 500 ms.

Filter by feature:

```bash
php artisan test --filter=ReplayProjections
php artisan test --filter=RetractAnswer
php artisan test --filter=MutualMatchGoldenPath
```

## Event Sourcing operations

Reset all read-model tables and reconstruct them from `stored_events`:

```bash
php artisan event-sourcing:replay
```

Inspect registered projectors and reactors:

```bash
php artisan event-sourcing:list
```

## API surface (PoC)

| Method | Route | Description |
|---|---|---|
| POST | `/api/auth/register` | Register with name/email/password. Returns Sanctum token. |
| POST | `/api/auth/login` | Log in with email/password. Returns Sanctum token. |
| POST | `/api/auth/logout` | Revoke the current token. |
| GET | `/api/auth/me` | Authenticated user profile. |
| POST | `/api/couples/invitations` | Create a couple invitation code. |
| POST | `/api/couples/invitations/{code}/accept` | Accept an invitation and link the couple. |
| GET | `/api/questionnaire` | Fetch the question catalog. |
| POST | `/api/questionnaire/answers` | Submit an answer (encrypted at rest). |
| DELETE | `/api/questionnaire/answers/{questionUuid}` | Retract a previously submitted answer. |
| GET | `/api/matches` | Reveal yes/yes matches for the current couple. |

## Useful tail commands

Live server logs:

```bash
tail -f storage/logs/laravel.log
```

## Related docs

- [`docs/POC_EVENT_SOURCING.md`](docs/POC_EVENT_SOURCING.md) — architecture, bounded contexts, replay pattern, learnings.
