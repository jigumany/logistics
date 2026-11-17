<?php

namespace App\Domain\Services;

use App\Domain\Notifications\OutboxMessage;
use App\Domain\Tenancy\AuditEntry;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateServiceType
{
    public function execute(string $publicId, array $attributes, int $actorId): void
    {
        DB::transaction(function () use ($publicId, $attributes, $actorId): void {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            $service = TenantService::where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if ((isset($attributes['enabled']) && ! $attributes['enabled']) || (isset($attributes['active']) && ! $attributes['active'])) {
                if (CompanySettings::where('default_service_id', $service->id)->exists()) {
                    throw ValidationException::withMessages(['enabled' => 'Choose another default service before disabling this one.']);
                }
            }
            $service->update($attributes);
            app(TenantServiceCatalogue::class)->invalidateAfterCommit();
            AuditEntry::create(['actor_id' => $actorId, 'action' => 'service.updated', 'subject_public_id' => $service->public_id]);
            OutboxMessage::create(['event_type' => 'service.updated', 'payload' => ['service_id' => $service->public_id, 'enabled' => $service->enabled]]);
        });
    }
}
