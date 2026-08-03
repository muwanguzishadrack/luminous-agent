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
 * @property string $sequence_id
 * @property string $contact_id
 * @property string|null $current_step_id
 * @property string $status
 * @property Carbon $enrolled_at
 * @property Carbon|null $next_run_at
 * @property Carbon|null $exited_at
 * @property string|null $exit_reason
 * @property-read Tenant $tenant
 * @property-read Sequence $sequence
 * @property-read Contact $contact
 * @property-read SequenceStep|null $currentStep
 */
#[Fillable(['sequence_id', 'contact_id', 'current_step_id', 'status', 'enrolled_at', 'next_run_at', 'exited_at', 'exit_reason'])]
class SequenceEnrollment extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the sequence this enrollment belongs to.
     *
     * @return BelongsTo<Sequence, $this>
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    /**
     * Get the enrolled contact.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the step the enrollment currently sits on, if any.
     *
     * @return BelongsTo<SequenceStep, $this>
     */
    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class, 'current_step_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'next_run_at' => 'datetime',
            'exited_at' => 'datetime',
        ];
    }
}
