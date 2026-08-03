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
 * @property string $catalog_id
 * @property string $retailer_id
 * @property string|null $meta_product_id
 * @property string $name
 * @property string|null $description
 * @property int $price_minor
 * @property string $currency
 * @property string $availability
 * @property string|null $image_url
 * @property string|null $url
 * @property array<string, mixed> $attributes
 * @property string|null $sync_status
 * @property Carbon|null $last_synced_at
 * @property-read Tenant $tenant
 * @property-read Catalog $catalog
 */
#[Fillable(['catalog_id', 'retailer_id', 'meta_product_id', 'name', 'description', 'price_minor', 'currency', 'availability', 'image_url', 'url', 'attributes', 'sync_status', 'last_synced_at'])]
class Product extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the catalog this product belongs to.
     *
     * @return BelongsTo<Catalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
