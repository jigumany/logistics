<?php

namespace App\Domain\Bookings;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class ExpireBookingHolds
{
    // Call within SchedulingTransaction, including before every authoritative allocation attempt.
    public function execute(int $limit = 500, array $resourceIds = [], ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): int
    {
        $count = 0;
        $query = BookingHold::where('status', 'active')->where('expires_at', '<=', now());
        if ($resourceIds) {
            $allocations = ResourceAllocation::whereIn('resource_id', $resourceIds)->where('status', 'held')->when($from, fn ($q) => $q->where('ends_at', '>', $from))->when($until, fn ($q) => $q->where('starts_at', '<', $until));
            $query->whereIn('id', $allocations->select('hold_id'));
        }
        foreach ($query->orderBy('id')->limit($limit)->lockForUpdate()->get() as $hold) {
            ResourceAllocation::where('hold_id', $hold->id)->where('status', 'held')->update(['status' => 'released', 'updated_at' => now()]);
            $hold->status = 'expired';
            $hold->save();
            if ($hold->booking_id && $hold->purpose === 'initial') {
                $booking = Booking::whereKey($hold->booking_id)->lockForUpdate()->firstOrFail();
                if (in_array($booking->status, ['pending_payment', 'pending_confirmation'], true)) {
                    app(BookingLifecycle::class)->record($booking, 'expired', 'Temporary resource hold expired before confirmation.');
                    app(BookingLifecycle::class)->event('booking.hold_expired', $booking);
                }
            }
            $count++;
        }
        if ($count) {
            Log::info('booking.holds_expired', ['count' => $count]);
        }

        return $count;
    }

    public function release(BookingHold $hold): void
    {
        if ($hold->status !== 'active') {
            return;
        }
        ResourceAllocation::where('hold_id', $hold->id)->where('status', 'held')->update(['status' => 'released', 'updated_at' => now()]);
        $hold->status = 'released';
        $hold->save();
        if ($hold->purpose === 'initial' && $hold->booking_id) {
            $booking = Booking::whereKey($hold->booking_id)->firstOrFail();
            if (in_array($booking->status, ['pending_payment', 'pending_confirmation'], true)) {
                app(BookingLifecycle::class)->record($booking, 'expired', 'Resource hold released before confirmation.');
            }
        }
    }
}
