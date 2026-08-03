<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Connector tool — no tenant_id column; tenancy flows through the connector
 * (docs/02-data-model.md §8).
 *
 * @property string $id
 * @property string $connector_id
 * @property string|null $external_id
 * @property string $name
 * @property string|null $description
 * @property string $method
 * @property string $path
 * @property array<string, mixed> $input_schema
 * @property array<string, mixed> $output_schema
 * @property bool $is_write
 * @property bool $enabled
 * @property-read MbaConnector $connector
 */
#[Fillable(['connector_id', 'external_id', 'name', 'description', 'method', 'path', 'input_schema', 'output_schema', 'is_write', 'enabled'])]
class MbaConnectorTool extends Model
{
    use HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the connector this tool belongs to.
     *
     * @return BelongsTo<MbaConnector, $this>
     */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(MbaConnector::class, 'connector_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'output_schema' => 'array',
            'is_write' => 'boolean',
            'enabled' => 'boolean',
        ];
    }
}
