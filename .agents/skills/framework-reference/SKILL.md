---
name: Charts Backend PHP MVC Framework Reference
description: Complete reference for the custom PHP MVC framework used in the Charts backend project. Covers architecture, routing, controllers, models, enums, traits, library classes, utility functions, query builder (Pixie), and coding conventions. Consult this before writing any PHP code in this project.
---

# Charts Backend — Custom PHP MVC Framework Reference

## 1. Architecture Overview

```
index.php                  → Entry point (CORS, debug mode)
  └─ config.php            → Constants (DB, paths, app settings)
  └─ bootstrap.php         → Timezone, autoload, DB connection (Pixie)
  └─ routes.php            → All route definitions via Library\Router
```

### Directory Structure
```
backend/
├── App/
│   ├── Controllers/       → Controller classes (extend Controller base)
│   ├── Models/             → Model classes (use Pixie query builder)
│   ├── Enums/              → Enum classes (extend Library\Enum)
│   └── Traits/             → Reusable trait modules
├── Inc/
│   └── utilities.php       → Global helper functions
├── Library/
│   ├── Router.php          → HTTP router (bramus-style)
│   ├── Input.php           → Request input handling (CI-style)
│   ├── Security.php        → XSS cleaning (CI-style)
│   ├── Jwt.php             → JWT generate/validate
│   ├── Red.php             → Redis wrapper (static)
│   └── Enum.php            → Abstract enum base class
├── config.php              → Define constants
├── bootstrap.php           → Boot: autoloader, DB, utilities
├── autoload.php            → PSR-0 style class autoloading
├── routes.php              → Route definitions
├── index.php               → Front controller
└── composer.json           → Dependencies
```

### Namespace Convention
- `Library\*` → Framework core classes
- `App\Controllers\*` → Application controllers
- `App\Models\*` → Database model classes
- `App\Enums\*` → Enum constants
- `App\Traits\*` → Shared trait logic

### Autoloading
PSR-0 style: `spl_autoload_register` maps `Namespace\ClassName` → `Namespace/ClassName.php` relative to `DOCUMENT_ROOT`. Composer autoload is also loaded for vendor packages.

---

## 2. Configuration Constants (`config.php`)

| Constant | Purpose | Example |
|---|---|---|
| `DOCUMENT_ROOT` | Project root path with trailing separator | `__DIR__ . DIRECTORY_SEPARATOR` |
| `DB_HOST` | MySQL host | `'localhost'` |
| `DB_NAME` | Database name | `'charts'` |
| `DB_PORT` | MySQL port | `3306` |
| `DB_USER` | MySQL user | `'root'` |
| `DB_PASS` | MySQL password | `''` |
| `DEFAULT_TIME_ZONE` | PHP timezone | `'UTC'` |
| `ROUTER_BASE_PATH` | Base URL path for routing | `'/'` |
| `MP_TOKEN` | Default/fallback API token | `''` |
| `ACTIVE_STATUS` | Active record flag | `1` |
| `ENCRYPTION_KEY` | AES encryption key (32 chars) | `'change_me_32_chars_secret_key!!!'` |
| `PROXY_IPS` | Trusted proxy IPs for IP detection | `''` |

---

## 3. Routing (`Library\Router`)

### Route Registration
```php
$router = new \Library\Router();
$router->setBasePath(ROUTER_BASE_PATH);
$router->setNamespace("\App\Controllers");

// HTTP method shortcuts:
$router->get('/path', 'ControllerName@methodName');
$router->post('/path', 'ControllerName@methodName');
$router->put('/path', 'ControllerName@methodName');
$router->patch('/path', 'ControllerName@methodName');
$router->delete('/path', 'ControllerName@methodName');
$router->options('/path', $callback);

// Match multiple methods:
$router->match('GET|POST', '/path', $callback);

// All methods:
$router->all('/path', $callback);

// Route parameters (Laravel-style curly braces):
$router->get('/items/{id}', 'ItemController@show');

// Inline closure:
$router->get('/status', function() {
    echo json_encode(["status" => 200]);
    exit;
});

// Route groups (mounting):
$router->mount('/charts', function() use ($router) {
    $router->get('/', 'ChartsController@getCharts');
    $router->get('/filters', 'ChartsController@getFilters');
});

// Before middleware:
$router->before('GET|POST', '/pattern', function($param) {
    // runs before matching routes
});

// 404 handler:
$router->set404(function() { /* ... */ });

// Execute routing:
$router->run();
```

