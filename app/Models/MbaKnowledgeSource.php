<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $mba_agent_id
 * @property string $kind
 * @property string|null $external_id
 * @property array<string, mixed> $payload
 * @property string|null $media_id
 * @property string|null $url
 * @property int|null $recrawl_interval_hours
 * @property string $sync_status
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 * @property int $version
 * @property-read Tenant $tenant
 * @property-read MbaAgent $mbaAgent
 * @property-read Media|null $media
 */
#[Fillable(['mba_agent_id', 'kind', 'external_id', 'payload', 'media_id', 'url', 'recrawl_interval_hours', 'sync_status', 'last_synced_at', 'last_error', 'version'])]
class MbaKnowledgeSource extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the agent this knowledge source feeds.
     *
     * @return BelongsTo<MbaAgent, $this>
     */
    public function mbaAgent(): BelongsTo
    {
        return $this->belongsTo(MbaAgent::class);
    }

    /**
     * Get the uploaded file backing a `file` source, if any.
     *
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
