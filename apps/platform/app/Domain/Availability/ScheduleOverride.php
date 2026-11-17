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
 * @property CarbonImmutable $date
 */
class ScheduleOverride extends Model
{
    use BelongsToTenant;

    protected $table = 'schedule_overrides';

    protected $fillable = ['resource_id', 'date', 'closed', 'opens_at', 'closes_at', 'ends_next_day', 'timezone', 'reason'];

    protected $hidden = ['id', 'tenant_id'];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Resources\Resource::class);
    }

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'closed' => 'boolean', 'ends_next_day' => 'boolean'];
    }
}
