<?php

namespace Webkul\Google\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Webkul\Google\Contracts\Synchronization as SynchronizationContract;

class Synchronization extends Model implements SynchronizationContract
{
    protected $connection = 'tenant'; 
    protected $table = 'google_synchronizations';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'token',
        'last_synchronized_at',
        'resource_id',
        'expired_at',
    ];

    protected $casts = [
        'last_synchronized_at' => 'datetime',
        'expired_at'           => 'datetime',
    ];

    public function ping(): mixed
    {
        return $this->synchronizable?->synchronize();
    }

    public function startListeningForChanges(): mixed
    {
        return $this->synchronizable?->watch();
    }

    public function stopListeningForChanges()
    {
        if (! $this->resource_id) return;

        try {
            $this->synchronizable
                ->getGoogleService('Calendar')
                ->channels->stop($this->asGoogleChannel());
        } catch (\Exception $e) {
            Log::error("StopListeningForChanges failed: {$e->getMessage()}", [
                'synchronization_id' => $this->id
            ]);
        }
    }

    public function synchronizable()
    {
        return $this->morphTo();
    }

    public function refreshWebhook(): self
    {
        $this->stopListeningForChanges();

        $this->id = Uuid::uuid4();
        $this->save();

        $this->startListeningForChanges();

        return $this;
    }

    public function asGoogleChannel(): mixed
    {
        return tap(new \Google_Service_Calendar_Channel, function ($channel) {
            $channel->setId($this->id);
            $channel->setResourceId($this->resource_id);
            $channel->setType('web_hook');
            $channel->setAddress($this->getWebhookUri());
        });
    }

    protected function getWebhookUri(): string
    {
        try {
            $googleApp = \Webkul\Google\Models\GoogleApp::first();
            return $googleApp->webhook_uri ?? '';
        } catch (\Exception $e) {
            Log::error("getWebhookUri failed: {$e->getMessage()}", [
                'synchronization_id' => $this->id
            ]);
            return '';
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($synchronization) {
            $synchronization->id = Uuid::uuid4();
            $synchronization->last_synchronized_at = now();
        });

        static::created(function ($synchronization) {
            try {
                $synchronization->startListeningForChanges();
                $synchronization->ping();
            } catch (\Exception $e) {
                Log::error("Synchronization created hook failed: {$e->getMessage()}", [
                    'synchronization_id' => $synchronization->id
                ]);
            }
        });

        static::deleting(function ($synchronization) {
            $synchronization->stopListeningForChanges();
        });
    }
}
