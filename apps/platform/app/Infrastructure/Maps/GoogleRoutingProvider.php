<?php

namespace App\Infrastructure\Maps;

use App\Domain\Routing\ProviderFailure;
use App\Domain\Routing\RoutingProvider;

final class GoogleRoutingProvider implements RoutingProvider
{
    public function __construct(private readonly GoogleMapsTransport $transport) {}

    public function calculate(array $stops, ?array $depot = null): array
    {
        try {
            if (count($stops) < 2 || count($stops) > 10) {
                throw new ProviderFailure('invalid_route_stops');
            }
            foreach ([...$stops, ...($depot ? [$depot] : [])] as $stop) {
                if (! isset($stop['latitude'], $stop['longitude']) || ! is_numeric($stop['latitude']) || ! is_numeric($stop['longitude']) || abs((float) $stop['latitude']) > 90 || abs((float) $stop['longitude']) > 180) {
                    throw new ProviderFailure('missing_coordinates');
                }
            }
            $journey = $this->journey($stops);
            $depotJourney = $depot ? $this->journey([$depot, $stops[0]]) : null;
            $returnJourney = $depot ? $this->journey([$stops[array_key_last($stops)], $depot]) : null;

            return ['provider' => 'google', 'status' => 'available', 'failure_code' => null, 'customer_metres' => $journey['metres'], 'duration_seconds' => $journey['seconds'], 'depot_metres' => $depotJourney['metres'] ?? null, 'return_metres' => $returnJourney['metres'] ?? null, 'depot_duration_seconds' => $depotJourney['seconds'] ?? null, 'return_duration_seconds' => $returnJourney['seconds'] ?? null, 'tolls_minor' => null, 'retain_until' => now()->addHours(min(720, (int) config('maps.google.retention_hours')))->toIso8601String()];
        } catch (ProviderFailure $failure) {
            $this->transport->recordFailure($failure);

            return ['provider' => 'google', 'status' => 'unavailable', 'failure_code' => $failure->reason, 'customer_metres' => null, 'duration_seconds' => null, 'depot_metres' => null, 'return_metres' => null, 'tolls_minor' => null, 'retain_until' => null];
        }
    }

    private function journey(array $stops): array
    {
        $point = fn ($stop) => ['location' => ['latLng' => ['latitude' => (float) $stop['latitude'], 'longitude' => (float) $stop['longitude']]]];
        $body = $this->transport->request('POST', 'https://routes.googleapis.com/directions/v2:computeRoutes', ['origin' => $point($stops[0]), 'destination' => $point($stops[array_key_last($stops)]), 'intermediates' => array_map($point, array_slice($stops, 1, -1)), 'travelMode' => 'DRIVE', 'routingPreference' => 'TRAFFIC_UNAWARE', 'computeAlternativeRoutes' => false, 'optimizeWaypointOrder' => false], 'routes.distanceMeters,routes.duration');
        $route = $body['routes'][0] ?? null;
        if (! is_array($route) || ! is_int($route['distanceMeters'] ?? null) || $route['distanceMeters'] < 0 || $route['distanceMeters'] > 10000000 || ! is_string($route['duration'] ?? null) || ! preg_match('/^(\d+)(?:\.(\d+))?s$/D', $route['duration'], $duration) || (int) $duration[1] > 2592000) {
            throw new ProviderFailure('invalid_provider_response');
        }

        return ['metres' => $route['distanceMeters'], 'seconds' => (int) $duration[1] + (trim($duration[2] ?? '', '0') !== '' ? 1 : 0)];
    }
}
