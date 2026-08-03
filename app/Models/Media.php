<?php

namespace App\Models;

use App\Enums\MediaScanStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $meta_media_id
 * @property string $sha256
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $filename
 * @property string $disk
 * @property string $path
 * @property string|null $thumb_path
 * @property int|null $duration_ms
 * @property string|null $transcript
 * @property MediaScanStatus $scan_status
 * @property Carbon|null $meta_expires_at
 * @property-read Tenant $tenant
 * @property-read Collection<int, Message> $messages
 */
#[Fillable(['meta_media_id', 'sha256', 'mime_type', 'size_bytes', 'filename', 'disk', 'path', 'thumb_path', 'duration_ms', 'transcript', 'scan_status', 'meta_expires_at'])]
class Media extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'media';

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the messages that carry this media.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scan_status' => MediaScanStatus::class,
            'meta_expires_at' => 'datetime',
        ];
    }
}
