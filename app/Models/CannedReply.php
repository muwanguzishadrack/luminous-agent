<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $shortcut
 * @property string $title
 * @property string $body
 * @property array<int, mixed> $variables
 * @property bool $is_shared
 * @property string|null $created_by
 * @property-read Tenant $tenant
 */
#[Fillable(['shortcut', 'title', 'body', 'variables', 'is_shared', 'created_by'])]
class CannedReply extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_shared' => 'boolean',
        ];
    }
}
