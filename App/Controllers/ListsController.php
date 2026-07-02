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
    // Fetches the authed user, resolves their tier, and terminates with
    // UPGRADE when the user is a guest. Returns the tier so callers can
    // enforce per-tier caps.

    private function requireListsAccess(): string
    {
        $user = (new UserModel())->findById($this->auto_id);
        $tier = $this->resolveTier($user);
        if ($tier === 'guest') {
            $this->sendJson(ResponseStatusEnum::UPGRADE);
        }
        return $tier;
    }

    // ─── GET /lists ───────────────────────────────────────────────────────
    // Returns all lists for the authenticated user

    public function index(): void
    {
        $this->requireListsAccess();

        $lists = $this->model->getAllByUser($this->auto_id);

        $result = [];
        foreach ($lists as $list) {
            $list = (array) $list;
            $list['item_count'] = $this->model->getItemCount((int) $list['id']);
            $list['items'] = array_map(fn($item) => (array) $item, $this->model->getFirstFourArtworks((int) $list['id']));
            $list['id'] = encrypt((string) $list['id']);
            $result[] = $list;
        }

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", ['lists' => $result]);
    }

    // ─── POST /lists ──────────────────────────────────────────────────────
    // Create a new list

    public function store(): void
    {
        $this->validateInput([
            'title' => 'required|min:1|max:60',
        ]);

        $tier = $this->requireListsAccess();
        $cap  = $this->getListCap($tier);

        if ($cap !== null && $this->model->countByUser($this->auto_id) >= $cap) {
            $this->sendJson(ResponseStatusEnum::LIMIT_EXCEEDED);
        }

        $title       = $this->payload['title'];
        $description = isset($this->payload['description'])
            ? substr(trim($this->payload['description']), 0, 200)
            : null;

        $existing = \QB::table('lists')
            ->where('user_id', $this->auto_id)
            ->where('title', $title)
            ->first();

        if ($existing) {
            $this->sendJson(ResponseStatusEnum::INVALID_INPUT, "You already have a list with that name");
        }

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

            $duplicate = \QB::table('lists')
                ->where('user_id', $this->auto_id)
                ->where('title', $fields['title'])
                ->whereNot('id', $id)
                ->first();

            if ($duplicate) {
                $this->sendJson(ResponseStatusEnum::INVALID_INPUT, "You already have a list with that name");
            }
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
    // ─── POST /lists/{id}/email ───────────────────────────────────────────
    // Export the list to the user's email

    public function emailExport(string $encryptedId): void
    {
        $tier = $this->requireListsAccess();

        $this->validateInput([
            'format'    => 'required|in:csv,json',
            'recipient' => 'required|in:account,custom',
            'email'     => 'required_if:recipient,custom|email',
        ]);

        $id   = (int) decrypt($encryptedId);
        $list = $this->model->findById($id);

        if (!$list) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, "List not found");
        }
        if ((int) $list->user_id !== $this->auto_id) {
            $this->sendJson(ResponseStatusEnum::UNAUTHORIZED);
        }

        $to = $this->payload['recipient'] === 'account'
            ? $this->email_id
            : trim($this->payload['email']);

        $rawItems = $this->model->getItems($id);
        $items = array_map(fn($item) => (array) $item, $rawItems);

        // Enrich with tier-gated podcast metadata
        $columns = \App\Enums\EntitlementEnum::META_COLUMNS[$tier] ?? [];
        if (!empty($columns)) {
            $matchKeys = array_values(array_filter(array_map(fn($i) => $i['match_key'] ?? '', $items)));
            if (!empty($matchKeys)) {
                $metaByKey = (new \App\Models\PodcastMetaModel())->getByMatchKeys($matchKeys, $columns);
                foreach ($items as &$item) {
                    $item = array_merge($item, $metaByKey[$item['match_key'] ?? ''] ?? []);
                }
                unset($item);
            }
        }

        $format = $this->payload['format'];
        $attachmentFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $list->title ?? 'List') . '.' . $format;
        $attachmentContent = '';
        $attachmentMime = '';

        if ($format === 'json') {
            $attachmentContent = json_encode($items, JSON_PRETTY_PRINT);
            $attachmentMime = 'application/json';
        } else {
            // Build CSV string in memory
            $fp = fopen('php://temp', 'r+');
            if (count($items) > 0) {
                $headers = array_keys($items[0]);
                fputcsv($fp, $headers);
                foreach ($items as $item) {
                    $row = [];
                    foreach ($headers as $header) {
                        $val = $item[$header] ?? '';
                        if (is_array($val) || is_object($val)) {
                            $val = json_encode($val);
                        }
                        $row[] = $val;
                    }
                    fputcsv($fp, $row);
                }
            }
            rewind($fp);
            $attachmentContent = stream_get_contents($fp);
            fclose($fp);
            $attachmentMime = 'text/csv';
        }

        // Build HTML Body using Views and CssToInlineStyles
        ob_start();
        include DOCUMENT_ROOT . 'App/Views/Emails/list_export.php';
        $rawHtml = ob_get_clean();
        
        $css = file_get_contents(DOCUMENT_ROOT . 'App/Views/Emails/list_export.css');
        $cssToInlineStyles = new \TijsVerkoyen\CssToInlineStyles\CssToInlineStyles();
        $htmlBody = $cssToInlineStyles->convert($rawHtml, $css);

        $htmlTitle = htmlspecialchars($list->title ?? 'List');

        $sent = \Library\Mailer::send($to, "Your exported list: {$htmlTitle}", $htmlBody, [
            [
                'filename' => $attachmentFilename,
                'content'  => $attachmentContent,
                'mime'     => $attachmentMime,
            ]
        ]);

        if (!$sent) {
            $this->sendJson(ResponseStatusEnum::BAD_REQUEST, "Failed to send email");
        }

        $this->sendJson(ResponseStatusEnum::SUCCESS, "List emailed");
    }
}
