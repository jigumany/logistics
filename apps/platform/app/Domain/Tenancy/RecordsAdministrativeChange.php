<?php

namespace App\Domain\Tenancy;

use App\Domain\Notifications\OutboxMessage;
use Illuminate\Database\Eloquent\Model;

final class RecordsAdministrativeChange
{
    public function record(Model $subject, string $event, int $actorId): void
    {
        AuditEntry::create(['actor_id' => $actorId, 'action' => $event, 'subject_public_id' => $subject->getAttribute('public_id')]);
        OutboxMessage::create(['event_type' => $event, 'payload' => ['subject_id' => $subject->getAttribute('public_id')]]);
    }
}
