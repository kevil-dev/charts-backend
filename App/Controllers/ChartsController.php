<?php
namespace App\Controllers;

use App\Enums\ChartsEnum;
use App\Enums\ResponseStatusEnum;
use App\Models\ChartsModel;

/**
 * ChartsController
 *
 * Handles:
 *   GET /charts          → getCharts()   paginated chart rows
 *   GET /charts/filters  → getFilters()  dropdown data (countries + genres)
 *
 * Both endpoints are public — no auth middleware needed.
 * Input comes from $this->payload (query string, already trimmed).
 */
class ChartsController extends Controller
{
    private ChartsModel $model;

    public function __construct($vars = [])
    {
        // Always call parent first — boots Input, Validator, payload, headers
        parent::__construct($vars);

        $this->model = new ChartsModel();
    }

    // ---------------------------------------------------------------
    // GET /charts
    // ---------------------------------------------------------------

    /**
     * Returns a paginated list of chart entries for the given filters.
     *
     * Query params (all optional — fall back to ChartsEnum::defaults()):
     *   platform  apple | spotify | youtube        default: apple
     *   country   ISO 3166-1 alpha-2 (US, GB …)   default: US
     *   chart     top | trending | <genre_id>      default: top
     *   page      integer >= 1                     default: 1
     *   limit     integer >= 1                     default: 50
     *
     * Success response shape:
     * {
     *   "status": 200,
     *   "msg": "Success!",
     *   "data": {
     *     "results": [ { chart row … }, … ],
     *     "total": 200,
     *     "per_page": 50,
     *     "current_page": 1,
     *     "last_page": 4,
     *     "run_date": "2026-06-19",
     *     "platform_label": "Apple",
     *     "chart_label": "Top Podcasts"
     *   }
     * }
     */
    public function getCharts(): void
    {
        // --- 1. Read + validate inputs ---
        $this->validateInput([
            'platform' => 'in:apple,spotify,youtube',
            'page'     => 'numeric|min:1',
            'limit'    => 'numeric|min:1|max:200',
        ]);

        $defaults = ChartsEnum::defaults();

        $platform = $this->payload['platform'] ?? $defaults['platform'];
        $country  = strtoupper($this->payload['country'] ?? $defaults['country']);
        $chart    = $this->payload['chart']    ?? $defaults['chart'];

        // Additional guard: platform must be one of our three
        // (rakit 'in:' rule already covers it, but be explicit for safety)
        if (!ChartsEnum::isValidPlatform($platform)) {
            $this->sendJson(ResponseStatusEnum::INVALID_INPUT, "Invalid platform");
        }

        // Spotify stores 'top' as 'top-podcasts' — resolve before hitting DB
        $resolvedChart = ChartsEnum::resolveChart($platform, $chart);

        // --- 2. Fetch run date ONCE — bail early if this combination has no data.
        //        getCount() and getCharts() both need it internally; by resolving
        //        it here first we avoid running MAX(run_date) three times per request.
        $runDate = $this->model->getLatestRunDate($platform, $country, $resolvedChart);

        if (!$runDate) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "No data available for these filters");
        }

        // --- 3. Pagination (only reached when data exists) ---
        $this->setPagination();

        // --- 4. Count + results ---
        $total = $this->model->getCount($platform, $country, $resolvedChart);

        if ($total === 0) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "No data found");
        }

        $results = $this->model->getCharts(
            $platform,
            $country,
            $resolvedChart,
            $this->pagination_limit,
            $this->pagination_offset
        );

        // --- 5. Build paginated response with meta ---
        $pagination                   = $this->getPagination($total, $results);
        $pagination['run_date']       = $runDate;
        $pagination['platform_label'] = ChartsEnum::platformLabel($platform);
        $pagination['chart_label']    = ChartsEnum::chartLabel($chart);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", $pagination);
    }

    // ---------------------------------------------------------------
    // GET /charts/filters
    // ---------------------------------------------------------------

    /**
     * Returns the data needed to populate the platform, country, and
     * genre/chart dropdowns on the frontend.
     *
     * Query params:
     *   platform  apple | spotify | youtube   default: apple
     *   country   ISO 3166-1 alpha-2          default: US
     *
     * Why country is required for genres: genre availability is
     * country-specific. Japan may carry genres the US does not.
     * We only return genres that have actual rows for that country.
     *
     * Success response shape:
     * {
     *   "status": 200,
     *   "msg": "Success!",
     *   "data": {
     *     "countries": [
     *       { "country_code": "US", "display_name": "United States", "flag": "🇺🇸" },
     *       …
     *     ],
     *     "genres": [
     *       { "native_id": "1488", "display_name": "True Crime" },
     *       …
     *     ],
     *     "platforms": [
     *       { "value": "apple",   "label": "Apple" },
     *       { "value": "spotify", "label": "Spotify" },
     *       { "value": "youtube", "label": "YouTube" }
     *     ]
     *   }
     * }
     *
     * Note: `genres` is an empty array for YouTube (no genre concept).
     */
    public function getFilters(): void
    {
        // --- 1. Validate ---
        $this->validateInput([
            'platform' => 'in:apple,spotify,youtube',
        ]);

        $defaults = ChartsEnum::defaults();

        $platform = $this->payload['platform'] ?? $defaults['platform'];
        $country  = strtoupper($this->payload['country'] ?? $defaults['country']);

        if (!ChartsEnum::isValidPlatform($platform)) {
            $this->sendJson(ResponseStatusEnum::INVALID_INPUT, "Invalid platform");
        }

        // --- 2. Query ---
        $countries = $this->model->getFilterCountries($platform);
        $genres    = $this->model->getFilterGenres($platform, $country);

        // Build the platform list from the enum — single source of truth
        $platforms = array_map(
            fn(string $p) => ['value' => $p, 'label' => ChartsEnum::platformLabel($p)],
            ChartsEnum::platforms()
        );

        // --- 3. Respond ---
        $this->sendJson(ResponseStatusEnum::SUCCESS, "", [
            'countries' => $countries,
            'genres'    => $genres,
            'platforms' => $platforms,
        ]);
    }
}