<?php

namespace App\Domain\Geography;

use App\Domain\Tenancy\RecordsAdministrativeChange;
use Illuminate\Support\Facades\DB;

final class SaveServiceZone
{
    public function execute(array $data, int $actorId, ?ServiceZone $zone = null): ServiceZone
    {
        $data['boundary'] = app(PolygonBoundary::class)->validate($data['boundary']);

        return DB::transaction(function () use ($data, $actorId, $zone): ServiceZone {
            $zone ??= new ServiceZone;
            $zone->fill($data)->save();
            app(RecordsAdministrativeChange::class)->record($zone, 'service-zone.saved', $actorId);

            return $zone;
        });
    }
}
