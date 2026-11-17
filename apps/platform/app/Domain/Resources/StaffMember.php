<?php

namespace App\Domain\Resources;

use App\Domain\Fleet\Depot;
use App\Domain\Tenancy\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\StaffMemberFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property CarbonImmutable|null $licence_expires_on
 * @property-read Depot|null $depot
 * @property-read User|null $user
 * @property-read Collection<int, DriverVehicleClass> $eligibleClasses
 */
class StaffMember extends Model
{
    use BelongsToTenant, \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected static function newFactory(): StaffMemberFactory
    {
        return StaffMemberFactory::new();
    }

    protected $table = 'staff_members';

    protected $fillable = ['full_name', 'email', 'phone', 'role', 'engagement_status', 'active', 'depot_id', 'user_id', 'licence_category', 'licence_expires_on', 'qualifications', 'notes'];

    protected $hidden = ['id', 'tenant_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'licence_expires_on' => 'immutable_date', 'archived_at' => 'immutable_datetime'];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function eligibleClasses(): HasMany
    {
        return $this->hasMany(DriverVehicleClass::class, 'staff_id');
    }
}