### How Controller Invocation Works
When a route string like `'ControllerName@methodName'` is matched:
1. Router prepends the namespace → `\App\Controllers\ControllerName`
2. Instantiates: `new ControllerName(['__controller' => $controller, '__method' => $method])`
3. Calls: `$instance->methodName(...$urlParams)`

Route parameters from `{param}` placeholders are passed as arguments to the method.

---

## 4. Base Controller (`App\Controllers\Controller`)

All controllers **MUST** extend this class.

### Properties Available in Every Controller

| Property | Type | Description |
|---|---|---|
| `$this->payload` | `array` | Parsed & trimmed request data (GET or POST based on method) |
| `$this->input` | `Library\Input` | Raw input accessor |
| `$this->client` | `GuzzleHttp\Client` | HTTP client for external API calls |
| `$this->validator` | `Rakit\Validation\Validator` | Input validation |
| `$this->user_token` | `string` | Authorization header value (fallback: `MP_TOKEN`) |
| `$this->content_type` | `string` | Content-Type header value |
| `$this->headers` | `array` | Reconstructed headers array for outgoing requests |
| `$this->router_controller` | `string` | Current controller FQCN |
| `$this->router_method` | `string` | Current method name |
| `$this->pagination_limit` | `int\|null` | Set after `setPagination()` |
| `$this->pagination_offset` | `int\|null` | Set after `setPagination()` |

### Key Methods

#### `sendJson(int $status, string $msg = "", array $data = []): void`
Standard JSON response. **Terminates execution** (`die`). Sets HTTP status code and Content-Type header.
```php
// Success with data:
$this->sendJson(ResponseStatusEnum::SUCCESS, "", $data);

// Error:
$this->sendJson(ResponseStatusEnum::BAD_REQUEST, "Field X is invalid");

// No-data success:
$this->sendJson(ResponseStatusEnum::SUCCESS);
```
Response format:
```json
{ "status": 200, "msg": "Success!", "data": { ... } }
{ "status": 400, "msg": "Bad request! - custom msg" }
```

#### `sendRawJson(array $data): void`
Sends raw JSON without the standard wrapper. Data must include a `status` key. Also terminates execution.

#### `validateInput(array $rules, array $payload = []): true`
Uses Rakit Validator. Auto-sends 400 response on failure (calls `sendJson` + `die`).
```php
$this->validateInput([
    'email'    => 'required|email',
    'name'     => 'required|min:3',
    'page'     => 'numeric',
]);
// If validation passes, execution continues.
// If it fails, sendJson(BAD_REQUEST, "error messages") is called + die.
```

#### `setPagination(): void`
Reads `page` and `limit` from `$this->payload`, sets `$this->pagination_limit` and `$this->pagination_offset`.
```php
$this->setPagination();
$results = QB::table('items')
    ->limit($this->pagination_limit)
    ->offset($this->pagination_offset)
    ->get();
```

#### `getPagination(int $total, array $results): array`
Returns a pagination metadata array.
```php
$data = $this->getPagination($total, $results);
// Returns: ["results" => [...], "total" => 100, "per_page" => 10, "current_page" => 1, "last_page" => 10]
```

#### `paginate(int $total, array $results): void`
Shortcut: calls `sendJson(SUCCESS, "", getPagination($total, $results))`.

#### `middleware(array $middlewares): void`
Register middleware to run before controller logic.
```php
$this->middleware([
    '\App\Controllers\Controller:verifyAuthToken' => [
        'class'  => get_class($this),
        'except' => ['login', 'register'],
    ],
]);
```

#### `getPayloads(string $method): array`
Gets trimmed request input. POST data for POST requests, GET params otherwise. Called automatically in constructor.

---

## 5. Database — Pixie Query Builder

The project uses **Pixie** (`usmanhalalit/pixie`) as its query builder. Connection is established in `bootstrap.php` with alias `QB`.

