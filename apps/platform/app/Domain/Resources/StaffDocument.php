<?php

namespace App\Domain\Resources;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 */
class StaffDocument extends Model
{
    use BelongsToTenant;

    protected $table = 'staff_documents';

    protected $fillable = ['staff_id', 'path', 'original_name', 'mime_type', 'size_bytes'];

    protected $hidden = ['id', 'tenant_id', 'path'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
