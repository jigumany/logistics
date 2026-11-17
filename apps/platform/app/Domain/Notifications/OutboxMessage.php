<?php

namespace App\Domain\Notifications;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed> $payload */
class OutboxMessage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['event_type', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'published_at' => 'immutable_datetime'];
    }
}
