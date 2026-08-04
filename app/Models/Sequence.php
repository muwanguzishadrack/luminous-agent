<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Drip journey (docs/02-data-model.md §7).
 *
 * @property string $id
 * @property string $team_id
 * @property string $name
 * @property string $status
 * @property array<string, mixed> $settings
 * @property-read Team $team
 * @property-read Collection<int, SequenceStep> $steps
 * @property-read Collection<int, SequenceEnrollment> $enrollments
 */
#[Fillable(['name', 'status', 'settings'])]
class Sequence extends Model
{
    use BelongsToTeam, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the ordered steps of this sequence.
     *
     * @return HasMany<SequenceStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class);
    }

    /**
     * Get the contact enrollments in this sequence.
     *
     * @return HasMany<SequenceEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(SequenceEnrollment::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
