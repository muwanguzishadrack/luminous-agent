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
 * @property string $contact_id
 * @property string|null $conversation_id
 * @property string $user_id
 * @property string $body
 * @property array<int, mixed> $mentions
 * @property Carbon|null $created_at
 * @property-read Tenant $tenant
 * @property-read Contact $contact
 * @property-read Conversation|null $conversation
 * @property-read User $user
 */
#[Fillable(['contact_id', 'conversation_id', 'user_id', 'body', 'mentions'])]
class Note extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table only carries created_at.
     */
    const UPDATED_AT = null;

    /**
     * Get the contact this note is about.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the conversation this note was written in, if any.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the user who wrote the note.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mentions' => 'array',
        ];
    }
}
