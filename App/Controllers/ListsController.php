<?php

namespace App\Controllers;

use App\Enums\ResponseStatusEnum;
use App\Models\ListsModel;
use App\Models\UserModel;

class ListsController extends Controller
{
    private ListsModel $model;

    public function __construct($vars = [])
    {
        parent::__construct($vars);

        // Every route in this controller requires auth
        // except getShared (public share link)
        $this->middleware([
            $this->auth_user_key => [
                'class'  => __CLASS__,
                'except' => ['getShared'],
            ],
        ]);

        if (isset($this->mw[$this->auth_user_key])) {
            $this->auto_id  = $this->mw[$this->auth_user_key]->auto_id  ?? null;
            $this->email_id = $this->mw[$this->auth_user_key]->email_id ?? null;
        }

        $this->model = new ListsModel();
    }

    // ─── Entitlement guard ────────────────────────────────────────────────
    // Fetches the authed user, computes entitlement, and terminates with
    // UPGRADE when the user has no active/trialing plan. Returns the
    // entitlement array so callers can enforce per-tier caps.

    private function requireListsAccess(): array
    {
        $user = (new UserModel())->findById($this->auto_id);
        $ent  = $this->getEntitlement($user);
        if ($ent['tier'] === null) {
            $this->sendJson(ResponseStatusEnum::UPGRADE);
        }
        return $ent;
    }

    // ─── GET /lists ───────────────────────────────────────────────────────
    // Returns all lists for the authenticated user

    public function index(): void
    {
        $this->requireListsAccess();

        $lists = $this->model->getAllByUser($this->auto_id);

        $result = array_map(function ($list) {
            $list = (array) $list;
            $list['id'] = encrypt((string) $list['id']);
            return $list;
        }, $lists);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", ['lists' => $result]);
    }

    // ─── POST /lists ──────────────────────────────────────────────────────
    // Create a new list

