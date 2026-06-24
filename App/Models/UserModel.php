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
}
