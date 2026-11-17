<?php

namespace App\Domain\Notifications;

final readonly class DomainEvent
{
    public function __construct(public string $id, public string $type, public array $payload) {}
}
