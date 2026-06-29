<?php

namespace App\Controllers;

use App\Enums\ResponseStatusEnum;
use App\Models\UserModel;
use Library\Jwt;

class AuthController extends Controller
{
    private UserModel $model;

    public function __construct($vars = [])
    {
        parent::__construct($vars);

        $this->model = new UserModel();

        $this->middleware([
            $this->auth_user_key => [
                'class'  => __CLASS__,
                'except' => ['register', 'login', 'google','logout'],
            ],
        ]);

        // Propagate auth data set by verifyAuthToken onto this instance
        if (isset($this->mw[$this->auth_user_key])) {
            $this->auto_id  = $this->mw[$this->auth_user_key]->auto_id  ?? null;
            $this->email_id = $this->mw[$this->auth_user_key]->email_id ?? null;
        }
    }

    public function register(): void
    {
        $this->validateInput([
            'name'     => 'required|min:2|max:100',
            'email'    => 'required|email',
            'password' => 'required|min:8',
        ]);

        $name     = $this->payload['name'];
        $email    = strtolower($this->payload['email']);
        $password = $this->payload['password'];

        if ($this->model->findByEmail($email)) {
            $this->sendJson(ResponseStatusEnum::ALREADY_REGISTERED);
        }

        $id = $this->model->create([
            'name'       => $name,
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_BCRYPT),
            'status'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $token = Jwt::generate([
            'user_id' => encrypt((string) $id),
            'email'   => $email,
            'iat'     => time(),
        ]);

        $this->input->set_cookie('mp_token', $token, 60 * 60 * 24 * 30, '', '/', '', false, true);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", [
            'token' => $token,
            'user'  => [
                'id'    => encrypt((string) $id),
                'name'  => $name,
                'email' => $email,
            ],
        ]);
    }

    public function login(): void
    {
        $this->validateInput([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $email    = strtolower($this->payload['email']);
        $password = $this->payload['password'];

        $user = $this->model->findByEmail($email);
        if (!$user) {
            $this->sendJson(ResponseStatusEnum::NO_USER);
        }

        if ((int) $user->status === 0) {
            $this->sendJson(ResponseStatusEnum::ACCOUNT_DEACTIVATED);
        }

        if (!password_verify($password, $user->password)) {
            $this->sendJson(ResponseStatusEnum::INVALID_PASSWORD);
        }

        $token = Jwt::generate([
            'user_id' => encrypt((string) $user->id),
            'email'   => $user->email,
            'iat'     => time(),
        ]);

        $this->input->set_cookie('mp_token', $token, 60 * 60 * 24 * 30, '', '/', '', false, true);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", [
            'token' => $token,
            'user'  => [
                'id'    => encrypt((string) $user->id),
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function me(): void
    {
        $user = $this->model->findById($this->auto_id);
        if (!$user) {
            $this->sendJson(ResponseStatusEnum::NO_USER);
        }

        $data       = (array) $user;
        $data['id'] = encrypt((string) $user->id);
        unset($data['password']);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", ['user' => $data]);
    }
    public function google(): void
    {
        $this->validateInput([
            'credential' => 'required',
        ]);

        $idToken = $this->payload['credential'];

        // Verify token with Google — checks signature, aud, iss, exp
        $client  = new \Google_Client(['client_id' => \GOOGLE_CLIENT_ID]);
        $payload = $client->verifyIdToken($idToken);

        if (!$payload) {
            $this->sendJson(ResponseStatusEnum::UNAUTHORIZED);
        }

        $sub    = $payload['sub'];
        $email  = strtolower($payload['email']);
        $name   = $payload['name']    ?? $email;
        $avatar = $payload['picture'] ?? null;

        // 1. Returning Google user?
        $user = $this->model->findByGoogleId($sub);

        // 2. Not found by sub — existing local account with same email? Auto-link it.
        if (!$user) {
            $existing = $this->model->findByEmail($email);
            if ($existing) {
                $this->model->linkGoogle((int) $existing->id, $sub, $avatar);
                $user = $this->model->findById((int) $existing->id);
            }
        }

        // 3. Brand-new — create a Google-only account
        if (!$user) {
            $id = $this->model->create([
                'name'          => $name,
                'email'         => $email,
                'google_id'     => $sub,
                'auth_provider' => 'google',
                'avatar_url'    => $avatar,
                'password'      => null,
                'status'        => 1,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            $user = $this->model->findById($id);
        }

        // Same deactivation check as local login
        if ((int) $user->status === 0) {
            $this->sendJson(ResponseStatusEnum::ACCOUNT_DEACTIVATED);
        }

        // Issue YOUR platform JWT — identical shape to login()
        $token = Jwt::generate([
            'user_id' => encrypt((string) $user->id),
            'email'   => $user->email,
            'iat'     => time(),
        ]);

        $this->input->set_cookie('mp_token', $token, 60 * 60 * 24 * 30, '', '/', '', false, true);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", [
            'token' => $token,
            'user'  => [
                'id'    => encrypt((string) $user->id),
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function logout(): void
    {
        $this->input->set_cookie('mp_token', '', -1, '', '/', '', false, true);
        $this->sendJson(ResponseStatusEnum::SUCCESS, "Logged out");
    }
}
