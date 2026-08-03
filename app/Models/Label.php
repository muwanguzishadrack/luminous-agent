<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $color
 * @property string $kind
 * @property string|null $created_by
 * @property-read Tenant $tenant
 * @property-read Collection<int, Contact> $contacts
 * @property-read Collection<int, Conversation> $conversations
 */
#[Fillable(['name', 'color', 'kind', 'created_by'])]
class Label extends Model
{
    use BelongsToTenant, HasUuids;

    /** @use HasFactory<LabelFactory> */
    use HasFactory;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the contacts carrying this label.
     *
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_label');
    }

    /**
     * Get the conversations carrying this label.
     *
     * @return BelongsToMany<Conversation, $this>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_label');
    }
}
