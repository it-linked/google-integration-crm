<?php

namespace Webkul\Google\Repositories;

use Webkul\Core\Eloquent\Repository;

class GoogleAppRepository extends Repository
{
    /**
     * Specify Model class name
     *
     * @return string
     */
    public function model()
    {
        return 'Webkul\Google\Contracts\GoogleApp';
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
        // Ensure 'user_id' is always set explicitly
        $data['user_id'] = $userId;

        return $this->model->updateOrCreate(
            ['user_id' => $userId],
            $data
        );
    }
}
