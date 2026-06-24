<?php
namespace App\Models;

use App\Enums\ChartsEnum;

/**
 * ChartsModel
 *
 * Single model that handles all chart-related DB queries across
 * Apple, Spotify, and YouTube. Platform routing is driven by
 * ChartsEnum — zero hardcoded table names here.
 *
 * Public API
 * ----------
 * getLatestRunDate(string $platform, string $country, string $chart): ?string
 * getCount(string $platform, string $country, string $chart): int
 * getCharts(string $platform, string $country, string $chart, int $limit, int $offset): array
 * getFilterCountries(string $platform): array
 * getFilterGenres(string $platform, string $country): array
 */
class ChartsModel
{
    // ---------------------------------------------------------------
    // PUBLIC — LATEST RUN DATE
    // ---------------------------------------------------------------

    /**
     * Returns the most recent run_date that exists in the platform
     * table for the given country + chart combination.
     *
     * Why: we never want to show "no data" just because today hasn't
     * been scraped yet. We always show the freshest snapshot.
     *
     * Returns null when the table is completely empty for those filters.
     */
    public function getLatestRunDate(string $platform, string $country, string $chart): ?string
    {
        $table = ChartsEnum::mainTable($platform);
        if (!$table) {
            return null;
        }

        $q = \QB::table($table)->where('country_code', $country);
        $this->applyChartFilter($q, $platform, $chart);

        $row = $q->select(\QB::raw('MAX(run_date) AS latest_date'))->first();

        return $row && $row->latest_date ? $row->latest_date : null;
    }

    // ---------------------------------------------------------------
    // PUBLIC — COUNT (for pagination)
    // ---------------------------------------------------------------

    /**
     * Total rows for the given filter set on the latest run_date.
     * Called by the controller before getCharts() to build pagination.
     */
    public function getCount(string $platform, string $country, string $chart): int
    {
        $table = ChartsEnum::mainTable($platform);
        if (!$table) {
            return 0;
        }

        $runDate = $this->getLatestRunDate($platform, $country, $chart);
        if (!$runDate) {
            return 0;
        }

        $q = \QB::table($table)
            ->where('country_code', $country)
            ->where('run_date', $runDate);

        $this->applyChartFilter($q, $platform, $chart);

        return (int) $q->count();
    }

    // ---------------------------------------------------------------
    // PUBLIC — CHARTS LIST
    // ---------------------------------------------------------------

    /**
     * Returns paginated chart rows for a single platform snapshot.
     *
     * Shape guarantee (every platform returns these keys):
     *   id, chart_rank, rank_move, name, artist_or_publisher,
     *   artwork, url_or_null, external_id, match_key,
     *   country_code, country_name, flag, genre_label, run_date
     *
     * "artist_or_publisher" normalises: Apple→artist, Spotify→publisher,
     * YouTube→channel. The controller/frontend never needs to know
     * which column it came from.
     */
    public function getCharts(
        string $platform,
        string $country,
        string $chart,
        int    $limit,
        int    $offset
    ): array {
        $table = ChartsEnum::mainTable($platform);
        if (!$table) {
            return [];
        }

        $runDate = $this->getLatestRunDate($platform, $country, $chart);
        if (!$runDate) {
            return [];
        }

        // Build the raw query per platform — they have different
        // column names but we normalise them in SELECT aliases.
        $rows = match ($platform) {
            ChartsEnum::APPLE   => $this->fetchApple($table, $country, $chart, $runDate, $limit, $offset),
            ChartsEnum::SPOTIFY => $this->fetchSpotify($table, $country, $chart, $runDate, $limit, $offset),
            ChartsEnum::YOUTUBE => $this->fetchYoutube($table, $country, $runDate, $limit, $offset),
            default             => [],
        };

        return array_map(fn($r) => (array) $r, $rows);
    }

    // ---------------------------------------------------------------
    // PUBLIC — FILTERS
    // ---------------------------------------------------------------

