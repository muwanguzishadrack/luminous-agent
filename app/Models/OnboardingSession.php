<?php

namespace App\Models;

use Database\Factories\OnboardingSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $team_id
 * @property string $nonce
 * @property string|null $feature_type
 * @property string $es_version
 * @property array<int, mixed> $events
 * @property string|null $waba_id
 * @property string|null $phone_number_id
 * @property Carbon|null $code_exchanged_at
 * @property Carbon|null $history_sync_requested_at
 * @property Carbon|null $history_sync_completed_at
 * @property Carbon|null $contacts_sync_requested_at
 * @property string $status
 * @property array<string, mixed>|null $failure
 * @property-read Team|null $team
 */
#[Fillable(['nonce', 'feature_type', 'es_version', 'events', 'waba_id', 'phone_number_id', 'code_exchanged_at', 'history_sync_requested_at', 'history_sync_completed_at', 'contacts_sync_requested_at', 'status', 'failure'])]
class OnboardingSession extends Model
{
    /** @use HasFactory<OnboardingSessionFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'code_exchanged_at' => 'datetime',
            'history_sync_requested_at' => 'datetime',
            'history_sync_completed_at' => 'datetime',
            'contacts_sync_requested_at' => 'datetime',
            'failure' => 'array',
        ];
    }

    /**
     * Nullable by design: rows are created platform-level before any team
     * context exists (docs/02 §3) — hence no BelongsToTeam trait.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
