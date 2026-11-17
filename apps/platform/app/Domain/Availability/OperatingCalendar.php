<?php

namespace App\Domain\Availability;

use App\Domain\Resources\Resource;
use Carbon\CarbonImmutable;

interface OperatingCalendar
{
    public function windows(CarbonImmutable $from, CarbonImmutable $until, ?Resource $resource = null): array;
}
