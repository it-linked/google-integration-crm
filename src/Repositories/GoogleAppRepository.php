<?php

namespace Webkul\Google\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Google\Models\GoogleApp;

class GoogleAppRepository extends Repository
{
    /**
     * Specify Model class name
     *
     * @return string
     */
    public function model()
    {
        return GoogleApp::class;  // ← Use the actual model, not the contract
    }

    /**
     * Get the Google App for a specific user
     */
    public function findByUserId(int $userId)
    {
        return $this->model
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Upsert (create or update) credentials for a user
     */
    public function upsertForUser(int $userId, array $data)
    {
        $data['user_id'] = $userId;

        return $this->model->updateOrCreate(
            ['user_id' => $userId],
            $data
        );
    }
}

