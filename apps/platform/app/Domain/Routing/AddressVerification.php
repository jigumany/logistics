<?php

namespace App\Domain\Routing;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

final class AddressVerification
{
    public function resolve(array $address): array
    {
        $address['verification'] = 'manual';
        $proof = $address['verification_proof'] ?? null;
        unset($address['verification_proof']);
        if (! $proof) {
            return $address;
        }
        try {
            $verified = json_decode(Crypt::decryptString($proof), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages(['stops' => 'The address verification has expired. Select the address again or enter it manually.']);
        }
        if (($verified['expires_at'] ?? 0) < now()->timestamp || ($address['provider_place_id'] ?? '') !== ($verified['place_id'] ?? null) || ! isset($address['latitude'], $address['longitude']) || abs((float) $address['latitude'] - (float) $verified['latitude']) > 0.0000001 || abs((float) $address['longitude'] - (float) $verified['longitude']) > 0.0000001) {
            throw ValidationException::withMessages(['stops' => 'The selected coordinates changed. Verify the address again.']);
        }
        foreach (['address_line', 'city', 'country_code'] as $field) {
            if (($address[$field] ?? '') !== ($verified[$field] ?? null)) {
                throw ValidationException::withMessages(['stops' => 'The selected address changed. Select it again or use manual entry.']);
            }
        }
        $address['verification'] = 'provider';

        return $address;
    }
}
