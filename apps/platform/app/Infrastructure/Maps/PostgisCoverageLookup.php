<?php

namespace App\Infrastructure\Maps;

use App\Domain\Geography\CoverageLookup;
use App\Domain\Geography\ServiceZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class PostgisCoverageLookup implements CoverageLookup
{
    public function check(float $latitude, float $longitude, string $purpose = 'pickup'): array
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180 || ! in_array($purpose, ['pickup', 'delivery'], true)) {
            throw new InvalidArgumentException('Invalid location or coverage purpose.');
        }
        if (DB::getDriverName() !== 'pgsql') {
            throw new LogicException('Coverage checks require PostgreSQL with PostGIS.');
        }
        $zone = ServiceZone::query()->where('active', true)->where('example_only', false)->whereIn('zone_type', [$purpose, 'both'])->whereRaw('ST_Covers(geometry, ST_SetSRID(ST_MakePoint(?,?),4326))', [$longitude, $latitude])->orderByDesc('priority')->orderBy('id')->first();

        return ['covered' => $zone !== null, 'zone' => $zone?->only('public_id', 'name', 'priority', 'manual_review', 'restrictions'), 'coordinate_verification_required' => true];
    }
}