### Usage
```php
// SELECT all
$rows = QB::table('users')->get();

// SELECT with WHERE
$user = QB::table('users')->where('id', 15)->first();

// SELECT specific columns
$rows = QB::table('users')->select(['id', 'name', 'email'])->get();

// WHERE with operators
$rows = QB::table('users')->where('age', '>', 18)->get();

// Multiple WHERE
$rows = QB::table('users')
    ->where('status', 1)
    ->where('role', 'admin')
    ->get();

// WHERE IN
$rows = QB::table('users')->whereIn('id', [1, 2, 3])->get();

// INSERT
$id = QB::table('users')->insert([
    'name'  => 'John',
    'email' => 'john@example.com'
]);

// INSERT (get insert ID)
$insertId = QB::table('users')->insertGetId([
    'name'  => 'John',
    'email' => 'john@example.com'
]);

// UPDATE
QB::table('users')->where('id', 15)->update(['name' => 'Jane']);

// DELETE
QB::table('users')->where('id', 15)->delete();

// COUNT
$count = QB::table('users')->where('status', 1)->count();

// JOIN
$rows = QB::table('orders')
    ->join('users', 'orders.user_id', '=', 'users.id')
    ->select(['orders.*', 'users.name'])
    ->get();

// ORDER BY
$rows = QB::table('users')->orderBy('created_at', 'DESC')->get();

// LIMIT / OFFSET
$rows = QB::table('users')->limit(10)->offset(20)->get();

// RAW query
$rows = QB::query("SELECT * FROM users WHERE status = ?", [1])->get();

// GROUP BY
$rows = QB::table('orders')
    ->select(QB::raw('user_id, COUNT(*) as total'))
    ->groupBy('user_id')
    ->get();

// Aggregate
$total = QB::table('users')->count();
$max   = QB::table('orders')->max('amount');
```

### Model Pattern
Models should encapsulate table-specific queries. Example:
```php
namespace App\Models;

class ChartModel {
    public function getByCountry(string $country, int $limit, int $offset): array {
        return (array) QB::table('apple_main')
            ->where('country', $country)
            ->orderBy('position', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }
    
    public function getCount(string $country): int {
        return QB::table('apple_main')
            ->where('country', $country)
            ->count();
    }
}
```

---

## 6. Enums (`Library\Enum`)

Abstract base class. All project enums extend it.

### Creating an Enum
```php
namespace App\Enums;
use Library\Enum;

class StatusEnum extends Enum {
    const ACTIVE   = 1;
    const INACTIVE = 0;
    const BANNED   = -1;
}
```

### Built-in Methods (inherited)
```php
StatusEnum::getConstants();              // ['ACTIVE' => 1, 'INACTIVE' => 0, 'BANNED' => -1]
StatusEnum::getConstantName(1);          // 'ACTIVE'
StatusEnum::isValidName('ACTIVE');       // true
StatusEnum::isValidName('active');       // true (case-insensitive by default)
StatusEnum::isValidName('active', true); // false (strict = case-sensitive)
StatusEnum::isValidValue(1);             // true
StatusEnum::isValidValue(99);            // false
```

### Existing Enums

**`ResponseStatusEnum`** — HTTP-like status codes for API responses:
- `SUCCESS = 200`, `BAD_REQUEST = 400`, `UNAUTHORIZED = 401`, `FORBIDDEN = 403`, `NOT_FOUND = 404`, `TOO_MANY_REQUEST = 429`
- Custom: `UPGRADE = 300`, `UNABLE_TO_PROCESS = 303`, `INPUT_MISSING = 304`, `INVALID_INPUT = 305`, `NO_DATA_FOUND = 306`, etc.

**`ChartsEnum`** — Platform, table, and chart constants & helpers:
- **Platforms**: `APPLE = 'apple'`, `SPOTIFY = 'spotify'`, `YOUTUBE = 'youtube'`
- **Defaults**: `DEFAULT_COUNTRY = 'US'`, `DEFAULT_CHART = 'top'`
- **Chart Types**: `CHART_TOP = 'top'`, `CHART_TRENDING = 'trending'`
- **Tables**: `APPLE_MAIN_TBL`, `SPOTIFY_MAIN_TBL`, `YOUTUBE_MAIN_TBL`, `HISTORY_TBL`, `GENRES_TBL`, `COUNTRIES_TBL`
- **Platform Helpers**: `platforms()`, `isValidPlatform($platform)`, `platformLabel($platform)`
- **Table Helpers**: `mainTable($platform)`
- **Chart Helpers**: `resolveChart($platform, $chart)`, `defaultCharts($platform)`, `chartLabel($chart)`
- **Default Helpers**: `defaults()` (returns array with `platform`, `country`, `chart`)

