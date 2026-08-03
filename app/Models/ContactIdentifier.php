<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Handles BSUIDs and identity changes without losing history
 * (docs/02-data-model.md §4).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property string $kind
 * @property string $value
 * @property bool $is_primary
 * @property Carbon|null $verified_at
 * @property Carbon|null $retired_at
 * @property-read Tenant $tenant
 * @property-read Contact $contact
 */
#[Fillable(['contact_id', 'kind', 'value', 'is_primary', 'verified_at', 'retired_at'])]
class ContactIdentifier extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the contact this identifier belongs to.
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
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }
}
