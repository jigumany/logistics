<?php

namespace App\Domain\Routing;

use RuntimeException;

final class ProviderFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The mapping provider is unavailable. Manual quotation remains available.');
    }
}
