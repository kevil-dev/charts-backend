<?php
namespace App\Controllers;

use App\Enums\ChartsEnum;
use App\Enums\ResponseStatusEnum;
use App\Models\ChartsModel;

class ChartsController extends Controller
{
    private ChartsModel $model;

    public function __construct($vars = [])
    {
        // call parent first — boots Input, Validator, payload, headers
        parent::__construct($vars);

        $this->model = new ChartsModel();
    }

    /*
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
        $this->validateInput([
            'platform' => 'in:apple,spotify,youtube',
            'page'     => 'numeric|min:1',
            'limit'    => 'numeric|min:1|max:200',
        ]);

        $defaults = ChartsEnum::defaults();

        $platform = $this->payload['platform'] ?? $defaults['platform'];
        $country  = strtoupper($this->payload['country'] ?? $defaults['country']);
        $chart    = $this->payload['chart']    ?? $defaults['chart'];

        if (!ChartsEnum::isValidPlatform($platform)) {
            $this->sendJson(ResponseStatusEnum::INVALID_INPUT, "Invalid platform");
        }

        // Spotify stores 'top' as 'top-podcasts' — resolve before hitting DB
        $resolvedChart = ChartsEnum::resolveChart($platform, $chart);

        $runDate = $this->model->getLatestRunDate($platform, $country, $resolvedChart);

        if (!$runDate) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "No data available for these filters");
        }

        $this->setPagination();

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

        $pagination                   = $this->getPagination($total, $results);
        $pagination['run_date']       = $runDate;
        $pagination['platform_label'] = ChartsEnum::platformLabel($platform);
        $pagination['chart_label']    = ChartsEnum::chartLabel($chart);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", $pagination);
    }
    /*
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
     */
    public function getFilters(): void
    {
        $this->validateInput([
            'platform' => 'in:apple,spotify,youtube',
        ]);

        $defaults = ChartsEnum::defaults();

        $platform = $this->payload['platform'] ?? $defaults['platform'];
        $country  = strtoupper($this->payload['country'] ?? $defaults['country']);

        if (!ChartsEnum::isValidPlatform($platform)) {
            $this->sendJson(ResponseStatusEnum::INVALID_INPUT, "Invalid platform");
        }

        $countries = $this->model->getFilterCountries($platform);
        $genres    = $this->model->getFilterGenres($platform, $country);

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