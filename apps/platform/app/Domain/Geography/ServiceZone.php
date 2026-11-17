<?php

namespace App\Domain\Geography;

use App\Domain\Tenancy\BelongsToTenant;
use Database\Factories\ServiceZoneFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property array{type: string, coordinates: array} $boundary
 */
class ServiceZone extends Model
{
    use BelongsToTenant, \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected static function newFactory(): ServiceZoneFactory
    {
        return ServiceZoneFactory::new();
    }

    protected $table = 'service_zones';

    protected $fillable = ['name', 'description', 'zone_type', 'boundary', 'active', 'priority', 'restrictions', 'manual_review', 'example_only'];

    protected $hidden = ['id', 'tenant_id', 'geometry'];

    protected function casts(): array
    {
        return ['boundary' => 'array', 'active' => 'boolean', 'priority' => 'integer', 'manual_review' => 'boolean', 'example_only' => 'boolean'];
    }
}
