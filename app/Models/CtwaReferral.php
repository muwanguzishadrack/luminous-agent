<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Click-to-WhatsApp ad referral (docs/02-data-model.md §10).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property string $conversation_id
 * @property string $message_wamid
 * @property string|null $source_id
 * @property string|null $source_type
 * @property string|null $source_url
 * @property string|null $headline
 * @property string|null $body
 * @property string|null $media_type
 * @property string|null $image_url
 * @property string|null $video_url
 * @property string|null $thumbnail_url
 * @property string|null $ctwa_clid
 * @property array<string, mixed>|null $welcome_message
 * @property Carbon $occurred_at
 * @property-read Tenant $tenant
 * @property-read Contact $contact
 * @property-read Conversation $conversation
 */
#[Fillable(['contact_id', 'conversation_id', 'message_wamid', 'source_id', 'source_type', 'source_url', 'headline', 'body', 'media_type', 'image_url', 'video_url', 'thumbnail_url', 'ctwa_clid', 'welcome_message', 'occurred_at'])]
class CtwaReferral extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the contact who arrived from the ad.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the conversation the referral opened.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'welcome_message' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
