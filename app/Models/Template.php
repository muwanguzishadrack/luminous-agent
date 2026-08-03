<?php

namespace App\Models;

use App\Enums\TemplateCategory;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $waba_account_id
 * @property string|null $template_group_id
 * @property string|null $meta_template_id
 * @property string $name
 * @property string $language
 * @property TemplateCategory $category
 * @property string|null $sub_type
 * @property string $status
 * @property string|null $quality_score
 * @property string|null $rejected_reason
 * @property array<string, mixed> $components
 * @property array<string, mixed> $variable_map
 * @property int|null $ttl_seconds
 * @property string|null $library_template_name
 * @property Carbon|null $paused_until
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read WabaAccount $wabaAccount
 * @property-read TemplateGroup|null $templateGroup
 * @property-read Collection<int, TemplateEvent> $events
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, Campaign> $campaigns
 */
#[Fillable(['waba_account_id', 'template_group_id', 'meta_template_id', 'name', 'language', 'category', 'sub_type', 'status', 'quality_score', 'rejected_reason', 'components', 'variable_map', 'ttl_seconds', 'library_template_name', 'paused_until', 'last_synced_at'])]
class Template extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the WABA this template is registered under.
     *
     * @return BelongsTo<WabaAccount, $this>
     */
    public function wabaAccount(): BelongsTo
    {
        return $this->belongsTo(WabaAccount::class);
    }

    /**
     * Get the multi-language group this template belongs to, if any.
     *
     * @return BelongsTo<TemplateGroup, $this>
     */
    public function templateGroup(): BelongsTo
    {
        return $this->belongsTo(TemplateGroup::class);
    }

    /**
     * Get the append-only status/quality/category events for this template.
     *
     * @return HasMany<TemplateEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TemplateEvent::class);
    }

    /**
     * Get the messages rendered from this template.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get the campaigns using this template.
     *
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TemplateCategory::class,
            'components' => 'array',
            'variable_map' => 'array',
            'paused_until' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
