<?php

namespace App\Infrastructure\Maps;

use App\Domain\Routing\RoutingProvider;

final class SimulationRoutingProvider implements RoutingProvider
{
    public function __construct(private readonly int $metres, private readonly int $seconds) {}

    public function calculate(array $stops, ?array $depot = null): array
    {
        return ['provider' => 'internal_route_fixture', 'status' => 'available', 'failure_code' => null, 'customer_metres' => $this->metres, 'duration_seconds' => $this->seconds, 'depot_metres' => null, 'return_metres' => null, 'depot_duration_seconds' => null, 'return_duration_seconds' => null, 'tolls_minor' => 0, 'retain_until' => now()->addDay()->toIso8601String()];
    }
}
