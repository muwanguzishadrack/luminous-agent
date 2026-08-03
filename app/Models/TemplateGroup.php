<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TemplateGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Logical multi-language template set — lets a campaign target a group and
 * resolve the right language per contact (docs/02-data-model.md §6).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $name
 * @property-read Tenant $tenant
 * @property-read Collection<int, Template> $templates
 * @property-read Collection<int, Campaign> $campaigns
 */
#[Fillable(['key', 'name'])]
class TemplateGroup extends Model
{
    use BelongsToTenant, HasUuids;

    /** @use HasFactory<TemplateGroupFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'template_group';

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the language variants in this group.
     *
     * @return HasMany<Template, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    /**
     * Get the campaigns targeting this group.
     *
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
