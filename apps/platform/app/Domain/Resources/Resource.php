<?php

namespace App\Domain\Resources;

use App\Domain\Fleet\Vehicle;
use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property-read Vehicle|null $vehicle
 * @property-read StaffMember|null $staff
 * @property-read string $label
 */
class Resource extends Model
{
    use BelongsToTenant;

    protected $table = 'resources';

    protected $fillable = ['kind', 'vehicle_id', 'staff_id', 'active'];

    protected $hidden = ['id', 'tenant_id'];

    public function getLabelAttribute(): string
    {
        return $this->vehicle?->getAttribute('name') ?? $this->staff?->getAttribute('full_name') ?? $this->public_id;
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }
}