    public function store(): void
    {
        $this->validateInput([
            'title' => 'required|min:1|max:60',
        ]);

        $ent = $this->requireListsAccess();

        if ($ent['list_cap'] !== null) {
            if ($this->model->countByUser($this->auto_id) >= $ent['list_cap']) {
                $this->sendJson(ResponseStatusEnum::LIMIT_EXCEEDED);
            }
        }

        $title       = $this->payload['title'];
        $description = isset($this->payload['description'])
            ? substr(trim($this->payload['description']), 0, 200)
            : null;

        $id = $this->model->create($this->auto_id, $title, $description);

        $list = (array) $this->model->findById($id);
        $list['id'] = encrypt((string) $list['id']);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "List created", ['list' => $list]);
    }

    // ─── GET /lists/{id} ─────────────────────────────────────────────────
    // Returns a single list with its items

    public function show(string $encryptedId): void
    {
        $this->requireListsAccess();

        $id   = (int) decrypt($encryptedId);
        $list = $this->model->findById($id);

        if (!$list) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "List not found");
        }

        // Only the owner can view their private list
        if ((int) $list->user_id !== $this->auto_id) {
            $this->sendJson(ResponseStatusEnum::UNAUTHORIZED);
        }

        $items = $this->model->getItems($id);

        $result         = (array) $list;
        $result['id']   = encrypt((string) $list->id);
        $result['items'] = array_map(function ($item) {
            $item = (array) $item;
            $item['id'] = encrypt((string) $item['id']);
            return $item;
        }, $items);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", ['list' => $result]);
    }

    // ─── PATCH /lists/{id} ───────────────────────────────────────────────
    // Update list title or description

    public function update(string $encryptedId): void
    {
        $this->requireListsAccess();

        $id   = (int) decrypt($encryptedId);
        $list = $this->model->findById($id);

        if (!$list) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "List not found");
        }

        if ((int) $list->user_id !== $this->auto_id) {
            $this->sendJson(ResponseStatusEnum::UNAUTHORIZED);
        }

        $fields = [];

        if (isset($this->payload['title'])) {
            $title = trim($this->payload['title']);
            if (strlen($title) < 1 || strlen($title) > 60) {
                $this->sendJson(ResponseStatusEnum::INVALID_INPUT, "Title must be 1–60 characters");
            }
            $fields['title'] = $title;
        }

        if (array_key_exists('description', $this->payload)) {
            $fields['description'] = strlen(trim($this->payload['description'])) > 0
                ? substr(trim($this->payload['description']), 0, 200)
                : null;
        }

        if (empty($fields)) {
            $this->sendJson(ResponseStatusEnum::INVALID_INPUT, "Nothing to update");
        }

        $this->model->update($id, $fields);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "List updated");
    }

    // ─── DELETE /lists/{id} ──────────────────────────────────────────────
    // Delete a list and all its items

    public function destroy(string $encryptedId): void
    {
        $this->requireListsAccess();

        $id   = (int) decrypt($encryptedId);
        $list = $this->model->findById($id);

        if (!$list) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "List not found");
        }

        if ((int) $list->user_id !== $this->auto_id) {
            $this->sendJson(ResponseStatusEnum::UNAUTHORIZED);
        }

        $this->model->deleteWithItems($id);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "List deleted");
    }

    // ─── POST /lists/{id}/share ───────────────────────────────────────────
    // Generate or return existing share token

    public function share(string $encryptedId): void
    {
        $this->requireListsAccess();

        $id   = (int) decrypt($encryptedId);
        $list = $this->model->findById($id);

        if (!$list) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "List not found");
        }

        if ((int) $list->user_id !== $this->auto_id) {
            $this->sendJson(ResponseStatusEnum::UNAUTHORIZED);
        }

        // Reuse existing token if already shared
        $token = $list->share_token ?? sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6))
        );

        if (!$list->share_token) {
            $this->model->setShareToken($id, $token);
        }

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", ['share_token' => $token]);
    }

    // ─── DELETE /lists/{id}/share ─────────────────────────────────────────
    // Revoke share link (make private again)

    public function revokeShare(string $encryptedId): void
    {
        $this->requireListsAccess();

        $id   = (int) decrypt($encryptedId);
        $list = $this->model->findById($id);

        if (!$list) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "List not found");
        }

        if ((int) $list->user_id !== $this->auto_id) {
            $this->sendJson(ResponseStatusEnum::UNAUTHORIZED);
        }

        $this->model->revokeShareToken($id);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "Share link revoked");
    }

    // ─── GET /lists/shared/{token} ────────────────────────────────────────
    // Public route — no auth required

    public function getShared(string $token): void
    {
        $list = $this->model->findByShareToken($token);

        if (!$list || (int) $list->is_private === 1) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "List not found");
        }

        $items = $this->model->getItems((int) $list->id);

        $result          = (array) $list;
        $result['id']    = encrypt((string) $list->id);
        // Never expose user_id on a public endpoint
        unset($result['user_id']);
        $result['items'] = array_map(function ($item) {
            $item = (array) $item;
            $item['id'] = encrypt((string) $item['id']);
            return $item;
        }, $items);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", ['list' => $result]);
    }

    // ─── POST /lists/{id}/items ───────────────────────────────────────────
    // Add a podcast to a list

    public function addItem(string $encryptedId): void
    {
        $this->requireListsAccess();

        $this->validateInput([
            'podcast_name' => 'required|min:1|max:255',
            'platform'     => 'required|in:apple,spotify,youtube',
        ]);

        $id   = (int) decrypt($encryptedId);
        $list = $this->model->findById($id);

        if (!$list) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "List not found");
        }

        if ((int) $list->user_id !== $this->auto_id) {
            $this->sendJson(ResponseStatusEnum::UNAUTHORIZED);
        }

        $matchKey = $this->payload['match_key'] ?? null;

        // Block duplicate — same podcast already in this list
        if ($matchKey && $this->model->itemExists($id, $matchKey)) {
            $this->sendJson(ResponseStatusEnum::ALREADY_REGISTERED, "Already in this list");
        }

        $itemId = $this->model->addItem($id, [
            'podcast_name'   => $this->payload['podcast_name'],
            'podcast_author' => $this->payload['podcast_author'] ?? null,
            'artwork_url'    => $this->payload['artwork_url']    ?? null,
            'match_key'      => $matchKey,
            'platform'       => $this->payload['platform'],
            'genre'          => $this->payload['genre']          ?? null,
        ]);

        $item         = (array) $this->model->findItem($itemId);
        $item['id']   = encrypt((string) $item['id']);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "Added to list", ['item' => $item]);
    }

    // ─── DELETE /lists/{id}/items/{itemId} ───────────────────────────────
    // Remove a podcast from a list

    public function removeItem(string $encryptedListId, string $encryptedItemId): void
    {
        $this->requireListsAccess();

        $listId = (int) decrypt($encryptedListId);
        $itemId = (int) decrypt($encryptedItemId);

        $list = $this->model->findById($listId);

        if (!$list) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "List not found");
        }

        if ((int) $list->user_id !== $this->auto_id) {
            $this->sendJson(ResponseStatusEnum::UNAUTHORIZED);
        }

        $item = $this->model->findItem($itemId);

        if (!$item || (int) $item->list_id !== $listId) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "Item not found");
        }

        $this->model->deleteItem($itemId);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "Removed from list");
    }
}
