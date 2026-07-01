# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a custom PHP REST API backend — no framework (not Laravel/Symfony). It uses a lightweight in-house router, Pixie query builder for MySQL, and Razorpay for subscription billing. The app serves podcast chart data across Apple, Spotify, and YouTube platforms.

## Running the Project

Serve the `backend/` directory with Apache or Nginx + PHP. `index.php` is the entry point. No build step or CLI commands.

```
# Enable debug output
GET /any-route?debug=1
```

Configuration is in `config.php` (hardcoded constants — no `.env` file). Change DB credentials, JWT secret, encryption key, and Razorpay keys there.

Install PHP dependencies:
```bash
composer install
```

## Architecture

### Request Lifecycle

```
index.php → config.php → bootstrap.php → routes.php → Router → Controller::__construct() [middleware] → action method
```

1. `index.php` — Sets CORS headers, loads config/bootstrap/routes
2. `bootstrap.php` — Initializes Pixie DB connection with global alias `QB`; loads `Inc/utilities.php`
3. `routes.php` — Registers all routes on `\Library\Router`
4. Each controller's `__construct()` calls `$this->middleware([...])` — auth runs here, before the action

### Key Directories

- `App/Controllers/` — All request handlers; every controller extends `Controller`
- `App/Models/` — DB access via `\QB::table(...)` (Pixie); no ORM, no migrations
- `App/Enums/` — Constants classes (platform names, table names, HTTP status codes)
- `App/Traits/` — `ToolsTrait` (sendJson, payload parsing, validation), `ThrottleTrait` (Redis rate limiting)
- `Library/` — Framework internals: `Router`, `Input`, `Jwt`, `Enum`, `Security`
- `Inc/utilities.php` — Global helpers: `encrypt()`, `decrypt()`, `jsonEncode()`, `get_client_ip()`, etc.

### Database Access

All models use the global `QB` alias (Pixie query builder):
```php
\QB::table('users')->where('id', $id)->first();
\QB::table('users')->insert($data);
```

Tables: `users`, `subscriptions`, `webhook_events`, `apple_main`, `spotify_main`, `youtube_main`, `genres`, `countries`, `history`

No migration system — schema is managed manually.

### Authentication

- JWT tokens issued on login/register, stored as `mp_token` HTTP-only cookie and accepted via `Authorization: Bearer` header
- `Controller::verifyAuthToken()` validates the token and sets `$this->auto_id` (int) and `$this->email_id`
- User IDs in API responses are obfuscated with `encrypt()` / `decrypt()` helpers (XOR + base64); raw integer IDs never leave the server
- Google OAuth via `google/apiclient` — verifies Google ID token, auto-links to existing email account or creates a new one

Per-controller auth with exclusions:
```php
$this->middleware([
    $this->auth_user_key => [
        'class'  => __CLASS__,
        'except' => ['login', 'register'],  // skip auth for these methods
    ],
]);
// Auth sets auto_id/email_id on the middleware object, so propagate it:
$this->auto_id  = $this->mw[$this->auth_user_key]->auto_id  ?? null;
$this->email_id = $this->mw[$this->auth_user_key]->email_id ?? null;
```

### Controller Patterns

Every action must call `$this->sendJson()` (which echoes JSON and `die`s — no return):

```php
$this->sendJson(ResponseStatusEnum::SUCCESS, "", ['key' => $data]);
$this->sendJson(ResponseStatusEnum::UNAUTHORIZED);           // halts immediately
$this->sendJson(ResponseStatusEnum::BAD_REQUEST, "msg");
```

Input is accessed via `$this->payload` (merged GET/POST/JSON body, pre-trimmed). Validate with:
```php
$this->validateInput(['field' => 'required|email|max:100']);
```

Pagination:
```php
$this->setPagination();   // reads page + limit from payload
$this->paginate($total, $results);  // or
$this->getPagination($total, $results);  // returns array for manual merging
```

### Charts Domain

`ChartsEnum` is the single source of truth for platform names, table names, and chart slugs. Always use it rather than hardcoding strings.

Key subtlety: Spotify stores `top` chart as `top-podcasts` in the DB. `ChartsEnum::resolveChart($platform, $chart)` handles this translation before any DB query.

`ChartsModel::getCharts()` enriches results with cross-platform presence flags (`on_apple`, `on_spotify`, `on_youtube`, `apple_url`, `spotify_id`, `youtube_url`) by joining on the `match_key` column across all three platform tables.

### Billing (Razorpay)

- `POST /billing/checkout` creates a Razorpay subscription with a 14-day trial, stores it in `subscriptions` with status `created`
- `POST /billing/webhook` receives Razorpay events, verifies signature, deduplicates via `webhook_events` table, then updates `subscriptions` and `users.plan_status`
- Plan IDs are constants in `config.php`: `RAZORPAY_PLAN_TIER1_MONTHLY`, etc.
- `BillingController::webhook` is excluded from auth (called by Razorpay, not users)

## Dependencies

| Package | Purpose |
|---|---|
| `usmanhalalit/pixie` | Query builder — global `QB` alias |
| `rakit/validation` | Input validation rules |
| `guzzlehttp/guzzle` | HTTP client |
| `google/apiclient` | Google OAuth token verification |
| `razorpay/razorpay` | Subscription billing |


