<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per campaign+contact — the table that guarantees no double-send
 * (docs/02-data-model.md §7).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $campaign_id
 * @property string $contact_id
 * @property string|null $message_id
 * @property string|null $wamid
 * @property string $status
 * @property string|null $suppression_reason
 * @property int|null $error_code
 * @property int|null $cost_minor
 * @property array<string, mixed> $variables
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $clicked_at
 * @property Carbon|null $replied_at
 * @property Carbon|null $failed_at
 * @property-read Tenant $tenant
 * @property-read Campaign $campaign
 * @property-read Contact $contact
 * @property-read Message|null $message
 */
#[Fillable(['campaign_id', 'contact_id', 'message_id', 'wamid', 'status', 'suppression_reason', 'error_code', 'cost_minor', 'variables', 'queued_at', 'sent_at', 'delivered_at', 'read_at', 'clicked_at', 'replied_at', 'failed_at'])]
class CampaignRecipient extends Model
{
    use BelongsToTenant;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the campaign this recipient row belongs to.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Get the targeted contact.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the message that was sent to this recipient, if any.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'clicked_at' => 'datetime',
            'replied_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
