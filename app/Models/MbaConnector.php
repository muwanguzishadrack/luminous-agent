<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $team_id
 * @property string $mba_agent_id
 * @property string|null $external_id
 * @property string $name
 * @property string $base_url
 * @property string $auth_scheme
 * @property string $token_id
 * @property bool $enabled
 * @property-read Team $team
 * @property-read MbaAgent $mbaAgent
 * @property-read ConnectorToken $token
 * @property-read Collection<int, MbaConnectorTool> $tools
 */
#[Fillable(['mba_agent_id', 'external_id', 'name', 'base_url', 'auth_scheme', 'token_id', 'enabled'])]
class MbaConnector extends Model
{
    use BelongsToTeam, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the agent this connector is exposed to.
     *
     * @return BelongsTo<MbaAgent, $this>
     */
    public function mbaAgent(): BelongsTo
    {
        return $this->belongsTo(MbaAgent::class);
    }

    /**
     * Get the bearer token Meta uses to call this connector.
     *
     * @return BelongsTo<ConnectorToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(ConnectorToken::class, 'token_id');
    }

    /**
     * Get the tools this connector exposes.
     *
     * @return HasMany<MbaConnectorTool, $this>
     */
    public function tools(): HasMany
    {
        return $this->hasMany(MbaConnectorTool::class, 'connector_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