    /**
     * Returns only countries that have actual data for the platform
     * in the most recent scrape run.
     *
     * Returns: [['country_code'=>'US','display_name'=>'United States','flag'=>'🇺🇸'], ...]
     */
    public function getFilterCountries(string $platform): array
    {
        $table = ChartsEnum::mainTable($platform);
        if (!$table) {
            return [];
        }

        // Subquery: latest run_date across the whole table
        $latestDate = \QB::table($table)
            ->select(\QB::raw('MAX(run_date)'))
            ->first();

        if (!$latestDate) {
            return [];
        }

        $latestArr = (array) $latestDate;
        $latestDateValue = is_object($latestDate)
            ? reset($latestArr)
            : null;

        if (!$latestDateValue) {
            return [];
        }

        $ct = ChartsEnum::COUNTRIES_TBL;

        $rows = \QB::table($table)
            ->join($ct, "$table.country_code", '=', "$ct.country_code")
            ->where("$table.run_date", $latestDateValue)
            ->select(["$ct.country_code", "$ct.display_name", "$ct.flag"])
            ->groupBy("$ct.country_code")
            ->orderBy("$ct.display_name", 'ASC')
            ->get();

        return array_map(fn($r) => (array) $r, (array) $rows);
    }

    /**
     * Returns genres that have actual data for the given platform + country.
     *
     * Country is required because genre availability varies per country —
     * e.g. Japan may have genres that the US does not, and vice versa.
     * We only show genres that have rows in the latest scrape for that country.
     *
     * Apple   → JOIN genres ON genre_id = native_id WHERE platform='apple' AND country_code=$country
     * Spotify → distinct chart slugs for that country
     * YouTube → no genres; returns empty array
     *
     * Returns: [['native_id'=>'1488','display_name'=>'True Crime'], ...]
     *          or for Spotify: [['native_id'=>'top-podcasts','display_name'=>'Top Podcasts'], ...]
     */
    public function getFilterGenres(string $platform, string $country): array
    {
        return match ($platform) {
            ChartsEnum::APPLE   => $this->getAppleGenres($country),
            ChartsEnum::SPOTIFY => $this->getSpotifyCharts($country),
            ChartsEnum::YOUTUBE => [],   // YouTube has no genre filter
            default             => [],
        };
    }

    // ---------------------------------------------------------------
    // PRIVATE — PLATFORM-SPECIFIC FETCH QUERIES
    // ---------------------------------------------------------------

