<?php

namespace App\Domain\Services;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantService extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_services';

    protected $fillable = ['code', 'name', 'enabled', 'description', 'notice_minutes', 'restrictions', 'manual_approval', 'publicly_bookable', 'active', 'service_type_id'];

    protected $hidden = ['id', 'tenant_id', 'service_type_id'];

    protected static function booted(): void
    {
        static::creating(function (TenantService $service): void {
            $keys = ['household' => 'household_moving', 'office' => 'office_relocation', 'general' => 'goods_transport'];
            if (! $service->service_type_id) {
                $service->service_type_id = ServiceType::where('key', $keys[$service->code])->value('id');
            }
        });
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'manual_approval' => 'boolean', 'publicly_bookable' => 'boolean', 'active' => 'boolean', 'notice_minutes' => 'integer'];
    }
}
