<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-contact click tracking for wrapped URL buttons (docs/02-data-model.md §7).
 *
 * @property int $id
 * @property string $team_id
 * @property string $campaign_id
 * @property string $contact_id
 * @property int $button_index
 * @property string $token
 * @property string $target_url
 * @property Carbon|null $clicked_at
 * @property int $click_count
 * @property string|null $user_agent
 * @property string|null $ip_hash
 * @property-read Team $team
 * @property-read Campaign $campaign
 * @property-read Contact $contact
 */
#[Fillable(['campaign_id', 'contact_id', 'button_index', 'token', 'target_url', 'clicked_at', 'click_count', 'user_agent', 'ip_hash'])]
class CampaignClick extends Model
{
    use BelongsToTeam;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the campaign this click row belongs to.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Get the contact who received the wrapped button.
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
            'clicked_at' => 'datetime',
        ];
    }
}
