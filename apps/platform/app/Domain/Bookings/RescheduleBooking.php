<?php

namespace App\Domain\Bookings;

use App\Domain\Availability\AvailabilityPlanner;
use App\Domain\Availability\BookingQuoteScope;
use App\Domain\Quotes\Quote;
use App\Domain\Resources\Resource;
use App\Domain\Services\TenantService;

final class RescheduleBooking
{
    public function execute(Booking $booking, BookingHold $hold, string $key, string $reason, int $version, ?int $actorId = null): Booking
    {
        if ($actorId) {
            app(BookingPermissions::class)->manage($actorId);
        }
        $hash = hash('sha256', json_encode([$booking->public_id, $hold->public_id, $reason, $version], JSON_THROW_ON_ERROR));

        return app(SchedulingTransaction::class)->execute(function () use ($booking, $hold, $key, $hash, $reason, $version, $actorId) {
            app(ExpireBookingHolds::class)->execute();
            $receipt = app(BookingCommandReceipt::class)->find($key, 'reschedule', $hash);
            if ($receipt) {
                return Booking::whereKey($receipt->booking_id)->firstOrFail();
            }
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $hold = BookingHold::whereKey($hold->id)->lockForUpdate()->firstOrFail();
            if ($booking->status !== 'confirmed' || $booking->lock_version !== $version || $hold->booking_id !== $booking->id || $hold->purpose !== 'reschedule' || $hold->status !== 'active' || $hold->expires_at->isPast()) {
                throw BookingConflict::because('reschedule_conflict', 'The booking or replacement hold changed. The original booking has been preserved.');
            }
            if (($booking->policy_snapshot['manual_confirmation'] ?? true) && $actorId === null) {
                throw BookingConflict::because('operator_approval_required', 'An authorised operator must approve this replacement. Your original booking remains confirmed.');
            }
            $quote = Quote::whereKey($booking->quote_id)->firstOrFail();
            $scope = app(BookingQuoteScope::class)->resolve($quote);
            $policy = app(BookingPolicy::class)->forService(TenantService::whereKey($booking->service_id)->firstOrFail());
            $context = app(AvailabilityPlanner::class)->prepare($scope, $policy, $hold->service_starts_at->startOfDay(), $hold->service_ends_at->addDay(), $booking);
            $context['ignore_hold_id'] = $hold->id;
            $context['policy']['notice_minutes'] = 0;
            $resources = Resource::whereIn('id', BookingHoldResource::where('hold_id', $hold->id)->select('resource_id'))->orderBy('id')->get();
            $choice = app(AvailabilityPlanner::class)->choose($context, $hold->service_starts_at, $resources->pluck('public_id')->all());
            foreach ($choice['intervals'] as $field => $value) {
                if (! $value->eq($hold->{$field})) {
                    throw BookingConflict::because('schedule_requirements_changed', 'The replacement duration changed. The original booking has been preserved.');
                }
            }
            if ($choice['requirements'] !== $hold->requirements) {
                throw BookingConflict::because('schedule_requirements_changed', 'The replacement resource requirements changed.');
            }
            $old = $booking->only('service_starts_at', 'service_ends_at', 'allocation_starts_at', 'allocation_ends_at');
            ResourceAllocation::where('booking_id', $booking->id)->where('status', 'confirmed')->update(['status' => 'released', 'updated_at' => now()]);
            ResourceAllocation::where('hold_id', $hold->id)->where('status', 'held')->update(['status' => 'released', 'updated_at' => now()]);
            $booking->fill([...$choice['intervals'], 'requirements' => $choice['requirements'], 'duration_snapshot' => $choice['duration_snapshot']]);
            app(BookingLifecycle::class)->record($booking, 'confirmed', $reason, $actorId);
            foreach ($choice['resource_ids'] as $resourceId) {
                ResourceAllocation::create(['hold_id' => $hold->id, 'booking_id' => $booking->id, 'resource_id' => $resourceId, 'status' => 'confirmed', 'starts_at' => $hold->allocation_starts_at, 'ends_at' => $hold->allocation_ends_at]);
            }
            $hold->status = 'consumed';
            $hold->save();
            app(BookingCommandReceipt::class)->save($booking, $key, 'reschedule', $hash, $reason, ['previous_interval' => $old, 'replacement_hold_public_id' => $hold->public_id], $actorId);
            app(BookingLifecycle::class)->event('booking.rescheduled', $booking);

            return $booking;
        });
    }
}
