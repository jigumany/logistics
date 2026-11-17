<?php

namespace App\Domain\Fleet;

use App\Domain\Tenancy\BelongsToTenant;
use Database\Factories\DepotFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 */
class Depot extends Model
{
    use BelongsToTenant, \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected static function newFactory(): DepotFactory
    {
        return DepotFactory::new();
    }

    protected $table = 'depots';

    protected $fillable = ['name', 'address_line', 'suburb', 'city', 'postal_code', 'country_code', 'latitude', 'longitude', 'coordinate_source', 'coordinates_verified', 'contact_name', 'contact_email', 'contact_phone', 'is_primary', 'active', 'timezone'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'is_primary' => 'boolean', 'active' => 'boolean', 'coordinates_verified' => 'boolean'];
    }
}
