<?php

namespace App\Infrastructure\Maps;

use App\Domain\Routing\ProviderFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class GoogleMapsTransport
{
    public function available(): bool
    {
        return filled(config('maps.google.key')) && config('maps.google.billing_confirmed') && config('maps.google.retention_permitted') && (int) config('maps.google.retention_hours') > 0;
    }

    public function request(string $method, string $url, array $data, string $fieldMask): array
    {
        if (! $this->available()) {
            throw new ProviderFailure('provider_not_configured');
        }
        foreach (range(0, 1) as $attempt) {
            try {
                $client = Http::connectTimeout(2)->timeout(max(1, min(10, (int) config('maps.google.timeout_seconds'))))->acceptJson()->withHeaders(['X-Goog-Api-Key' => config('maps.google.key'), 'X-Goog-FieldMask' => $fieldMask]);
                $response = $method === 'GET' ? $client->get($url, $data) : $client->post($url, $data);
                if ($response->successful()) {
                    $body = $response->json();
                    if (! is_array($body)) {
                        throw new ProviderFailure('invalid_provider_response');
                    }

                    return $body;
                }
                $reason = $response->status() === 429 ? 'provider_rate_limited' : ($response->serverError() ? 'provider_failure' : 'provider_rejected_request');
                if ($attempt === 0 && ($response->status() === 429 || $response->serverError())) {
                    usleep(200000);

                    continue;
                }
                throw new ProviderFailure($reason);
            } catch (ConnectionException) {
                if ($attempt === 0) {
                    continue;
                }
                throw new ProviderFailure('provider_timeout');
            }
        }
        throw new ProviderFailure('provider_failure');
    }

    public function recordFailure(ProviderFailure $failure): void
    {
        // Provider bodies, addresses and credentials deliberately stay out of telemetry.
        Log::warning('quote.mapping_provider_failed', ['provider' => 'google', 'reason' => $failure->reason]);
    }
}
