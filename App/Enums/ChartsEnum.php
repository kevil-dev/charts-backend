<?php

namespace App\Enums;

use Library\Enum;

class ChartsEnum extends Enum {

    const APPLE   = 'apple';
    const SPOTIFY = 'spotify';
    const YOUTUBE = 'youtube';

    const DEFAULT_COUNTRY  = 'US';   
    const DEFAULT_CHART    = 'top';  

    const CHART_TOP      = 'top';

    const APPLE_MAIN_TBL   = 'apple_main';
    const SPOTIFY_MAIN_TBL = 'spotify_main';
    const YOUTUBE_MAIN_TBL = 'youtube_main';
    const HISTORY_TBL      = 'history';
    const GENRES_TBL       = 'genres';
    const COUNTRIES_TBL    = 'countries';


    public static function platforms(): array
    {
        return [self::APPLE, self::SPOTIFY, self::YOUTUBE];
    }

    public static function mainTable(string $platform): ?string
    {
        $map = [
            self::APPLE   => self::APPLE_MAIN_TBL,
            self::SPOTIFY => self::SPOTIFY_MAIN_TBL,
            self::YOUTUBE => self::YOUTUBE_MAIN_TBL,
        ];
        return $map[$platform] ?? null;
    }

    // Spotify stores 'top' as 'top-podcasts' in the DB — translate here once
    public static function resolveChart(string $platform, string $chart): string
    {
        if ($platform === self::SPOTIFY && $chart === self::CHART_TOP) {
            return 'top-podcasts';
        }
        return $chart;
    }
}