<?php

namespace App\Models;

class PodcastMetaModel
{
    /**
     * Selects only the passed $columns from podcast_meta — the model does
     * not make tier decisions, it just executes the column list it is given.
     */
    public function getByMatchKey(string $matchKey, array $columns): ?array
    {
        if (!in_array('match_key', $columns, true)) {
            $columns[] = 'match_key';
        }

        $row = \QB::table('podcast_meta')
            ->where('match_key', $matchKey)
            ->select($columns)
            ->first();

        if (!$row) {
            return null;
        }

        $meta = (array) $row;

        foreach (['rank_history', 'global_footprint'] as $jsonField) {
            if (in_array($jsonField, $columns, true) && isset($meta[$jsonField]) && is_string($meta[$jsonField])) {
                $decoded = json_decode($meta[$jsonField], true);
                if ($decoded !== null) {
                    $meta[$jsonField] = $decoded;
                }
            }
        }

        return $meta;
    }
}
