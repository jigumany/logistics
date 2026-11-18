<?php

namespace App\Domain\Dispatch;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\ResourceAllocation;
use App\Domain\Bookings\SchedulingTransaction;
use App\Domain\Notifications\OutboxMessage;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Resources\ResourceRegistry;
use Illuminate\Support\Str;

final class DispatchBooking
{
    public function execute(Booking $booking, int $actor): DispatchJob
    {
        app(DispatchAccess::class)->manage($actor);

        return app(SchedulingTransaction::class)->execute(function () use ($booking, $actor) {
            $b = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $existing = DispatchJob::where('booking_id', $b->id)->where('status', '<>', 'recalled')->first();
            if ($existing) {
                return $existing;
            }
            if ($b->status !== 'confirmed' || ! in_array($b->financial_state, ['verified', 'not_required'], true)) {
                throw BookingConflict::because('dispatch_not_ready', 'Only confirmed bookings satisfying their payment policy can be dispatched.');
            }
            if ($b->allocation_ends_at->isPast()) {
                throw BookingConflict::because('dispatch_window_passed', 'The reservation window has ended. Review availability and reschedule before dispatch.');
            }
            $resources = app(ResourceRegistry::class)->eligible()->with(['staff', 'vehicle'])->whereIn('id', ResourceAllocation::where('booking_id', $b->id)->where('status', 'confirmed')->select('resource_id'))->orderBy('id')->get();
            $snapshot = [];
            foreach ($resources as $r) {
                if (! $r->active || ($r->kind === 'vehicle' && (! $r->vehicle || $r->vehicle->status !== 'available' || ! $r->vehicle->capacity_verified || $r->vehicle->demonstration_key)) || ($r->kind !== 'vehicle' && (! $r->staff || ! $r->staff->active))) {
                    throw BookingConflict::because('resource_unavailable', 'A reserved resource is no longer operational.');
                }$snapshot[] = ['resource_public_id' => $r->public_id, 'kind' => $r->kind, 'label' => $r->label, 'staff_public_id' => $r->staff?->public_id];
            }
            foreach (['vehicle' => 'vehicles', 'driver' => 'drivers', 'mover' => 'movers'] as $kind => $count) {
                if (count(array_filter($snapshot, fn ($r) => $r['kind'] === $kind)) !== (int) $b->requirements[$count]) {
                    throw BookingConflict::because('incomplete_dispatch', 'The complete reserved team is required.');
                }
            }
            $revision = QuoteRevision::with(['stops', 'inventory'])->findOrFail($b->accepted_revision_id);
            $work = ['reference' => $b->reference, 'starts_at' => $b->service_starts_at->toIso8601String(), 'ends_at' => $b->service_ends_at->toIso8601String(), 'contact' => array_intersect_key($revision->contact_snapshot, array_flip(['name', 'phone'])), 'stops' => $revision->stops->map(fn ($s) => $s->only(['position', 'address_line', 'city', 'latitude', 'longitude', 'kind', 'instructions']))->all(), 'inventory' => $revision->inventory->map(fn ($i) => $i->only(['name', 'quantity', 'length_cm', 'width_cm', 'height_cm', 'weight_kg', 'instructions', 'fragile']))->all(), 'access' => array_intersect_key($revision->input['details'] ?? [], array_flip(['pickup_stairs', 'delivery_stairs', 'pickup_lift', 'delivery_lift', 'waiting_minutes']))];
            $job = DispatchJob::create(['booking_id' => $b->id, 'booking_version' => $b->lock_version, 'status' => 'assigned', 'lock_version' => 0, 'resource_snapshot' => $snapshot, 'work_snapshot' => $work]);
            DispatchEvent::create(['job_id' => $job->id, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', $b->public_id), 'from_status' => null, 'to_status' => 'assigned', 'actor_id' => $actor, 'note' => 'Dispatched the complete reserved team.']);
            OutboxMessage::create(['event_type' => 'dispatch.assigned', 'payload' => ['job_public_id' => $job->public_id, 'booking_public_id' => $b->public_id]]);

            return $job;
        });
    }
}
