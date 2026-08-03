<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\MessageStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string|null $wamid
 * @property MessageDirection $direction
 * @property string $type
 * @property string|null $body
 * @property array<string, mixed> $payload
 * @property string|null $media_id
 * @property string|null $replied_to_wamid
 * @property string|null $reaction_to_wamid
 * @property MessageOrigin $origin
 * @property string|null $sent_by_user_id
 * @property string|null $campaign_id
 * @property string|null $template_id
 * @property MessageStatus $status
 * @property int|null $error_code
 * @property array<string, mixed>|null $error_detail
 * @property string|null $pricing_category
 * @property bool|null $billable
 * @property int|null $cost_minor
 * @property int|null $token_count
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $failed_at
 * @property Carbon $occurred_at
 * @property-read Tenant $tenant
 * @property-read Conversation $conversation
 * @property-read Media|null $media
 * @property-read User|null $sentBy
 * @property-read Campaign|null $campaign
 * @property-read Template|null $template
 * @property-read Collection<int, MessageEvent> $events
 */
#[Fillable(['conversation_id', 'wamid', 'direction', 'type', 'body', 'payload', 'media_id', 'replied_to_wamid', 'reaction_to_wamid', 'origin', 'sent_by_user_id', 'campaign_id', 'template_id', 'status', 'error_code', 'error_detail', 'pricing_category', 'billable', 'cost_minor', 'token_count', 'sent_at', 'delivered_at', 'read_at', 'failed_at', 'occurred_at'])]
class Message extends Model
{
    use BelongsToTenant, HasUuids;

    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the conversation this message belongs to.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the media attached to this message, if any.
     *
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * Get the user who sent this message, if a human agent sent it.
     *
     * @return BelongsTo<User, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    /**
     * Get the campaign this message was sent by, if any.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Get the template this message was rendered from, if any.
     *
     * @return BelongsTo<Template, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Get the append-only status ladder for this message.
     *
     * @return HasMany<MessageEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MessageEvent::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'payload' => 'array',
            'origin' => MessageOrigin::class,
            'status' => MessageStatus::class,
            'error_detail' => 'array',
            'billable' => 'boolean',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }
}
