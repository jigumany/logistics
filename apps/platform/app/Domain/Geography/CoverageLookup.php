<?php

namespace App\Domain\Geography;

interface CoverageLookup
{
    public function check(float $latitude, float $longitude, string $purpose = 'pickup'): array;
}
