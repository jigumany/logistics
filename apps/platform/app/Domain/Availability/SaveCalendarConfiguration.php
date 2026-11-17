<?php

namespace App\Domain\Availability;

use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveCalendarConfiguration
{
    public function execute(string $model, array $data, int $actorId, ?Model $record = null): Model
    {
        return DB::transaction(function () use ($model, $data, $actorId, $record): Model {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            if ($model === ResourceBlock::class) {
                $data['starts_at'] = CarbonImmutable::parse($data['starts_at'])->utc();
                $data['ends_at'] = CarbonImmutable::parse($data['ends_at'])->utc();
            } else {
                $field = $model === WorkingSchedule::class ? 'weekday' : 'date';
                $query = $model::where('resource_id', $data['resource_id'] ?? null)->where($field, $data[$field]);
                if ($record) {
                    $query->whereKeyNot($record->getKey());
                }if ($query->exists()) {
                    throw ValidationException::withMessages([$field => 'A configuration already exists for this scope and day. Edit it instead.']);
                }
            }
            $record ??= new $model;
            $record->fill($data)->save();
            app(ProtectAllocatedCalendars::class)->assertValid();
            app(RecordsAdministrativeChange::class)->record($record, 'calendar.saved', $actorId);

            return $record;
        });
    }
}
