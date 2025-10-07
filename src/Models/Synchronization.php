<?php

namespace Webkul\Google\Models;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;
use Illuminate\Support\Facades\DB;
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

    /*--------------------------------------------------------------
     | Core Google Sync Logic
     --------------------------------------------------------------*/

    public function ping(): mixed
    {
        try {
            if (! $this->synchronizable) {
                Log::error("Ping failed: No related synchronizable model for synchronization {$this->id}");
                return null;
            }

            $this->synchronizable->synchronize();
            return true;
        } catch (\Exception $e) {
            Log::error("Ping failed for synchronization {$this->id}: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    public function startListeningForChanges(): mixed
    {
        try {
            $this->synchronizable?->watch();
            return true;
        } catch (\Exception $e) {
            Log::error("startListeningForChanges failed for synchronization {$this->id}: {$e->getMessage()}");
            return null;
        }
    }

    public function stopListeningForChanges()
    {
        if (! $this->resource_id) return;

        try {
            $this->synchronizable
                ->getGoogleService('Calendar')
                ->channels->stop($this->asGoogleChannel());
        } catch (\Exception $e) {
            Log::error("stopListeningForChanges failed for synchronization {$this->id}: {$e->getMessage()}");
        }
    }

    public function synchronizable()
    {
        return $this->morphTo();
    }

    public function refreshWebhook(): self
    {
        try {
            $this->stopListeningForChanges();

            $this->id = Uuid::uuid4();
            $this->save();

            $this->startListeningForChanges();
        } catch (\Exception $e) {
            Log::error("refreshWebhook failed for synchronization {$this->id}: {$e->getMessage()}");
        }

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
            Log::error("getWebhookUri failed for synchronization {$this->id}: {$e->getMessage()}");
            return '';
        }
    }

    /*--------------------------------------------------------------
     | Meta Storage in Master DB
     --------------------------------------------------------------*/

    /**
     * Store or update the Google Calendar resource mapping in admin_user_tenants.meta
     */
    protected function storeResourceMeta(): void
    {
        try {
            $tenantDb = session('tenant_db');
            $user = auth()->guard('user')->user();

            if (! $user || ! $tenantDb) {
                Log::warning('Skipping meta update: missing user or tenant');
                return;
            }

            $masterUser = \Webkul\Master\Models\AdminUser::on('mysql')
                ->where('email', $user->email)
                ->first();

            if (! $masterUser) {
                Log::warning('Master user not found for meta update', ['email' => $user->email]);
                return;
            }

            // Get existing meta for this user + tenant
            $existingMeta = DB::connection('mysql')->table('admin_user_tenants')
                ->where('admin_user_id', $masterUser->id)
                ->where('tenant_db', $tenantDb)
                ->value('meta');

            $existingMeta = $existingMeta ? json_decode($existingMeta, true) : [];

            // Prepare the Google meta payload
            $googleMeta = [
                'google' => [
                    'calendar' => [
                        'resource_id'        => $this->resource_id,
                        'synchronization_id' => $this->id,
                        'expired_at'         => optional($this->expired_at)->toDateTimeString(),
                        'webhook_url'        => $this->getWebhookUri(),
                    ],
                ],
            ];

            // Merge new meta into existing
            $mergedMeta = $this->mergeMeta($existingMeta, $googleMeta);

            // Update or insert meta
            DB::connection('mysql')->table('admin_user_tenants')->updateOrInsert(
                [
                    'admin_user_id' => $masterUser->id,
                    'tenant_db'     => $tenantDb,
                ],
                [
                    'meta'       => json_encode($mergedMeta),
                    'updated_at' => now(),
                ]
            );

            Log::info('Stored Google resource_id in admin_user_tenants meta', [
                'admin_user_id' => $masterUser->id,
                'tenant_db'     => $tenantDb,
                'resource_id'   => $this->resource_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to store Google meta', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Recursively merge arrays
     */
    protected function mergeMeta(array $existing, array $new): array
    {
        foreach ($new as $key => $value) {
            if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
                $existing[$key] = $this->mergeMeta($existing[$key], $value);
            } else {
                $existing[$key] = $value;
            }
        }
        return $existing;
    }

    /*--------------------------------------------------------------
     | Eloquent Hooks
     --------------------------------------------------------------*/

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
                Log::error("Synchronization created hook failed for {$synchronization->id}: {$e->getMessage()}");
            }
        });

        static::saved(function ($synchronization) {
            if (! empty($synchronization->resource_id)) {
                $synchronization->storeResourceMeta();
            }
        });

        static::deleting(function ($synchronization) {
            try {
                $synchronization->stopListeningForChanges();
            } catch (\Exception $e) {
                Log::error("Deleting synchronization failed for {$synchronization->id}: {$e->getMessage()}");
            }
        });
    }
}
