<?php

namespace App\Domain\Availability;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 */
class WorkingSchedule extends Model
{
    use BelongsToTenant;

    protected $table = 'working_schedules';

    protected $fillable = ['resource_id', 'weekday', 'opens_at', 'closes_at', 'ends_next_day', 'timezone', 'active'];

    protected $hidden = ['id', 'tenant_id'];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Resources\Resource::class);
    }

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'active' => 'boolean', 'ends_next_day' => 'boolean'];
    }
}
