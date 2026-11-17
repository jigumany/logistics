<?php

namespace App\Domain\Bookings;

use App\Domain\Availability\AvailabilityPlanner;
use App\Domain\Availability\BookingQuoteScope;
use App\Domain\Notifications\OutboxMessage;
use App\Domain\Quotes\Quote;
use App\Domain\Resources\Resource;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class CreateBookingHold
{
    public function execute(Quote $quote, CarbonImmutable $start, string $key, ?Booking $booking = null, array $preferred = [], ?int $actorId = null): BookingHold
    {
        if ($actorId) {
            app(BookingPermissions::class)->manage($actorId);
        }
        if ($preferred && ! $actorId) {
            throw BookingConflict::because('operator_selection_required', 'Only operations staff can select specific fleet resources.');
        }
        $hash = hash('sha256', json_encode([$quote->public_id, $start->utc()->toIso8601String(), $booking?->public_id, array_values($preferred)], JSON_THROW_ON_ERROR));
        try {
            return app(SchedulingTransaction::class)->execute(function () use ($quote, $start, $key, $hash, $booking, $preferred, $actorId) {
                app(ExpireBookingHolds::class)->execute();
                $existing = BookingHold::where('idempotency_key', $key)->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        throw BookingConflict::because('idempotency_conflict', 'This hold key was already used for a different request.');
                    }

                    return $existing;
                }
                $scope = app(BookingQuoteScope::class)->resolve($quote);
                $quote = $scope['quote'];
                $service = TenantService::whereKey($quote->service_id)->firstOrFail();
                $policy = app(BookingPolicy::class)->forService($service);
                if ($booking) {
                    $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
                    if ($booking->quote_id !== $quote->id || $booking->customer_id !== $quote->customer_id || $booking->status !== 'confirmed') {
                        throw BookingConflict::because('reschedule_not_allowed', 'Only this customer’s confirmed booking can be rescheduled.');
                    }
                } elseif (Booking::where('quote_id', $quote->id)->exists()) {
                    throw BookingConflict::because('booking_exists', 'This accepted quotation already has a booking. Open that booking to manage it.');
                }
                if (BookingHold::where('quote_id', $quote->id)->where('status', 'active')->exists()) {
                    throw BookingConflict::because('hold_exists', 'Release the current hold before choosing another slot. Holds cannot be extended indefinitely.');
                }
                if (BookingHold::where('quote_id', $quote->id)->where('created_at', '>', now()->subHour())->count() >= $policy['hold_attempts_per_hour'] || BookingHold::where('customer_id', $quote->customer_id)->where('created_at', '>', now()->subHour())->count() >= 10) {
                    throw BookingConflict::because('hold_limit', 'The temporary reservation limit has been reached. Please contact operations or retry later.');
                }
                $context = app(AvailabilityPlanner::class)->prepare($scope, $policy, $start->startOfDay(), $start->addDay(), $booking);
                $choice = app(AvailabilityPlanner::class)->choose($context, $start, $preferred);
                do {
                    $expired = app(ExpireBookingHolds::class)->execute(500, $choice['resource_ids'], $choice['intervals']['allocation_starts_at'], $choice['intervals']['allocation_ends_at']);
                } while ($expired === 500);
                Resource::whereIn('id', $choice['resource_ids'])->orderBy('id')->lockForUpdate()->get();
                $hold = BookingHold::create([...$choice['intervals'], 'requirements' => $choice['requirements'], 'duration_snapshot' => $choice['duration_snapshot'], 'quote_id' => $quote->id, 'accepted_revision_id' => $scope['revision']->id, 'customer_id' => $quote->customer_id, 'booking_id' => $booking?->id, 'purpose' => $booking ? 'reschedule' : 'initial', 'status' => 'active', 'idempotency_key' => $key, 'request_hash' => $hash, 'expires_at' => now()->addMinutes($policy['hold_minutes'])->min($scope['revision']->expires_at)]);
                foreach ($choice['resource_ids'] as $resourceId) {
                    BookingHoldResource::create(['hold_id' => $hold->id, 'resource_id' => $resourceId]);
                    $segments = [[$hold->allocation_starts_at, $hold->allocation_ends_at]];
                    if ($booking) {
                        foreach (ResourceAllocation::where('booking_id', $booking->id)->where('resource_id', $resourceId)->where('status', 'confirmed')->get() as $old) {
                            $next = [];
                            foreach ($segments as [$a,$b]) {
                                if ($old->ends_at <= $a || $old->starts_at >= $b) {
                                    $next[] = [$a, $b];

                                    continue;
                                }
                                if ($a < $old->starts_at) {
                                    $next[] = [$a, $old->starts_at];
                                }
                                if ($old->ends_at < $b) {
                                    $next[] = [$old->ends_at, $b];
                                }
                            }
                            $segments = $next;
                        }
                    }
                    foreach ($segments as [$a,$b]) {
                        ResourceAllocation::create(['hold_id' => $hold->id, 'resource_id' => $resourceId, 'booking_id' => $booking?->id, 'status' => 'held', 'starts_at' => $a, 'ends_at' => $b]);
                    }
                }
                OutboxMessage::create(['event_type' => 'booking.hold_created', 'payload' => ['quote_public_id' => $quote->public_id, 'hold_public_id' => $hold->public_id, 'expires_at' => $hold->expires_at->toIso8601String()]]);
                if ($actorId) {
                    app(RecordsAdministrativeChange::class)->record($hold, 'booking.hold_administration_created', $actorId);
                }
                Log::info('booking.hold_created', ['hold_public_id' => $hold->public_id, 'resources' => count($choice['resource_ids'])]);

                return $hold;
            });
        } catch (BookingConflict $e) {
            Log::info('booking.hold_conflict', ['quote_public_id' => $quote->public_id, 'reasons' => array_column($e->reasons, 'code')]);
            if (! BookingChangeRequest::where('idempotency_key', $key)->exists()) {
                BookingChangeRequest::firstOrCreate(['idempotency_key' => $key], ['quote_id' => $quote->id, 'booking_id' => $booking?->id, 'request_hash' => $hash, 'kind' => 'hold_conflict', 'outcome' => 'conflict', 'reason' => $e->getMessage(), 'details' => ['starts_at' => $start->toIso8601String(), 'reasons' => $e->reasons], 'actor_id' => $actorId]);
            }
            throw $e;
        }
    }
}
