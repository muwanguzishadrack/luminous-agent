<?php

namespace App\Models;

use App\Enums\CampaignRouting;
use App\Enums\CampaignStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $phone_number_id
 * @property string $name
 * @property string|null $template_id
 * @property string|null $template_group_id
 * @property string|null $segment_id
 * @property CampaignRouting $routing
 * @property string|null $product_policy
 * @property CampaignStatus $status
 * @property Carbon|null $scheduled_for
 * @property string $timezone_mode
 * @property int|null $budget_cap_minor
 * @property int $spent_minor
 * @property string|null $variant_group_id
 * @property int|null $variant_weight
 * @property array<string, mixed> $stats
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read PhoneNumber $phoneNumber
 * @property-read Template|null $template
 * @property-read TemplateGroup|null $templateGroup
 * @property-read Segment|null $segment
 * @property-read Collection<int, CampaignRecipient> $recipients
 * @property-read Collection<int, CampaignClick> $clicks
 * @property-read Collection<int, Message> $messages
 */
#[Fillable(['phone_number_id', 'name', 'template_id', 'template_group_id', 'segment_id', 'routing', 'product_policy', 'status', 'scheduled_for', 'timezone_mode', 'budget_cap_minor', 'spent_minor', 'variant_group_id', 'variant_weight', 'stats', 'started_at', 'completed_at'])]
class Campaign extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the business phone number this campaign sends from.
     *
     * @return BelongsTo<PhoneNumber, $this>
     */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /**
     * Get the template this campaign sends, if targeting a single template.
     *
     * @return BelongsTo<Template, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Get the template group, if resolving language per contact.
     *
     * @return BelongsTo<TemplateGroup, $this>
     */
    public function templateGroup(): BelongsTo
    {
        return $this->belongsTo(TemplateGroup::class);
    }

    /**
     * Get the segment this campaign targets, if any.
     *
     * @return BelongsTo<Segment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /**
     * Get the per-contact recipient rows — the no-double-send guarantee.
     *
     * @return HasMany<CampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * Get the wrapped-URL click rows for this campaign.
     *
     * @return HasMany<CampaignClick, $this>
     */
    public function clicks(): HasMany
    {
        return $this->hasMany(CampaignClick::class);
    }

    /**
     * Get the messages sent by this campaign.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'routing' => CampaignRouting::class,
            'status' => CampaignStatus::class,
            'scheduled_for' => 'datetime',
            'stats' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
