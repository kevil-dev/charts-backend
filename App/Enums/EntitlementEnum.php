<?php

namespace App\Enums;

use Library\Enum;

class EntitlementEnum extends Enum
{
    const LIST_CAPS = ['guest' => 0, 'free' => 1, 'pro' => 20, 'elite' => null];

    const META_COLUMNS = [
        'guest' => [],
        'free'  => ['match_key', 'description', 'primary_genre', 'author', 'episode_count'],
        'pro'   => [
            'match_key', 'description', 'primary_genre', 'author', 'episode_count',
            'long_description', 'language', 'first_published_date',
            'last_published_date', 'release_frequency',
            'avg_episode_duration_minutes', 'content_advisory_rating',
            'website_url', 'feed_url',
        ],
        'elite' => [
            'match_key', 'description', 'primary_genre', 'author', 'episode_count',
            'long_description', 'language', 'first_published_date',
            'last_published_date', 'release_frequency',
            'avg_episode_duration_minutes', 'content_advisory_rating',
            'website_url', 'feed_url',
            'rating_average', 'rating_count', 'rank_history', 'global_footprint',
        ],
    ];
}
