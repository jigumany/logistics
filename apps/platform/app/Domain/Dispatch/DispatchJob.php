<?php

namespace App\Domain\Dispatch;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $booking_id
 * @property int $booking_version
 * @property string $status
 * @property int $lock_version
 * @property array $resource_snapshot
 * @property array $work_snapshot
 */
class DispatchJob extends Model
{
    use BelongsToTenant;

    protected $table = 'dispatch_jobs';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['resource_snapshot' => 'array', 'work_snapshot' => 'array', 'completed_at' => 'immutable_datetime', 'booking_version' => 'integer', 'lock_version' => 'integer'];
    }

    public function scopeAssignedTo(Builder $query, array $staffIds): Builder
    {
        return $query->where(function ($q) use ($staffIds) {
            $q->whereRaw('1=0');
            foreach ($staffIds as $id) {
                if ($this->getConnection()->getDriverName() === 'sqlite') {
                    $q->orWhereRaw("EXISTS (SELECT 1 FROM json_each(dispatch_jobs.resource_snapshot) AS assigned_driver WHERE json_extract(assigned_driver.value, '$.kind') = 'driver' AND json_extract(assigned_driver.value, '$.staff_public_id') = ?)", [$id]);
                } else {
                    $q->orWhereJsonContains('resource_snapshot', [['kind' => 'driver', 'staff_public_id' => $id]]);
                }
            }
        });
    }
}
