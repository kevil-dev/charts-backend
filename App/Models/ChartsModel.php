<?php
namespace App\Models;

use App\Enums\ChartsEnum;

class ChartsModel
{
    /**
     * Returns the most recent run_date that exists in the platform
     * table for the given country + chart combination.
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

    /**
     
     * Shape guarantee (every platform returns these keys):
     *   id, chart_rank, rank_move, name, artist_or_publisher,
     *   artwork, url_or_null, external_id, match_key,
     *   country_code, country_name, flag, genre_label, run_date,
     *   on_apple (bool), apple_url (string|null),
     *   on_spotify (bool), spotify_id (string|null),
     *   on_youtube (bool), youtube_url (string|null)
     *
     * "artist_or_publisher" normalises: Apple→artist, Spotify→publisher,
     * YouTube→channel.
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

        $rows = match ($platform) {
            ChartsEnum::APPLE   => $this->fetchApple($table, $country, $chart, $runDate, $limit, $offset),
            ChartsEnum::SPOTIFY => $this->fetchSpotify($table, $country, $chart, $runDate, $limit, $offset),
            ChartsEnum::YOUTUBE => $this->fetchYoutube($table, $country, $runDate, $limit, $offset),
            default             => [],
        };

        $rows = $this->enrichWithPlatformLinks($rows, $country);

        return array_map(fn($r) => (array) $r, $rows);
    }

    /**
     * Returns only countries that have actual data for the platform
     * in the most recent scrape run.
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

    /**
     * Enriches a page of chart rows with cross-platform presence flags and links.
     */
    private function enrichWithPlatformLinks(array $rows, string $country): array
    {
        $matchKeys = array_values(array_filter(
            array_map(fn($r) => $r->match_key ?? '', $rows)
        ));

        if (empty($matchKeys)) {
            return $rows;
        }

        $apple   = ChartsEnum::APPLE_MAIN_TBL;
        $spotify = ChartsEnum::SPOTIFY_MAIN_TBL;
        $youtube = ChartsEnum::YOUTUBE_MAIN_TBL;

        $appleLatest   = \QB::table($apple)->where('country_code', $country)->select(\QB::raw('MAX(run_date) AS d'))->first()?->d ?? null;
        $spotifyLatest = \QB::table($spotify)->where('country_code', $country)->select(\QB::raw('MAX(run_date) AS d'))->first()?->d ?? null;
        $youtubeLatest = \QB::table($youtube)->where('country_code', $country)->select(\QB::raw('MAX(run_date) AS d'))->first()?->d ?? null;

        $appleMap = [];
        if ($appleLatest) {
            $appleRows = \QB::table($apple)
                ->where('country_code', $country)
                ->where('run_date', $appleLatest)
                ->whereIn('match_key', $matchKeys)
                ->select(['match_key', 'url', 'apple_id'])
                ->get();
            foreach ((array) $appleRows as $r) {
                $r = (array) $r;
                $appleMap[$r['match_key']] = ['url' => $r['url'], 'apple_id' => $r['apple_id']];
            }
        }

        $spotifyMap = [];
        if ($spotifyLatest) {
            $spotifyRows = \QB::table($spotify)
                ->where('country_code', $country)
                ->where('run_date', $spotifyLatest)
                ->whereIn('match_key', $matchKeys)
                ->select(['match_key', 'spotify_id'])
                ->get();
            foreach ((array) $spotifyRows as $r) {
                $r = (array) $r;
                $spotifyMap[$r['match_key']] = ['spotify_id' => $r['spotify_id']];
            }
        }

        $youtubeMap = [];
        if ($youtubeLatest) {
            $youtubeRows = \QB::table($youtube)
                ->where('country_code', $country)
                ->where('run_date', $youtubeLatest)
                ->whereIn('match_key', $matchKeys)
                ->select(['match_key', 'channel_url'])
                ->get();
            foreach ((array) $youtubeRows as $r) {
                $r = (array) $r;
                $youtubeMap[$r['match_key']] = ['channel_url' => $r['channel_url']];
            }
        }

        foreach ($rows as &$row) {
            $mk = $row->match_key ?? '';
            $row->on_apple    = isset($appleMap[$mk]);
            $row->apple_url   = $appleMap[$mk]['url'] ?? null;
            $row->on_spotify  = isset($spotifyMap[$mk]);
            $row->spotify_id  = $spotifyMap[$mk]['spotify_id'] ?? null;
            $row->on_youtube  = isset($youtubeMap[$mk]);
            $row->youtube_url = $youtubeMap[$mk]['channel_url'] ?? null;
        }
        unset($row);

        return $rows;
    }
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
    private function getSpotifyCharts(string $country): array
    {
        $rows = \QB::table(ChartsEnum::SPOTIFY_MAIN_TBL)
            ->where('country_code', $country)
            ->select(\QB::raw('DISTINCT `chart` AS native_id'))
            ->get();

        $result = [];
        foreach ((array) $rows as $r) {
            $dbSlug = is_object($r) ? $r->native_id : $r['native_id'];

            // Translate DB-internal slugs back to URL-friendly slugs.
            // "top-podcasts" in the DB corresponds to "top" in the URL
            // (ChartsEnum::resolveChart bridges the two at query time).
            $urlSlug = match ($dbSlug) {
                'top-podcasts' => ChartsEnum::CHART_TOP,
                default        => $dbSlug,
            };

            $result[] = [
                'native_id'    => $urlSlug,
                'display_name' => ChartsEnum::chartLabel($urlSlug),
            ];
        }

        // Sort by display name for consistency
        usort($result, fn($a, $b) => strcmp($a['display_name'], $b['display_name']));

        return $result;
    }
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