**`DeviceEnum`** — `MOBILE = 1`, `DESKTOP = 2`

---

## 7. Traits

### `ToolsTrait` (used by Controller)
| Method | Description |
|---|---|
| `getPayloads($method)` | Gets trimmed input (POST or GET) |
| `trim_arr(&$arr)` | Recursively trims all string values |
| `input_char_limit($payload, $len)` | Validates max character length (currently disabled, returns true) |
| `restrict($mdl, $type, $id)` | Plan-based feature restriction (sends LIMIT_EXCEEDED) |
| `sendJson($status, $msg, $data)` | **Primary response method** — see Controller section |
| `sendRawJson($data)` | Raw JSON response |

### `ThrottleTrait` (used by Controller)
| Method | Description |
|---|---|
| `rateLimitMe($minute)` | Redis-based rate limiting per IP+endpoint. Pass requests-per-minute. Scales automatically to 5m/10m/1h/6h/12h/1d/1w windows. Sends `TOO_MANY_REQUEST` on exceed. |

---

## 8. Input Handling (`Library\Input`)

CI-inspired input class. Instantiated in Controller as `$this->input`.

```php
$this->input->get('key');             // $_GET['key']
$this->input->post('key');            // $_POST['key']
$this->input->get();                  // All $_GET
$this->input->post();                 // All $_POST
$this->input->get('key', true);       // With XSS cleaning
$this->input->server('REMOTE_ADDR');  // $_SERVER value
$this->input->cookie('name');         // $_COOKIE value
$this->input->method();               // 'get', 'post', etc.
$this->input->method(true);           // 'GET', 'POST', etc.
$this->input->ip_address();           // Client IP (proxy-aware)
$this->input->user_agent();           // User-Agent string
$this->input->request_headers();      // All request headers
$this->input->get_request_header('Authorization'); // Single header
Input::is_ajax_request();             // Static: checks X-Requested-With
```

---

## 9. Global Utility Functions (`Inc/utilities.php`)

| Function | Signature | Description |
|---|---|---|
| `base64UrlEncode` | `(string $text): string` | URL-safe base64 encoding |
| `remove_invisible_characters` | `(string $str, bool $url_encoded = true): string` | Strips invisible/control characters |
| `generate_random_string` | `(int $length = 10): string` | Random alphanumeric string |
| `jsonEncode` | `(mixed $data): string` | `json_encode` with hex-safe flags |
| `jsonDecode` | `(string $data, bool $arr = true): mixed` | Wrapper for `json_decode` |
| `encryptString` | `(string $string): string` | AES-128-CTR encryption using `ENCRYPTION_KEY` |
| `decryptString` | `(string $ciphertext): string` | AES-128-CTR decryption using `ENCRYPTION_KEY` |
| `encrypt` | `(string $string): string` | Simple XOR-style obfuscation (for IDs) |
| `decrypt` | `(string $string): string` | Reverse of `encrypt()` |
| `encryptIds` | `(array $items, string $keyName = 'id'): array` | Bulk encrypt the 'id' field in array of rows |
| `handleSpecialChar` | `(string $text, int $remove_tags = 0): string` | Decode HTML entities, strip slashes, optionally strip tags |
| `is_valid_email` | `(string $email): bool` | Regex email validation |
| `is_valid_url` | `(string $url): bool` | URL validation via `FILTER_VALIDATE_URL` |
| `trim_string` | `(string $txt, int $limit = 300): string` | Truncate string with word-boundary awareness |
| `trimValues` | `(mixed $data): mixed` | Trim and lowercase (string or array) |
| `get_client_ip` | `(): string` | Client IP from various headers |
| `include_with_variables` | `(string $filePath, array $variables = []): string` | Include a PHP file with extracted variables, return output |
| `generate_otp` | `(int $digits = 4): string` | Generate numeric OTP |
| `is_valid_date` | `(string $date): bool` | Validate `Y-m-d` date string |
| `sanitize_query` | `(string $query): string` | Sanitize search query string |
| `getLogger` | `(string $name, ?string $subDir, ?string $logDir): array` | Returns `[$logger, $closer]` callables for file-based logging |

---

## 10. JWT (`Library\Jwt`)

```php
// Generate a JWT token:
$token = \Library\Jwt::generate(['user_id' => 123, 'role' => 'admin']);

// Validate a JWT token:
$isValid = \Library\Jwt::validate($token); // returns bool
```
Uses HMAC-SHA256. Secret is `JWT_SECRET` constant.

