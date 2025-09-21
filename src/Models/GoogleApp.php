<?php

namespace Webkul\Google\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Webkul\Google\Contracts\GoogleApp as GoogleAppContract;

class GoogleApp extends Model implements GoogleAppContract
{
    protected $connection = 'tenant';
    protected $table = 'google_apps';

    protected $fillable = [
        'client_id',
        'client_secret',
        'redirect_uri',
        'webhook_uri',
        'scopes',
    ];

    protected $casts = [
        'scopes' => 'array',
    ];

    /**
     * Encrypt before saving.
     */
    public function setClientSecretAttribute(string $value): void
    {
        $this->attributes['client_secret'] = Crypt::encryptString($value);
    }

    /**
     * Decrypt when retrieving.
     */
    public function getClientSecretAttribute($value): string
    {
        return Crypt::decryptString($value);
    }
}
