<?php

namespace App\Domain\Availability;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class ResourceBlock extends Model
{
    use BelongsToTenant;

    protected $table = 'resource_blocks';

    protected $fillable = ['resource_id', 'starts_at', 'ends_at', 'reason', 'notes'];

    protected $hidden = ['id', 'tenant_id'];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Resources\Resource::class);
    }

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
