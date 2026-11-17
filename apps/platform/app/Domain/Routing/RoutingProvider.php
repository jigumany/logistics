<?php

namespace App\Domain\Routing;

interface RoutingProvider
{
    /** @return array{provider:string,status:'available'|'unavailable',failure_code:?string,customer_metres:?int,depot_metres:?int,return_metres:?int,duration_seconds:?int,tolls_minor:?int,retain_until:?string} */
    public function calculate(array $stops, ?array $depot = null): array;
}
