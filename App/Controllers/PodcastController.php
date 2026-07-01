<?php

namespace App\Controllers;

use App\Enums\ResponseStatusEnum;
use App\Models\PodcastMetaModel;
use App\Models\UserModel;

class PodcastController extends Controller
{
    private PodcastMetaModel $model;

    public function __construct($vars = [])
    {
        parent::__construct($vars);

        $this->resolveUserIfPresent();

        $this->model = new PodcastMetaModel();
    }

    // GET /podcasts/meta?match_key=the+daily
    public function meta(): void
    {
        if (!$this->auto_id) {
            $this->sendJson(ResponseStatusEnum::UPGRADE);
        }

        $user    = (new UserModel())->findById($this->auto_id);
        $tier    = $this->resolveTier($user);
        $columns = $this->getMetaColumns($tier);

        if (empty($columns)) {
            $this->sendJson(ResponseStatusEnum::UPGRADE);
        }

        $this->validateInput([
            'match_key' => 'required|min:1|max:255',
        ]);
        $matchKey = trim($this->payload['match_key']);

        $meta = $this->model->getByMatchKey($matchKey, $columns);

        if (!$meta) {
            $this->sendJson(ResponseStatusEnum::NO_DATA_FOUND, 'No metadata found');
        }

        $this->sendJson(ResponseStatusEnum::SUCCESS, '', ['meta' => $meta]);
    }
}
