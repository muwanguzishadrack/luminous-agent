<?php

namespace App\Models;

use App\Enums\ConversationState;
use App\Models\Concerns\BelongsToTeam;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $phone_number_id
 * @property string $contact_id
 * @property ConversationState $state
 * @property string|null $owner_app_id
 * @property string|null $assigned_user_id
 * @property Carbon|null $assigned_at
 * @property Carbon|null $csw_expires_at
 * @property Carbon|null $fep_expires_at
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $last_outbound_at
 * @property int $unread_count
 * @property Carbon|null $first_response_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $sla_breached_at
 * @property int $ai_handled_count
 * @property int $human_handled_count
 * @property-read Team $team
 * @property-read PhoneNumber $phoneNumber
 * @property-read Contact $contact
 * @property-read User|null $assignedUser
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, ThreadControlEvent> $threadControlEvents
 * @property-read Collection<int, Note> $notes
 * @property-read Collection<int, Label> $labels
 */
#[Fillable(['phone_number_id', 'contact_id', 'state', 'owner_app_id', 'assigned_user_id', 'assigned_at', 'csw_expires_at', 'fep_expires_at', 'last_message_at', 'last_inbound_at', 'last_outbound_at', 'unread_count', 'first_response_at', 'resolved_at', 'snoozed_until', 'sla_breached_at', 'ai_handled_count', 'human_handled_count'])]
class Conversation extends Model
{
    use BelongsToTeam, HasUuids;

    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the business phone number this conversation runs on.
     *
     * @return BelongsTo<PhoneNumber, $this>
     */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /**
     * Get the contact on the other side of the conversation.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the user this conversation is assigned to, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Get the messages in this conversation.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get the append-only thread control (handover) events.
     *
     * @return HasMany<ThreadControlEvent, $this>
     */
    public function threadControlEvents(): HasMany
    {
        return $this->hasMany(ThreadControlEvent::class);
    }

    /**
     * Get the internal notes written in this conversation.
     *
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * Get the labels applied to this conversation.
     *
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'conversation_label');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ConversationState::class,
            'assigned_at' => 'datetime',
            'csw_expires_at' => 'datetime',
            'fep_expires_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'sla_breached_at' => 'datetime',
        ];
    }
}
