<?php

namespace Webkul\Google\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Google\Models\GoogleApp;

class GoogleAppRepository extends Repository
{
    /**
     * Specify Model class name
     */
    public function model()
    {
        return \Webkul\Google\Models\GoogleApp::class;
    }
}
