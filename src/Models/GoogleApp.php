<?php

namespace Webkul\Google\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Google\Contracts\GoogleApp as App;
use Illuminate\Support\Facades\Crypt;

class GoogleApp extends Model implements App
{
    protected $table = 'google_apps';

    protected $fillable = [
        'client_id',
        'client_secret',
        'redirect_uri',
        'webhook_uri',
        'scopes'
    ];

    protected $casts = [
        'scopes' => 'array',
    ];

    // Encrypt/decrypt client_secret automatically if desired
    public function setClientSecretAttribute($value)
    {
        $this->attributes['client_secret'] = encrypt($value);
    }

    public function getClientSecretAttribute($value)
    {
        return decrypt($value);
    }

    public function user()
    {
        return $this->belongsTo(\Webkul\User\Models\User::class);
    }
}
