<?php

namespace App\Domain\Resources;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DriverVehicleClass extends Model
{
    use BelongsToTenant;

    protected $fillable = ['staff_id', 'vehicle_class'];

    protected $hidden = ['id', 'tenant_id'];
}
