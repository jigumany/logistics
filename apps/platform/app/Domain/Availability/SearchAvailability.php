<?php

namespace App\Domain\Availability;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\BookingPolicy;
use App\Domain\Quotes\Quote;
use App\Domain\Services\TenantService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class SearchAvailability
{
    public function execute(Quote $quote, string $date, int $days = 1, ?Booking $booking = null): array
    {
        $clock = hrtime(true);
        $scope = app(BookingQuoteScope::class)->resolve($quote);
        $service = TenantService::whereKey($quote->service_id)->firstOrFail();
        $policy = app(BookingPolicy::class)->forService($service);
        if ($days < 1 || $days > min(7, $policy['search_days'])) {
            throw BookingConflict::because('search_range', 'Search between one and seven days at a time.');
        }
        $from = CarbonImmutable::parse($date, $policy['timezone'])->startOfDay();
        $until = $from->addDays($days);
        if ($from->lt(now($policy['timezone'])->startOfDay()) || $from->gt(now($policy['timezone'])->addDays($policy['search_days']))) {
            throw BookingConflict::because('search_range', 'Choose a date within the configured booking horizon.');
        }
        $context = app(AvailabilityPlanner::class)->prepare($scope, $policy, $from, $until, $booking);
        $slots = [];
        $reasons = [];
        for ($day = $from; $day < $until; $day = $day->addDay()) {
            for ($minute = 0; $minute < 1440; $minute += $policy['slot_minutes']) {
                $start = app(LocalTimeResolver::class)->resolve($day->toDateString(), sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60), $policy['timezone']);
                if (! $start) {
                    continue;
                }
                try {
                    $choice = app(AvailabilityPlanner::class)->choose($context, $start);
                    $slots[] = ['starts_at' => $start->utc()->toIso8601String(), 'ends_at' => $choice['intervals']['service_ends_at']->utc()->toIso8601String(), 'timezone' => $policy['timezone'], 'state' => 'available', 'estimated_service_minutes' => $context['duration']['service_minutes'], 'vehicle_class' => $scope['vehicle_type_key']];
                } catch (BookingConflict $e) {
                    foreach ($e->reasons as $reason) {
                        $reasons[$reason['code']] = $reason;
                    }
                }
            }
        }
        Log::info('booking.availability_searched', ['quote_public_id' => $quote->public_id, 'elapsed_ms' => (hrtime(true) - $clock) / 1e6, 'slots' => count($slots)]);

        return ['slots' => $slots, 'reasons' => $slots ? [] : array_values($reasons), 'timezone' => $policy['timezone'], 'hold_minutes' => $policy['hold_minutes'], 'advisory' => true];
    }
}
