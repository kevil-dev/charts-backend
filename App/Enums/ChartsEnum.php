<?php
namespace App\Enums;

use Library\Enum;

class ChartsEnum extends Enum
{
    // --- Platforms ---
    const APPLE   = 'apple';
    const SPOTIFY = 'spotify';
    const YOUTUBE = 'youtube';

    // --- Defaults ---
    const DEFAULT_COUNTRY = 'US';
    const DEFAULT_CHART   = 'top';

    // --- Common chart values ---
    const CHART_TOP      = 'top';
    const CHART_TRENDING = 'trending';

    // --- DB Tables ---
    const APPLE_MAIN_TBL   = 'apple_main';
    const SPOTIFY_MAIN_TBL = 'spotify_main';
    const YOUTUBE_MAIN_TBL = 'youtube_main';
    const HISTORY_TBL      = 'history';
    const GENRES_TBL       = 'genres';
    const COUNTRIES_TBL    = 'countries';


    // ---------------------------------------------------------------
    // PLATFORM METHODS
    // ---------------------------------------------------------------

    // used wherever you need to loop all platforms
    // e.g. building the platform tabs on the page
    public static function platforms(): array
    {
        return [self::APPLE, self::SPOTIFY, self::YOUTUBE];
    }

    // used in controller to validate what came from the URL
    // ChartsEnum::isValidPlatform('apple') -> true
    // ChartsEnum::isValidPlatform('itunes') -> false
    public static function isValidPlatform(string $platform): bool
    {
        return in_array($platform, self::platforms(), true);
    }

    // used in the navbar / breadcrumb to show "Apple" not "apple"
    // ChartsEnum::platformLabel('spotify') -> "Spotify"
    public static function platformLabel(string $platform): string
    {
        $labels = [
            self::APPLE   => 'Apple',
            self::SPOTIFY => 'Spotify',
            self::YOUTUBE => 'YouTube',
        ];
        return $labels[$platform] ?? ucfirst($platform);
    }


    // ---------------------------------------------------------------
    // TABLE METHODS
    // ---------------------------------------------------------------

    // used in every model to get the right table without hardcoding
    // $table = ChartsEnum::mainTable('apple') -> 'apple_main'
    public static function mainTable(string $platform): ?string
    {
        $map = [
            self::APPLE   => self::APPLE_MAIN_TBL,
            self::SPOTIFY => self::SPOTIFY_MAIN_TBL,
            self::YOUTUBE => self::YOUTUBE_MAIN_TBL,
        ];
        return $map[$platform] ?? null;
    }


    // ---------------------------------------------------------------
    // CHART / CATEGORY METHODS
    // ---------------------------------------------------------------

    // Spotify stores 'top' as 'top-podcasts' in the DB
    // call this before every query so the model never has to know
    // ChartsEnum::resolveChart('spotify', 'top') -> 'top-podcasts'
    // ChartsEnum::resolveChart('apple', 'top')   -> 'top'
    public static function resolveChart(string $platform, string $chart): string
    {
        if ($platform === self::SPOTIFY && $chart === self::CHART_TOP) {
            return 'top-podcasts';
        }
        return $chart;
    }

    // used to build the category dropdown per platform
    // only returns what actually exists for that platform
    public static function defaultCharts(string $platform): array
    {
        $map = [
            self::APPLE   => [self::CHART_TOP],
            self::SPOTIFY => [self::CHART_TOP, self::CHART_TRENDING],
            self::YOUTUBE => [self::CHART_TOP],
        ];
        return $map[$platform] ?? [self::CHART_TOP];
    }

    // used in the dropdown label
    // ChartsEnum::chartLabel('top') -> 'Top Podcasts'
    public static function chartLabel(string $chart): string
    {
        $labels = [
            self::CHART_TOP      => 'Top Podcasts',
            self::CHART_TRENDING => 'Trending',
        ];
        return $labels[$chart] ?? ucfirst($chart);
    }


    // ---------------------------------------------------------------
    // DEFAULTS METHOD
    // ---------------------------------------------------------------

    // one place to get all defaults — used in the controller
    // before hitting the DB, fill in anything missing from the URL
    public static function defaults(): array
    {
        return [
            'platform' => self::APPLE,
            'country'  => self::DEFAULT_COUNTRY,
            'chart'    => self::DEFAULT_CHART,
        ];
    }
}