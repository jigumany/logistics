<?php

namespace App\Domain\Dispatch;

use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\SchedulingTransaction;
use App\Domain\Notifications\OutboxMessage;

final class TransitionJob
{
    public const NEXT = ['assigned' => 'accepted', 'accepted' => 'en_route', 'en_route' => 'arrived', 'arrived' => 'loading', 'loading' => 'in_transit', 'in_transit' => 'delivered', 'delivered' => 'completed'];

    public function execute(DispatchJob $job, string $target, int $version, string $key, int $actor, ?string $note = null, bool $operator = false): DispatchJob
    {
        if ($operator) {
            app(DispatchAccess::class)->manage($actor);
        } else {
            app(DispatchAccess::class)->assigned($job, $actor);
        }
        $hash = hash('sha256', json_encode([$job->public_id, $target, $version, $note, $actor], JSON_THROW_ON_ERROR));

        return app(SchedulingTransaction::class)->execute(function () use ($job, $target, $version, $key, $actor, $note, $hash, $operator) {
            $j = DispatchJob::whereKey($job->id)->lockForUpdate()->firstOrFail();
            $existing = DispatchEvent::where('idempotency_key', $key)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw BookingConflict::because('idempotency_conflict', 'This operation key was already used.');
                }

                return $j;
            }
            if ($j->lock_version !== $version || ((self::NEXT[$j->status] ?? null) !== $target && ! ($operator && $target === 'recalled' && in_array($j->status, ['assigned', 'accepted'], true) && strlen(trim($note ?? '')) >= 5))) {
                throw BookingConflict::because('job_changed', 'Refresh the job before advancing to its next step.');
            }
            if (in_array($target, ['delivered', 'completed'], true) && ! DeliveryProof::where('job_id', $j->id)->exists()) {
                throw BookingConflict::because('proof_required', 'Capture acknowledged delivery proof before marking delivery.');
            }
            $from = $j->status;
            $j->status = $target;
            $j->lock_version++;
            if ($target === 'completed') {
                $j->completed_at = now();
            }$j->save();
            DispatchEvent::create(['job_id' => $j->id, 'idempotency_key' => $key, 'request_hash' => $hash, 'from_status' => $from, 'to_status' => $target, 'actor_id' => $actor, 'note' => $note]);
            OutboxMessage::create(['event_type' => 'dispatch.'.$target, 'payload' => ['job_public_id' => $j->public_id, 'booking_id' => $j->booking_id]]);

            return $j;
        });
    }
}
