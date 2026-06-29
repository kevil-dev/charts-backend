<?php

namespace App\Models;

class ListsModel
{
    // ─── Lists ────────────────────────────────────────────────────────────

    public function getAllByUser(int $userId): array
    {
        return \QB::table('lists')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->get() ?? [];
    }

    public function findById(int $id): ?object
    {
        return \QB::table('lists')
            ->where('id', $id)
            ->first() ?: null;
    }

    public function findByShareToken(string $token): ?object
    {
        return \QB::table('lists')
            ->where('share_token', $token)
            ->first() ?: null;
    }

    public function create(int $userId, string $title, ?string $description): int
    {
        return \QB::table('lists')->insert([
            'user_id'     => $userId,
            'title'       => $title,
            'description' => $description,
            'is_private'  => 1,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(int $id, array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        \QB::table('lists')->where('id', $id)->update($fields);
    }

    // public function delete(int $id): void
    // {
    //     \QB::table('lists')->where('id', $id)->delete();
    // }

    public function setShareToken(int $id, string $token): void
    {
        \QB::table('lists')->where('id', $id)->update([
            'share_token' => $token,
            'is_private'  => 0,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function revokeShareToken(int $id): void
    {
        \QB::table('lists')->where('id', $id)->update([
            'share_token' => null,
            'is_private'  => 1,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    // ─── List Items ───────────────────────────────────────────────────────

    public function getItems(int $listId): array
    {
        $items = \QB::table('list_items')
            ->where('list_id', $listId)
            ->orderBy('added_at', 'ASC')
            ->get() ?? [];

        $items = array_map(fn($i) => (array) $i, (array) $items);

        return $this->enrichItemsWithPlatforms($items);
    }

    private function enrichItemsWithPlatforms(array $items): array
    {
        $matchKeys = array_values(array_filter(
            array_map(fn($it) => is_array($it) ? ($it['match_key'] ?? '') : ($it->match_key ?? ''), $items)
        ));

        if (empty($matchKeys)) {
            return $items;
        }

        $apple   = \App\Enums\ChartsEnum::APPLE_MAIN_TBL;
        $spotify = \App\Enums\ChartsEnum::SPOTIFY_MAIN_TBL;
        $youtube = \App\Enums\ChartsEnum::YOUTUBE_MAIN_TBL;

        $appleLatest   = \QB::table($apple)->select(\QB::raw('MAX(run_date) AS d'))->first()?->d ?? null;
        $spotifyLatest = \QB::table($spotify)->select(\QB::raw('MAX(run_date) AS d'))->first()?->d ?? null;
        $youtubeLatest = \QB::table($youtube)->select(\QB::raw('MAX(run_date) AS d'))->first()?->d ?? null;

        $appleMap = [];
        if ($appleLatest) {
            $rows = \QB::table($apple)
                ->where('run_date', $appleLatest)
                ->whereIn('match_key', $matchKeys)
                ->select(['match_key', 'url', 'apple_id'])
                ->get();
            foreach ((array) $rows as $r) {
                $r = (array) $r;
                if (!isset($appleMap[$r['match_key']])) {
                    $appleMap[$r['match_key']] = ['url' => $r['url'], 'apple_id' => $r['apple_id']];
                }
            }
        }

        $spotifyMap = [];
        if ($spotifyLatest) {
            $rows = \QB::table($spotify)
                ->where('run_date', $spotifyLatest)
                ->whereIn('match_key', $matchKeys)
                ->select(['match_key', 'spotify_id'])
                ->get();
            foreach ((array) $rows as $r) {
                $r = (array) $r;
                if (!isset($spotifyMap[$r['match_key']])) {
                    $spotifyMap[$r['match_key']] = ['spotify_id' => $r['spotify_id']];
                }
            }
        }

        $youtubeMap = [];
        if ($youtubeLatest) {
            $rows = \QB::table($youtube)
                ->where('run_date', $youtubeLatest)
                ->whereIn('match_key', $matchKeys)
                ->select(['match_key', 'channel_url'])
                ->get();
            foreach ((array) $rows as $r) {
                $r = (array) $r;
                if (!isset($youtubeMap[$r['match_key']])) {
                    $youtubeMap[$r['match_key']] = ['channel_url' => $r['channel_url']];
                }
            }
        }

        foreach ($items as &$item) {
            $arr = (array) $item;
            $mk  = $arr['match_key'] ?? '';
            $arr['on_apple']    = isset($appleMap[$mk]);
            $arr['apple_url']   = $appleMap[$mk]['url'] ?? null;
            $arr['on_spotify']  = isset($spotifyMap[$mk]);
            $arr['spotify_id']  = $spotifyMap[$mk]['spotify_id'] ?? null;
            $arr['on_youtube']  = isset($youtubeMap[$mk]);
            $arr['youtube_url'] = $youtubeMap[$mk]['channel_url'] ?? null;
            $item = $arr;
        }
        unset($item);

        return $items;
    }

    public function itemExists(int $listId, string $matchKey): bool
    {
        return \QB::table('list_items')
            ->where('list_id', $listId)
            ->where('match_key', $matchKey)
            ->count() > 0;
    }

    public function addItem(int $listId, array $data): int
    {
        return \QB::table('list_items')->insert([
            'list_id'        => $listId,
            'podcast_name'   => $data['podcast_name'],
            'podcast_author' => $data['podcast_author'] ?? null,
            'artwork_url'    => $data['artwork_url']    ?? null,
            'match_key'      => $data['match_key']      ?? null,
            'platform'       => $data['platform'],
            'genre'          => $data['genre']          ?? null,
            'added_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    public function findItem(int $itemId): ?object
    {
        return \QB::table('list_items')
            ->where('id', $itemId)
            ->first() ?: null;
    }

    public function deleteItem(int $itemId): void
    {
        \QB::table('list_items')->where('id', $itemId)->delete();
    }

    public function getItemCount(int $listId): int
    {
        return \QB::table('list_items')
            ->where('list_id', $listId)
            ->count();
    }

    public function getFirstFourArtworks(int $listId): array
    {
        return \QB::table('list_items')
            ->select('artwork_url')
            ->where('list_id', $listId)
            ->orderBy('added_at', 'ASC')
            ->limit(4)
            ->get() ?? [];
    }
    public function deleteWithItems(int $id): void
    {
        \QB::table('list_items')->where('list_id', $id)->delete();
        \QB::table('lists')->where('id', $id)->delete();
    }
}
