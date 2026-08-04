<?php

namespace App\Models;

use App\Enums\UsageBasis;
use App\Enums\UsageMeter as UsageMeterEnum;
use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only usage row — the billing source of truth; rows are never
 * re-marked in place, D-012 (docs/02-data-model.md §11).
 *
 * @property int $id
 * @property string $team_id
 * @property string|null $waba_account_id
 * @property string|null $phone_number_id
 * @property UsageMeterEnum $meter
 * @property string|null $category
 * @property string|null $country
 * @property int $quantity
 * @property int|null $unit_cost_micros
 * @property int $cost_minor
 * @property int $markup_minor
 * @property string $currency
 * @property string $source
 * @property UsageBasis $basis
 * @property Carbon $occurred_on
 * @property string|null $message_id
 * @property string|null $campaign_id
 * @property Carbon|null $created_at
 * @property-read Team $team
 * @property-read WabaAccount|null $wabaAccount
 * @property-read PhoneNumber|null $phoneNumber
 * @property-read Message|null $message
 * @property-read Campaign|null $campaign
 */
#[Fillable(['waba_account_id', 'phone_number_id', 'meter', 'category', 'country', 'quantity', 'unit_cost_micros', 'cost_minor', 'markup_minor', 'currency', 'source', 'basis', 'occurred_on', 'message_id', 'campaign_id'])]
class UsageMeter extends Model
{
    use BelongsToTeam;

    /**
     * The table only carries created_at.
     */
    const UPDATED_AT = null;

    /**
     * Get the WABA this usage was metered against, if any.
     *
     * @return BelongsTo<WabaAccount, $this>
     */
    public function wabaAccount(): BelongsTo
    {
        return $this->belongsTo(WabaAccount::class);
    }

    /**
     * Get the phone number this usage was metered against, if any.
     *
     * @return BelongsTo<PhoneNumber, $this>
     */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /**
     * Get the message this row traces back to, if any.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Get the campaign this row traces back to, if any.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meter' => UsageMeterEnum::class,
            'basis' => UsageBasis::class,
            'occurred_on' => 'date',
        ];
    }
}