---

## 11. Redis (`Library\Red`)

Static wrapper around phpredis. All methods are static.

```php
// Basic key-value:
Red::set('key', 'value', $expireSeconds);
Red::get('key');
Red::exists('key');
Red::delete('key');
Red::incr('key');
Red::ttl('key');
Red::setTimeout('key', $seconds);

// Lists:
Red::lPush('key', 'value');
Red::rPush('key', 'value');
Red::lPop('key');
Red::rPop('key');
Red::lLen('key');
Red::lRange('key', $start, $end);
Red::lTrim('key', $start, $end);
Red::lIndex('key', $index);
Red::lRem('key', 'value', $count);

// Hash:
Red::HSET('key', 'field', 'value');
Red::HGET('key', 'field');
Red::HDEL('key', 'field');
Red::HGETALL('key');
Red::HLEN('key');
Red::HINCRBY('key', 'field', $by);

// Sorted Sets:
Red::zAdd('key', $score, 'value');
Red::zRank('key', 'value');
Red::zScore('key', 'value');
Red::zRange('key', $start, $end);
Red::zRangeByScore('key', $min, $max, $options);
Red::zRevRangeByScore('key', $max, $min, $options);
Red::zDelete('key', 'value');
Red::zSize('key');
Red::zCount('key', $min, $max);
Red::zIncrementBy('key', 'value', $by);

// Pipeline operations (batch):
Red::HSETPipeline($objArray);
Red::HGETPipeline($objArray);
Red::zAddPipeline($objArray);

// Sets:
Red::SADD('key', 'value');
Red::SREM('key', 'value');
Red::SMEMBERS('key');

// HyperLogLog:
Red::PFADD('key', 'value');
Red::PFCOUNT('key');
```

---

## 12. Security (`Library\Security`)

CI-derived XSS filter. Used internally by `Input` class.

```php
$security = new \Library\Security();
$clean = $security->xss_clean($dirtyString);
$clean = $security->xss_clean($arrayOfStrings);
$safe  = $security->sanitize_filename($filename);
$decoded = $security->entity_decode($htmlString);
```

---

## 13. Composer Dependencies

| Package | Purpose |
|---|---|
| `guzzlehttp/guzzle ^7.5` | HTTP client for external API calls |
| `rakit/validation ^1.4` | Input validation library |
| `usmanhalalit/pixie 2.*@dev` | Database query builder (aliased as `QB`) |

---

## 14. Controller Template — How to Write a New Controller

```php
<?php
namespace App\Controllers;

use App\Enums\ResponseStatusEnum;
use App\Enums\ChartsEnum;
// use App\Models\YourModel;

class YourController extends Controller {
    
    // private $model;
    
    public function __construct($vars = []) {
        parent::__construct($vars);
        
        // Optional: register middleware
        // $this->middleware([
        //     $this->auth_user_key => [
        //         'class'  => get_class($this),
        //         'except' => ['publicMethod'],
        //     ],
        // ]);
        
        // $this->model = new YourModel();
    }
    
    public function index() {
        $this->setPagination();
        
        // Example: get paginated results
        // $total   = $this->model->getCount();
        // $results = $this->model->getAll($this->pagination_limit, $this->pagination_offset);
        // $this->paginate($total, $results);
        
        $this->sendJson(ResponseStatusEnum::SUCCESS, "", ["message" => "Hello"]);
    }
    
    public function store() {
        $this->validateInput([
            'name'  => 'required|min:3|max:100',
            'email' => 'required|email',
        ]);
        
        // $id = QB::table('your_table')->insertGetId([...]);
        $this->sendJson(ResponseStatusEnum::SUCCESS, "Created");
    }
    
    public function show($id) {
        // $item = QB::table('your_table')->where('id', decrypt($id))->first();
        // if (!$item) $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND);
        // $this->sendJson(ResponseStatusEnum::SUCCESS, "", (array) $item);
    }
}
```

Then in `routes.php`:
```php
$router->get('/your-resource', 'YourController@index');
$router->post('/your-resource', 'YourController@store');
$router->get('/your-resource/{id}', 'YourController@show');
```

---

## 15. Coding Conventions & Principles

