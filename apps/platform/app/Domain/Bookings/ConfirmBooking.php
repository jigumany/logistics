<?php

namespace App\Domain\Bookings;

use App\Domain\Availability\AvailabilityPlanner;
use App\Domain\Availability\BookingQuoteScope;
use App\Domain\Quotes\Quote;
use App\Domain\Resources\Resource;
use App\Domain\Services\TenantService;
use Illuminate\Support\Str;

final class ConfirmBooking
{
    public function execute(BookingHold $hold, string $key, ?int $actorId = null): Booking
    {
        if ($actorId) {
            app(BookingPermissions::class)->manage($actorId);
        }
        $hash = hash('sha256', $hold->public_id);

        return app(SchedulingTransaction::class)->execute(function () use ($hold, $key, $hash, $actorId) {
            app(ExpireBookingHolds::class)->execute();
            $receipt = app(BookingCommandReceipt::class)->find($key, 'confirm', $hash);
            if ($receipt) {
                return Booking::whereKey($receipt->booking_id)->firstOrFail();
            }
            $hold = BookingHold::whereKey($hold->id)->lockForUpdate()->firstOrFail();
            if ($hold->purpose !== 'initial' || $hold->status !== 'active' || $hold->expires_at->isPast()) {
                throw BookingConflict::because('hold_expired', 'This hold is no longer available. Refresh availability and reserve another slot.');
            }
            $quote = Quote::whereKey($hold->quote_id)->firstOrFail();
            $scope = app(BookingQuoteScope::class)->resolve($quote);
            if ($hold->accepted_revision_id !== $scope['revision']->id || $hold->customer_id !== $quote->customer_id) {
                throw BookingConflict::because('quote_changed', 'The held quotation no longer matches this customer’s accepted revision.');
            }
            $service = TenantService::whereKey($quote->service_id)->firstOrFail();
            $policy = app(BookingPolicy::class)->forService($service);
            $context = app(AvailabilityPlanner::class)->prepare($scope, $policy, $hold->service_starts_at->startOfDay(), $hold->service_ends_at->addDay());
            $context['ignore_hold_id'] = $hold->id;
            $context['policy']['notice_minutes'] = 0;
            $resources = Resource::whereIn('id', BookingHoldResource::where('hold_id', $hold->id)->select('resource_id'))->orderBy('id')->get();
            $choice = app(AvailabilityPlanner::class)->choose($context, $hold->service_starts_at, $resources->pluck('public_id')->all());
            foreach ($choice['intervals'] as $field => $value) {
                if (! $value->eq($hold->{$field})) {
                    throw BookingConflict::because('schedule_requirements_changed', 'The duration or scheduling policy changed. Release this hold and search again.');
                }
            }
            if ($choice['requirements'] !== $hold->requirements) {
                throw BookingConflict::because('schedule_requirements_changed', 'The required resource set changed. Please reserve a fresh slot.');
            }
            $booking = $hold->booking_id ? Booking::whereKey($hold->booking_id)->lockForUpdate()->firstOrFail() : null;
            if (! $booking) {
                $booking = new Booking([...$choice['intervals'], 'requirements' => $choice['requirements'], 'duration_snapshot' => $choice['duration_snapshot'], 'quote_id' => $quote->id, 'accepted_revision_id' => $scope['revision']->id, 'customer_id' => $quote->customer_id, 'service_id' => $quote->service_id, 'reference' => 'B-'.Str::upper(Str::random(12)), 'policy_snapshot' => [...$policy, 'manual_confirmation' => $policy['manual_confirmation'] || $service->manual_approval || filled($service->restrictions)], 'required_payment_minor' => app(BookingPaymentRequirement::class)->amount($scope['revision']->total_minor, $policy), 'currency' => $scope['revision']->currency, 'financial_state' => 'awaiting_verification', 'refund_state' => 'none', 'lock_version' => 0]);
            }
            if ($booking->exists && ! in_array($booking->status, ['pending_payment', 'pending_confirmation'], true)) {
                throw BookingConflict::because('booking_closed', 'This booking cannot be confirmed again.');
            }
            app(BookingPaymentRequirement::class)->apply($booking);
            $status = $booking->financial_state === 'awaiting_verification' ? 'pending_payment' : (($booking->policy_snapshot['manual_confirmation'] && ! $actorId) ? 'pending_confirmation' : 'confirmed');
            app(BookingLifecycle::class)->record($booking, $status, $status === 'confirmed' ? 'Accepted quotation, complete resources and confirmation requirements verified.' : 'Resources are held temporarily; confirmation requirements remain outstanding.', $actorId);
            $hold->booking_id = $booking->id;
            if ($status === 'confirmed') {
                ResourceAllocation::where('hold_id', $hold->id)->where('status', 'held')->update(['booking_id' => $booking->id, 'status' => 'confirmed', 'updated_at' => now()]);
                $hold->status = 'consumed';
            } else {
                ResourceAllocation::where('hold_id', $hold->id)->where('status', 'held')->update(['booking_id' => $booking->id, 'updated_at' => now()]);
            }
            $hold->save();
            app(BookingCommandReceipt::class)->save($booking, $key, 'confirm', $hash, 'Confirmation requirements evaluated.', ['hold_public_id' => $hold->public_id], $actorId);
            app(BookingLifecycle::class)->event('booking.'.$status, $booking);

            return $booking;
        });
    }
}
