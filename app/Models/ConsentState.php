<?php

namespace App\Models;

use App\Enums\ConsentScope;
use App\Enums\ConsentSource;
use App\Enums\ConsentState as ConsentStateEnum;
use App\Models\Concerns\BelongsToTeam;
use Database\Factories\ConsentStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Materialised consent read model, one row per contact+scope — the table the
 * send guard reads (docs/02-data-model.md §4).
 *
 * @property string $id
 * @property string $team_id
 * @property string $contact_id
 * @property ConsentScope $scope
 * @property ConsentStateEnum $state
 * @property ConsentSource $source
 * @property Carbon $occurred_at
 * @property int $consent_id
 * @property-read Team $team
 * @property-read Contact $contact
 * @property-read Consent $consent
 */
#[Fillable(['contact_id', 'scope', 'state', 'source', 'occurred_at', 'consent_id'])]
class ConsentState extends Model
{
    use BelongsToTeam, HasUuids;

    /** @use HasFactory<ConsentStateFactory> */
    use HasFactory;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the contact this state row belongs to.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the consent event that produced this state.
     *
     * @return BelongsTo<Consent, $this>
     */
    public function consent(): BelongsTo
    {
        return $this->belongsTo(Consent::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => ConsentScope::class,
            'state' => ConsentStateEnum::class,
            'source' => ConsentSource::class,
            'occurred_at' => 'datetime',
        ];
    }
}