    /**
     * Apple: genre_id can be a native genre id (e.g. '1488') or 'top'.
     * We LEFT JOIN genres so 'top' rows (no genres row) still appear.
     */
    private function fetchApple(
        string $table,
        string $country,
        string $chart,
        string $runDate,
        int    $limit,
        int    $offset
    ): array {
        // For Apple, $chart is either 'top' or a native_id like '1488'
        $gt = ChartsEnum::GENRES_TBL;
        $ct = ChartsEnum::COUNTRIES_TBL;

        $rows = \QB::table($table)
            ->leftJoin($gt, function ($join) use ($table, $gt) {
                $join->on("$table.genre_id", '=', "$gt.native_id")
                     ->on("$gt.platform", '=', \QB::raw("'apple'"));
            })
            ->join($ct, "$table.country_code", '=', "$ct.country_code")
            ->where("$table.country_code", $country)
            ->where("$table.run_date", $runDate)
            ->where("$table.genre_id", $chart)
            ->select([
                "$table.id",
                "$table.chart_rank",
                "$table.rank_move",
                "$table.name",
                \QB::raw("$table.artist AS artist_or_publisher"),
                "$table.artwork",
                \QB::raw("$table.url AS url"),
                \QB::raw("$table.apple_id AS external_id"),
                "$table.match_key",
                "$table.country_code",
                \QB::raw("$ct.display_name AS country_name"),
                "$ct.flag",
                \QB::raw("COALESCE($gt.display_name, 'Top Podcasts') AS genre_label"),
                "$table.run_date",
            ])
            ->orderBy("$table.chart_rank", 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return (array) $rows;
    }

    /**
     * Spotify: chart column holds the slug ('top-podcasts', 'trending').
     * No genre join — chart is itself the category.
     */
    private function fetchSpotify(
        string $table,
        string $country,
        string $chart,
        string $runDate,
        int    $limit,
        int    $offset
    ): array {
        // resolveChart already translated 'top' -> 'top-podcasts' upstream
        $ct = ChartsEnum::COUNTRIES_TBL;

        $rows = \QB::table($table)
            ->join($ct, "$table.country_code", '=', "$ct.country_code")
            ->where("$table.country_code", $country)
            ->where("$table.run_date", $runDate)
            ->where("$table.chart", $chart)
            ->select([
                "$table.id",
                "$table.chart_rank",
                "$table.rank_move",
                "$table.name",
                \QB::raw("$table.publisher AS artist_or_publisher"),
                "$table.artwork",
                \QB::raw('NULL AS url'),
                \QB::raw("$table.spotify_id AS external_id"),
                "$table.match_key",
                "$table.country_code",
                \QB::raw("$ct.display_name AS country_name"),
                "$ct.flag",
                \QB::raw("'" . addslashes(ChartsEnum::chartLabel($chart)) . "' AS genre_label"),
                "$table.run_date",
            ])
            ->orderBy("$table.chart_rank", 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return (array) $rows;
    }

    /**
     * YouTube: no chart column, no genre — just top by country.
     * channel_url is the equivalent of Apple's podcast URL.
     */
    private function fetchYoutube(
        string $table,
        string $country,
        string $runDate,
        int    $limit,
        int    $offset
    ): array {
        $ct = ChartsEnum::COUNTRIES_TBL;

        $rows = \QB::table($table)
            ->join($ct, "$table.country_code", '=', "$ct.country_code")
            ->where("$table.country_code", $country)
            ->where("$table.run_date", $runDate)
            ->select([
                "$table.id",
                "$table.chart_rank",
                "$table.rank_move",
                "$table.name",
                \QB::raw("$table.channel AS artist_or_publisher"),
                "$table.artwork",
                \QB::raw("$table.channel_url AS url"),
                \QB::raw("$table.youtube_id AS external_id"),
                "$table.match_key",
                "$table.country_code",
                \QB::raw("$ct.display_name AS country_name"),
                "$ct.flag",
                \QB::raw("'Top Podcasts' AS genre_label"),
                "$table.run_date",
            ])
            ->orderBy("$table.chart_rank", 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return (array) $rows;
    }

    // ---------------------------------------------------------------
    // PRIVATE — FILTER HELPERS
    // ---------------------------------------------------------------

    /**
     * Apple genres: only returns genres that have rows for this specific
     * country in the latest scrape. Scoped by country so the dropdown
     * only shows genres that actually have data to display.
     */
    private function getAppleGenres(string $country): array
    {
        $t  = ChartsEnum::APPLE_MAIN_TBL;
        $gt = ChartsEnum::GENRES_TBL;

        $rows = \QB::table($t)
            ->join($gt, function ($join) use ($t, $gt) {
                $join->on("$t.genre_id", '=', "$gt.native_id")
                     ->on("$gt.platform", '=', \QB::raw("'apple'"));
            })
            ->where("$t.country_code", $country)
            ->select(["$gt.native_id", "$gt.display_name"])
            ->groupBy("$gt.native_id")
            ->orderBy("$gt.display_name", 'ASC')
            ->get();

        return array_map(fn($r) => (array) $r, (array) $rows);
    }

    /**
     * Spotify "genres" are chart slugs stored in the chart column.
     * Scoped by country — not every country has 'trending', for example.
     * We pull distinct slugs for this country and map to human labels.
     */
    private function getSpotifyCharts(string $country): array
    {
        $rows = \QB::table(ChartsEnum::SPOTIFY_MAIN_TBL)
            ->where('country_code', $country)
            ->select(\QB::raw('DISTINCT `chart` AS native_id'))
            ->get();

        $result = [];
        foreach ((array) $rows as $r) {
            $slug = is_object($r) ? $r->native_id : $r['native_id'];
            $result[] = [
                'native_id'    => $slug,
                'display_name' => ChartsEnum::chartLabel($slug),
            ];
        }

        // Sort by display name for consistency
        usort($result, fn($a, $b) => strcmp($a['display_name'], $b['display_name']));

        return $result;
    }

    // ---------------------------------------------------------------
    // PRIVATE — SHARED QUERY HELPER
    // ---------------------------------------------------------------

    /**
     * Applies the correct WHERE clause for the chart/genre column
     * depending on the platform. Mutates the Pixie query builder $q.
     *
     * Apple   → WHERE genre_id = $chart
     * Spotify → WHERE chart = $chart  (already resolved to slug)
     * YouTube → no chart filter (only one chart type)
     */
    private function applyChartFilter(\Pixie\QueryBuilder\QueryBuilderHandler $q, string $platform, string $chart): void
    {
        match ($platform) {
            ChartsEnum::APPLE   => $q->where('genre_id', $chart),
            ChartsEnum::SPOTIFY => $q->where('chart', $chart),
            ChartsEnum::YOUTUBE => null,   // no-op
            default             => null,
        };
    }
}