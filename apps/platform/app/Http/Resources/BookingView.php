<?php

namespace App\Http\Resources;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingChangeRequest;
use App\Domain\Bookings\BookingHold;
use App\Domain\Bookings\BookingHoldResource;
use App\Domain\Bookings\BookingStatusHistory;
use App\Domain\Bookings\GuestBookingLinks;
use App\Domain\Bookings\ResourceAllocation;
use App\Domain\Dispatch\DispatchJob;
use App\Domain\Quotes\Quote;
use App\Domain\Resources\Resource;
use App\Domain\Tenancy\CompanySettings;

final class BookingView
{
    public function forQuote(Quote $quote, bool $internal = false): array
    {
        $booking = Booking::where('quote_id', $quote->id)->first();
        $hold = BookingHold::where('quote_id', $quote->id)->where('status', 'active')->where('expires_at', '>', now())->orderByDesc('id')->first();
        $data = ['quote' => app(QuotationView::class)->make($quote, $internal), 'booking' => $booking ? $this->booking($booking, $internal) : null, 'hold' => $hold ? $this->hold($hold, $internal) : null, 'timezone' => CompanySettings::value('timezone') ?? 'Africa/Johannesburg', 'links' => app(GuestBookingLinks::class)->forQuote($quote), 'server_time' => now()->toIso8601String()];

        return $data;
    }

    public function hold(BookingHold $hold, bool $internal = false): array
    {
        $data = $hold->only('public_id', 'purpose', 'status', 'expires_at', 'arrival_starts_at', 'arrival_ends_at', 'service_starts_at', 'service_ends_at', 'requirements', 'duration_snapshot');
        if ($internal) {
            $data['resources'] = Resource::with(['vehicle', 'staff'])->whereIn('id', BookingHoldResource::where('hold_id', $hold->id)->select('resource_id'))->get()->map(fn ($r) => ['public_id' => $r->public_id, 'kind' => $r->kind, 'label' => $r->label])->all();
        }

        return $data;
    }

    public function booking(Booking $booking, bool $internal = false): array
    {
        $data = $booking->only('public_id', 'reference', 'status', 'financial_state', 'required_payment_minor', 'currency', 'dispatch_state', 'refund_state', 'arrival_starts_at', 'arrival_ends_at', 'service_starts_at', 'service_ends_at', 'lock_version', 'requirements', 'duration_snapshot');
        $data['confirmation_instructions'] = match ($booking->status) {
            'confirmed' => 'Your booking has been confirmed by the reservation system. Dispatch instructions will follow separately.','pending_payment' => 'Complete payment through Yoco before your temporary hold expires. Only verified payment and any required operator approval can confirm this booking.','pending_confirmation' => 'An authorised operations team member must confirm this booking before the hold expires.','expired' => 'The temporary hold expired and resources have been released. Contact operations for a fresh availability review.','cancelled' => 'This booking has been cancelled.',default => 'Please contact operations.'
        };
        $data['dispatch'] = DispatchJob::where('booking_id', $booking->id)->where('status', '<>', 'recalled')->orderByDesc('id')->first()?->only(['status', 'completed_at']);
        $data['payments'] = app(PaymentSummary::class)->make($booking);
        $data['history'] = BookingStatusHistory::where('booking_id', $booking->id)->orderByDesc('id')->limit(100)->get()->map(fn ($h) => $internal ? $h->only('from_status', 'to_status', 'reason', 'created_at') : $h->only('to_status', 'created_at'))->all();
        if ($internal) {
            $data['allocation_starts_at'] = $booking->allocation_starts_at;
            $data['allocation_ends_at'] = $booking->allocation_ends_at;
            $data['resources'] = Resource::with(['vehicle', 'staff'])->whereIn('id', ResourceAllocation::where('booking_id', $booking->id)->whereIn('status', ['held', 'confirmed'])->select('resource_id'))->get()->map(fn ($r) => ['public_id' => $r->public_id, 'kind' => $r->kind, 'label' => $r->label, 'registration' => $r->vehicle?->registration_number])->all();
            $data['changes'] = BookingChangeRequest::where('booking_id', $booking->id)->orderByDesc('id')->limit(100)->get(['kind', 'reason', 'outcome', 'details', 'created_at'])->toArray();
        }

        return $data;
    }
}
