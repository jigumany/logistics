<?php

namespace App\Domain\Fleet;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property bool $capacity_verified
 * @property CarbonImmutable|null $capacity_verified_at
 * @property string|null $capacity_verification_note
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $usable_volume_m3
 * @property string $payload_kg
 * @property string $cargo_length_m
 * @property string $cargo_width_m
 * @property string $cargo_height_m
 * @property-read Depot|null $depot
 * @property-read VehicleType $type
 */
class Vehicle extends Model
{
    use BelongsToTenant, \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected static function newFactory(): VehicleFactory
    {
        return VehicleFactory::new();
    }

    protected $table = 'vehicles';

    protected $fillable = ['capacity_verified', 'capacity_verified_at', 'capacity_verification_note', 'name', 'registration_number', 'vehicle_type_id', 'make', 'model', 'manufacture_year', 'status', 'depot_id', 'usable_volume_m3', 'payload_kg', 'cargo_length_m', 'cargo_width_m', 'cargo_height_m', 'max_item_length_m', 'max_item_width_m', 'max_item_height_m', 'capacity_margin_basis_points', 'operating_restrictions', 'notes'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['capacity_verified' => 'boolean', 'capacity_verified_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime', 'capacity_margin_basis_points' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Vehicle $vehicle) => $vehicle->uuid ??= (string) Str::uuid());
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }
}
