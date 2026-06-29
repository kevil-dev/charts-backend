<?php

namespace App\Models;

class UserModel
{
    public function findByEmail(string $email): ?object
    {
        return \QB::table('users')->where('email', $email)->first();
    }

    public function create(array $data): int
    {
        return (int) \QB::table('users')->insert($data);
    }

    public function findById(int $id): ?object
    {
        return \QB::table('users')->where('id', $id)->first();
    }
    public function findByGoogleId(string $googleId): ?object
    {
        return \QB::table('users')->where('google_id', $googleId)->first();
    }

    public function linkGoogle(int $id, string $googleId, ?string $avatarUrl = null): void
    {
        \QB::table('users')->where('id', $id)->update([
            'google_id'  => $googleId,
            'avatar_url' => $avatarUrl,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
