<?php

namespace App\Domain\Dispatch;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingConflict;
use Illuminate\Support\Facades\Schema;

final class ProtectDispatchedBooking
{
    public function assertMutable(Booking $booking): void
    {
        if (Schema::hasTable('dispatch_jobs') && DispatchJob::where('booking_id', $booking->id)->exists()) {
            throw BookingConflict::because('dispatched_booking', 'This booking has been dispatched. Contact operations to resolve the job; it cannot be cancelled or rescheduled through reservations.');
        }
    }
}
