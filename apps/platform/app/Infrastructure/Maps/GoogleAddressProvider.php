<?php

namespace App\Infrastructure\Maps;

use App\Domain\Routing\AddressProvider;
use App\Domain\Routing\ProviderFailure;
use Illuminate\Support\Facades\Crypt;

final class GoogleAddressProvider implements AddressProvider
{
    public function __construct(private readonly GoogleMapsTransport $transport) {}

    public function search(string $query, string $sessionToken): array
    {
        try {
            $body = $this->transport->request('POST', 'https://places.googleapis.com/v1/places:autocomplete', ['input' => $query, 'sessionToken' => $sessionToken, 'includedRegionCodes' => ['za'], 'includeQueryPredictions' => false], 'suggestions.placePrediction.placeId,suggestions.placePrediction.text');
            $predictions = [];
            if (! is_array($body['suggestions'] ?? [])) {
                throw new ProviderFailure('invalid_provider_response');
            }
            foreach (array_slice($body['suggestions'] ?? [], 0, 5) as $suggestion) {
                $prediction = $suggestion['placePrediction'] ?? [];
                if (! is_string($prediction['placeId'] ?? null) || ! is_string($prediction['text']['text'] ?? null)) {
                    throw new ProviderFailure('invalid_provider_response');
                }
                $predictions[] = ['place_id' => $prediction['placeId'], 'label' => $prediction['text']['text']];
            }

            return ['available' => true, 'predictions' => $predictions, 'attribution' => 'Google Maps'];
        } catch (ProviderFailure $failure) {
            $this->transport->recordFailure($failure);

            return ['available' => false, 'predictions' => [], 'message' => $failure->getMessage()];
        }
    }

    public function details(string $placeId, string $sessionToken): array
    {
        try {
            $body = $this->transport->request('GET', 'https://places.googleapis.com/v1/places/'.rawurlencode($placeId), ['sessionToken' => $sessionToken], 'id,location,formattedAddress,addressComponents');
            $lat = $body['location']['latitude'] ?? null;
            $lng = $body['location']['longitude'] ?? null;
            if (($body['id'] ?? null) !== $placeId || ! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
                throw new ProviderFailure('invalid_provider_response');
            }
            $parts = [];
            if (! is_array($body['addressComponents'] ?? [])) {
                throw new ProviderFailure('invalid_provider_response');
            }
            foreach ($body['addressComponents'] ?? [] as $component) {
                if (! is_array($component) || ! is_array($component['types'] ?? [])) {
                    throw new ProviderFailure('invalid_provider_response');
                }
                foreach ($component['types'] ?? [] as $type) {
                    if (! is_string($type) || ! is_string($component['longText'] ?? '') || ! is_string($component['shortText'] ?? '')) {
                        throw new ProviderFailure('invalid_provider_response');
                    }
                    $parts[$type] = ['long' => $component['longText'] ?? '', 'short' => $component['shortText'] ?? ''];
                }
            }
            $address = ['address_line' => $body['formattedAddress'] ?? '', 'city' => $parts['locality']['long'] ?? $parts['postal_town']['long'] ?? $parts['administrative_area_level_2']['long'] ?? '', 'postal_code' => $parts['postal_code']['short'] ?? '', 'country_code' => $parts['country']['short'] ?? ''];
            if (! is_string($address['address_line']) || strlen($address['address_line']) > 255 || ! $address['city'] || ! preg_match('/^[A-Z]{2}$/D', $address['country_code'])) {
                throw new ProviderFailure('invalid_provider_response');
            }
            $proof = Crypt::encryptString(json_encode([...$address, 'place_id' => $placeId, 'latitude' => $lat, 'longitude' => $lng, 'expires_at' => now()->addMinutes(30)->timestamp], JSON_THROW_ON_ERROR));

            return ['available' => true, ...$address, 'place_id' => $placeId, 'latitude' => $lat, 'longitude' => $lng, 'verification_proof' => $proof, 'attribution' => 'Google Maps'];
        } catch (ProviderFailure $failure) {
            $this->transport->recordFailure($failure);

            return ['available' => false, 'message' => $failure->getMessage()];
        }
    }
}