1. **All controllers extend `App\Controllers\Controller`** — never bypass the base class.
2. **Always use `$this->sendJson()`** for responses — never raw `echo` + `die` in controllers.
3. **Use `$this->validateInput()`** for input validation — it auto-sends 400 on failure.
4. **Use `$this->payload`** to access request data — it's already trimmed.
5. **Use Pixie `QB::`** for all database operations — no raw PDO.
6. **Enums extend `Library\Enum`** — use class constants, not magic strings.
7. **Use `encryptIds()` / `encrypt()` / `decrypt()`** for exposing/receiving database IDs in API responses.
8. **Use `$this->setPagination()` + `$this->paginate()`** for list endpoints.
9. **Models encapsulate queries** — controllers should not contain inline SQL.
10. **DRY: use traits** for cross-cutting concerns. Existing: `ToolsTrait`, `ThrottleTrait`.
11. **Response flow**: `sendJson()` calls `die` — no code after it will execute.
12. **Route format**: `'ControllerName@methodName'` — Router auto-resolves via namespace.

---

## 16. Database Schema

The `charts` database uses the following table structure. You must use these tables and columns exactly as defined when writing Pixie queries.

### Shared Reference Tables
**`countries`**
- `country_code` (CHAR(2), PK)
- `display_name` (VARCHAR(100))
- `flag` (VARCHAR(16))

**`genres`**
- `id` (INT, PK, Auto-Increment)
- `platform` (VARCHAR(10))
- `native_id` (VARCHAR(50)) — e.g. '1488', 'business'
- `display_name` (VARCHAR(100))
- *Unique:* `(platform, native_id)`

### Platform Data Tables
**`apple_main`**
- `id` (INT, PK, Auto-Increment)
- `country_code` (CHAR(2), FK to countries)
- `genre_id` (VARCHAR(50)) — native genre id or 'top'
- `chart_rank` (INT)
- `rank_move` (VARCHAR(12)) — UP/DOWN/UNCHANGED/NEW
- `apple_id` (VARCHAR(50))
- `name` (VARCHAR(255))
- `artist` (VARCHAR(255))
- `artwork` (VARCHAR(500))
- `url` (VARCHAR(500))
- `match_key` (VARCHAR(500)) — normalized for cross-platform matching
- `run_date` (DATE)
- *Unique:* `(country_code, genre_id, apple_id)`

**`spotify_main`**
- `id` (INT, PK, Auto-Increment)
- `country_code` (CHAR(2), FK to countries)
- `chart` (VARCHAR(50)) — slug: 'top-podcasts', 'trending', genre
- `chart_rank` (INT)
- `rank_move` (VARCHAR(12))
- `spotify_id` (VARCHAR(50))
- `name` (VARCHAR(255))
- `publisher` (VARCHAR(255))
- `artwork` (VARCHAR(500))
- `match_key` (VARCHAR(500))
- `run_date` (DATE)
- *Unique:* `(country_code, chart, spotify_id)`

**`youtube_main`**
- `id` (INT, PK, Auto-Increment)
- `country_code` (CHAR(2), FK to countries)
- `chart_rank` (INT)
- `rank_move` (VARCHAR(12))
- `youtube_id` (VARCHAR(50))
- `name` (VARCHAR(255))
- `channel` (VARCHAR(255))
- `artwork` (VARCHAR(500))
- `channel_url` (VARCHAR(500))
- `match_key` (VARCHAR(500))
- `run_date` (DATE)
- *Unique:* `(country_code, youtube_id)`

### Operational Tables
**`history`** (Append-only for all platforms)
- `id` (INT, PK, Auto-Increment)
- `platform` (VARCHAR(10))
- `country_code` (CHAR(2))
- `chart` (VARCHAR(50)) — apple: genre_id or 'top', spotify: slug, youtube: 'top'
- `external_id` (VARCHAR(50)) — apple_id/spotify_id/youtube_id
- `chart_rank` (INT)
- `run_date` (DATE)
- *Unique:* `(platform, country_code, chart, external_id, run_date)`

**`runs`** (Loader manifest)
- `id` (INT, PK, Auto-Increment)
- `run_date` (DATE)
- `platform` (VARCHAR(10))
- `country_code` (CHAR(2))
- `chart` (VARCHAR(50))
- `rows_loaded` (INT)
- `rows_skipped` (INT)
- `status` (VARCHAR(10)) — ok/skipped/failed
- `error` (TEXT)
- `created_at` (TIMESTAMP)
