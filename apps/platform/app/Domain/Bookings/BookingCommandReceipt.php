<?php

namespace App\Domain\Bookings;

final class BookingCommandReceipt
{
    public function find(string $key, string $kind, string $hash): ?BookingChangeRequest
    {
        $row = BookingChangeRequest::where('idempotency_key', $key)->first();
        if ($row && ($row->kind !== $kind || ! hash_equals($row->request_hash, $hash))) {
            throw BookingConflict::because('idempotency_conflict', 'This command key was used for different booking instructions.');
        }

        return $row;
    }

    public function save(Booking $booking, string $key, string $kind, string $hash, string $reason, array $details = [], ?int $actorId = null): void
    {
        BookingChangeRequest::create(['quote_id' => $booking->quote_id, 'booking_id' => $booking->id, 'idempotency_key' => $key, 'kind' => $kind, 'request_hash' => $hash, 'reason' => $reason, 'details' => $details, 'actor_id' => $actorId, 'outcome' => 'completed']);
    }
}
