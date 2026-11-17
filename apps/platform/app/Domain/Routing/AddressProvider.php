<?php

namespace App\Domain\Routing;

interface AddressProvider
{
    public function search(string $query, string $sessionToken): array;

    public function details(string $placeId, string $sessionToken): array;
}
