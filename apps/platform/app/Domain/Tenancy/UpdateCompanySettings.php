<?php

namespace App\Domain\Tenancy;

use App\Domain\Notifications\OutboxMessage;
use App\Domain\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateCompanySettings
{
    public function execute(array $attributes, int $actorId): void
    {
        DB::transaction(function () use ($attributes, $actorId): void {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            if (isset($attributes['default_service_id']) && ! TenantService::whereKey($attributes['default_service_id'])->where('active', true)->where('enabled', true)->exists()) {
                throw ValidationException::withMessages(['default_service_public_id' => 'Select an active enabled company service.']);
            }
            $settings = CompanySettings::query()->lockForUpdate()->firstOrFail();
            $settings->update($attributes);
            AuditEntry::create(['actor_id' => $actorId, 'action' => 'company.settings.updated', 'subject_public_id' => $settings->public_id]);
            OutboxMessage::create(['event_type' => 'company.settings.updated', 'payload' => ['settings_id' => $settings->public_id]]);
        });
    }
}
