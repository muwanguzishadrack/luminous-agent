<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Database\Factories\CannedReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $team_id
 * @property string $shortcut
 * @property string $title
 * @property string $body
 * @property array<int, mixed> $variables
 * @property bool $is_shared
 * @property string|null $created_by
 * @property-read Team $team
 */
#[Fillable(['shortcut', 'title', 'body', 'variables', 'is_shared', 'created_by'])]
class CannedReply extends Model
{
    use BelongsToTeam, HasUuids;

    /** @use HasFactory<CannedReplyFactory> */
    use HasFactory;

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
