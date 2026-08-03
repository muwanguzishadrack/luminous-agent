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
 * @property array<string, mixed> $request
 * @property array<string, mixed> $result
 * @property string|null $score
 * @property Carbon $run_at
 * @property string|null $run_by
 * @property-read Tenant $tenant
 * @property-read MbaAgent $mbaAgent
 */
#[Fillable(['mba_agent_id', 'kind', 'request', 'result', 'score', 'run_at', 'run_by'])]
class MbaEval extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the agent this run was executed against.
     *
     * @return BelongsTo<MbaAgent, $this>
     */
    public function mbaAgent(): BelongsTo
    {
        return $this->belongsTo(MbaAgent::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request' => 'array',
            'result' => 'array',
            'score' => 'decimal:4',
            'run_at' => 'datetime',
        ];
    }
}
