<?php

namespace App\Models;

use App\Enums\ConsentScope;
use App\Enums\ConsentSource;
use App\Enums\ConsentState as ConsentStateEnum;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only consent event — never updated (docs/02-data-model.md §4).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property ConsentScope $scope
 * @property ConsentStateEnum $state
 * @property ConsentSource $source
 * @property array<string, mixed> $evidence
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property-read Tenant $tenant
 * @property-read Contact $contact
 */
#[Fillable(['contact_id', 'scope', 'state', 'source', 'evidence', 'occurred_at'])]
class Consent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ConsentFactory> */
    use HasFactory;

    /**
     * The table only carries created_at.
     */
    const UPDATED_AT = null;

    /**
     * Get the contact this consent event belongs to.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => ConsentScope::class,
            'state' => ConsentStateEnum::class,
            'source' => ConsentSource::class,
            'evidence' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
