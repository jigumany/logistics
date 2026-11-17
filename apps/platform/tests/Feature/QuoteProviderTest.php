<?php

use App\Domain\Routing\AddressVerification;
use App\Infrastructure\Maps\GoogleAddressProvider;
use App\Infrastructure\Maps\GoogleRoutingProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config(['maps.google.key' => 'not-a-live-key', 'maps.google.billing_confirmed' => true, 'maps.google.retention_permitted' => true, 'maps.google.retention_hours' => 72]);
    Http::preventStrayRequests();
});
function routeTestStops(): array
{
    return [['latitude' => -33.92, 'longitude' => 18.42], ['latitude' => -33.95, 'longitude' => 18.5]];
}
it('calculates ordered stops and separates approach return and customer distance', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::sequence()->push(['routes' => [['distanceMeters' => 20000, 'duration' => '1800s']]])->push(['routes' => [['distanceMeters' => 1000, 'duration' => '60s']]])->push(['routes' => [['distanceMeters' => 3000, 'duration' => '180s']]])]);
    $stops = routeTestStops();
    array_splice($stops, 1, 0, [['latitude' => -33.93, 'longitude' => 18.44]]);
    $route = app(GoogleRoutingProvider::class)->calculate($stops, ['latitude' => -33.9, 'longitude' => 18.4]);
    expect($route['customer_metres'])->toBe(20000)->and($route['depot_metres'])->toBe(1000)->and($route['return_metres'])->toBe(3000)->and($route['duration_seconds'])->toBe(1800)->and($route['tolls_minor'])->toBeNull();
    Http::assertSent(fn ($request) => count($request['intermediates']) === 1 && $request['optimizeWaypointOrder'] === false && $request['intermediates'][0]['location']['latLng']['longitude'] === 18.44);
    Http::assertSentCount(3);
});
it('bounds retries for provider rate limits', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response([], 429)]);
    $route = app(GoogleRoutingProvider::class)->calculate(routeTestStops());
    expect($route['status'])->toBe('unavailable')->and($route['failure_code'])->toBe('provider_rate_limited');
    Http::assertSentCount(2);
});
it('turns timeouts into a structured manual-review fallback', function (): void {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        throw new ConnectionException('Simulated timeout');
    });
    $route = app(GoogleRoutingProvider::class)->calculate(routeTestStops());
    expect($route['failure_code'])->toBe('provider_timeout')->and($route['customer_metres'])->toBeNull();
    expect($attempts)->toBe(2);
});
it('retries transient failures but validates the successful response', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::sequence()->push([], 503)->push(['routes' => [['distanceMeters' => -1, 'duration' => '30s']]])]);
    expect(app(GoogleRoutingProvider::class)->calculate(routeTestStops())['failure_code'])->toBe('invalid_provider_response');
    Http::assertSentCount(2);
});
it('does not call paid APIs without explicit configuration or coordinates', function (): void {
    config(['maps.google.key' => null]);
    expect(app(GoogleRoutingProvider::class)->calculate(routeTestStops())['failure_code'])->toBe('provider_not_configured');
    expect(app(GoogleRoutingProvider::class)->calculate([['address_line' => 'Manual address'], ['address_line' => 'Other address']])['failure_code'])->toBe('missing_coordinates');
    expect(app(GoogleAddressProvider::class)->search('Cape Town', '00000000-0000-4000-8000-000000000001')['available'])->toBeFalse();
    Http::assertNothingSent();
});
it('validates autocomplete and signs the selected address fields', function (): void {
    $prediction = ['placePrediction' => ['placeId' => 'place-a', 'text' => ['text' => 'Verified street, Cape Town']]];
    $selected = ['id' => 'place-a', 'formattedAddress' => '1 Verified Street, Cape Town', 'location' => ['latitude' => -33.92, 'longitude' => 18.42], 'addressComponents' => [['longText' => 'Cape Town', 'shortText' => 'Cape Town', 'types' => ['locality']], ['longText' => 'South Africa', 'shortText' => 'ZA', 'types' => ['country']]]];
    Http::fake(['places.googleapis.com/v1/places:autocomplete' => Http::response(['suggestions' => [$prediction]]), 'places.googleapis.com/v1/places/place-a*' => Http::response($selected)]);
    $provider = app(GoogleAddressProvider::class);
    expect($provider->search('Verified Street', '00000000-0000-4000-8000-000000000001')['predictions'][0]['place_id'])->toBe('place-a');
    $details = $provider->details('place-a', '00000000-0000-4000-8000-000000000001');
    $address = ['address_line' => $details['address_line'], 'city' => $details['city'], 'country_code' => $details['country_code'], 'latitude' => $details['latitude'], 'longitude' => $details['longitude'], 'provider_place_id' => $details['place_id'], 'verification_proof' => $details['verification_proof']];
    expect(app(AddressVerification::class)->resolve($address)['verification'])->toBe('provider');
    expect(fn () => app(AddressVerification::class)->resolve([...$address, 'latitude' => 0]))->toThrow(ValidationException::class);
    expect(fn () => app(AddressVerification::class)->resolve([...$address, 'address_line' => 'A different address']))->toThrow(ValidationException::class);
    $this->travel(31)->minutes();
    expect(fn () => app(AddressVerification::class)->resolve($address))->toThrow(ValidationException::class);
});
it('rejects malformed provider bodies without exposing provider errors', function (): void {
    Http::fake(['places.googleapis.com/*' => Http::response(['suggestions' => [['placePrediction' => ['placeId' => 42]]]])]);
    $result = app(GoogleAddressProvider::class)->search('Example', '00000000-0000-4000-8000-000000000001');
    expect($result['available'])->toBeFalse()->and($result['message'])->not->toContain('42', 'not-a-live-key');
});

it('rounds fractional provider durations to complete seconds without underestimating them', function (): void {
    Http::fake(['routes.googleapis.com/*' => Http::response(['routes' => [['distanceMeters' => 1000, 'duration' => '30.1s']]])]);
    expect(app(GoogleRoutingProvider::class)->calculate(routeTestStops())['duration_seconds'])->toBe(31);
    Http::assertSentCount(1);
});
