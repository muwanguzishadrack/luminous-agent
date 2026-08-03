<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Versioned Meta rate card — global, not tenant-scoped; rows are picked by
 * effective date and never retroactively repriced (docs/02-data-model.md §11).
 *
 * @property string $id
 * @property Carbon $effective_from
 * @property string $region
 * @property string $category
 * @property int|null $tier_min
 * @property int|null $tier_max
 * @property int $unit_cost_micros
 * @property string $source_url
 * @property Carbon|null $created_at
 */
#[Fillable(['effective_from', 'region', 'category', 'tier_min', 'tier_max', 'unit_cost_micros', 'source_url'])]
class RateCard extends Model
{
    use HasUuids;

    /**
     * The table only carries created_at.
     */
    const UPDATED_AT = null;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'rate_cards';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
        ];
    }
}
