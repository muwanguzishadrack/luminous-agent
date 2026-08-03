<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only template lifecycle events from the Meta webhooks
 * (docs/02-data-model.md §6).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $template_id
 * @property string $event
 * @property string|null $from
 * @property string|null $to
 * @property string|null $reason
 * @property array<string, mixed> $payload
 * @property Carbon $occurred_at
 * @property-read Tenant $tenant
 * @property-read Template $template
 */
#[Fillable(['template_id', 'event', 'from', 'to', 'reason', 'payload', 'occurred_at'])]
class TemplateEvent extends Model
{
    use BelongsToTenant;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the template this event belongs to.
     *
     * @return BelongsTo<Template, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
