<?php

namespace App\Domain\Availability;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\BookingHold;
use App\Domain\Bookings\ResourceAllocation;
use App\Domain\Fleet\AssessQuotationLoad;
use App\Domain\Resources\ResourceRegistry;
use Carbon\CarbonImmutable;

final class AvailabilityPlanner
{
    public function prepare(array $scope, array $policy, CarbonImmutable $from, CarbonImmutable $until, ?Booking $booking = null): array
    {
        $resources = app(ResourceRegistry::class)->eligible()->with(['vehicle.type', 'vehicle.depot', 'staff.eligibleClasses'])->orderBy('id')->limit(2001)->get();
        if ($resources->count() > 2000) {
            throw BookingConflict::because('resource_search_limit', 'Operations must narrow the resource search for this fleet.');
        }
        $duration = app(EstimateBookingDuration::class)->estimate($scope['revision'], $policy);
        $calendars = app(OperatingCalendar::class)->batch($from->subDay(), $until->addDays(3), $resources->all());
        $allocations = ResourceAllocation::whereIn('status', ['held', 'confirmed'])->where('starts_at', '<', $until->addDays(3))->where('ends_at', '>', $from->subDay())->where(fn ($q) => $q->where('status', 'confirmed')->orWhereIn('hold_id', BookingHold::where('status', 'active')->where('expires_at', '>', now())->select('id')))->get()->groupBy('resource_id');
        $vehicles = $resources->where('kind', 'vehicle')->filter(function ($resource) use ($scope, $policy) {
            $vehicle = $resource->vehicle;
            if (! $vehicle || filled($vehicle->operating_restrictions) || ! $vehicle->type->active || $vehicle->demonstration_key !== null || $vehicle->type->key !== $scope['vehicle_type_key']) {
                return false;
            }
            if ($vehicle->depot_id && (! $vehicle->depot || ! $vehicle->depot->active || ! $vehicle->depot->coordinates_verified)) {
                return false;
            }
            $quotedDepot = $scope['revision']->pricing_snapshot['configuration']['depot_public_id'] ?? null;
            if ($quotedDepot && $vehicle->depot?->public_id !== $quotedDepot) {
                return false;
            }
            if ($policy['service_rules']['depot_public_id'] && $vehicle->depot?->public_id !== $policy['service_rules']['depot_public_id']) {
                return false;
            }

            return app(AssessQuotationLoad::class)->evaluate($vehicle, $scope['inventory'], 9000, ['customer', 'catalogue'])['technically_fits'];
        })->sortBy(fn ($r) => sprintf('%015.3f:%020d', (float) $r->vehicle->payload_kg, $r->id));

        return compact('scope', 'policy', 'resources', 'vehicles', 'duration', 'calendars', 'allocations', 'booking');
    }

    public function choose(array $context, CarbonImmutable $start, array $preferred = []): array
    {
        $policy = $context['policy'];
        $rules = $policy['service_rules'];
        if (! $policy['booking_enabled'] || ! $policy['reviewed']) {
            throw BookingConflict::because('booking_not_enabled', 'Booking configuration requires an operations review.');
        }
        if ($rules['vehicles'] !== 1) {
            throw BookingConflict::because('multi_vehicle_review', 'This job requires a complete multi-vehicle allocation plan from operations. No partial reservation was made.');
        }
        if ($start->lt(now()->addMinutes($policy['notice_minutes']))) {
            throw BookingConflict::because('minimum_notice', 'The selected time does not meet the minimum booking notice.');
        }
        if ($start->gt(now($policy['timezone'])->addDays($policy['search_days'])->endOfDay()->utc())) {
            throw BookingConflict::because('booking_horizon', 'Choose a date within the configured booking horizon.');
        }
        $intervals = app(EstimateBookingDuration::class)->intervals($start, $context['duration']);
        $local = $start->setTimezone($policy['timezone']);
        if (! in_array($local->dayOfWeek, $rules['weekdays'], true)) {
            throw BookingConflict::because('service_day_closed', 'The service does not operate on that day.');
        }
        if ($rules['opens_at'] && $rules['closes_at']) {
            $open = $local->startOfDay()->setTimeFromTimeString($rules['opens_at']);
            $close = $local->startOfDay()->setTimeFromTimeString($rules['closes_at']);
            if ($close <= $open) {
                if ($local < $open) {
                    $open = $open->subDay();
                } else {
                    $close = $close->addDay();
                }
            }
            if ($local < $open || $intervals['service_ends_at'] > $close) {
                throw BookingConflict::because('service_hours_closed', 'The service interval falls outside its operating hours.');
            }
        }
        app(BookingQuoteScope::class)->priceNeutral($context['scope']['revision'], $start, $policy['timezone']);
        $movers = max($context['scope']['movers'], (int) $rules['minimum_movers']);
        foreach ($context['vehicles'] as $vehicleResource) {
            if ($preferred && ! in_array($vehicleResource->public_id, $preferred, true)) {
                continue;
            }
            if (! $this->free($vehicleResource, $context, $intervals)) {
                continue;
            }
            $staff = $context['resources']->filter(function ($r) use ($vehicleResource, $context, $intervals, $preferred) {
                if ($r->kind === 'vehicle' || ($preferred && ! in_array($r->public_id, $preferred, true)) || ! $this->free($r, $context, $intervals)) {
                    return false;
                }

                return ! $vehicleResource->vehicle->depot_id || $r->staff?->depot_id === $vehicleResource->vehicle->depot_id;
            });
            $driver = $staff->where('kind', 'driver')->first(fn ($r) => $r->staff->licence_expires_on?->toDateString() >= $intervals['allocation_ends_at']->setTimezone($policy['timezone'])->toDateString() && $r->staff->eligibleClasses->contains('vehicle_class', $vehicleResource->vehicle->type->vehicle_class));
            $crew = $staff->where('kind', 'mover')->take($movers);
            if (! $driver || $crew->count() !== $movers) {
                continue;
            }
            $selected = [$vehicleResource, $driver, ...$crew->all()];
            if ($preferred && (count($preferred) !== count($selected) || array_diff($preferred, array_map(fn ($r) => $r->public_id, $selected)))) {
                continue;
            }

            return ['intervals' => $intervals, 'resource_ids' => array_map(fn ($r) => $r->id, $selected), 'requirements' => ['vehicles' => 1, 'drivers' => 1, 'movers' => $movers, 'vehicle_type_key' => $context['scope']['vehicle_type_key']], 'duration_snapshot' => $context['duration']];
        }
        throw BookingConflict::because('resource_conflict', 'No complete combination of a suitable truck, qualified driver and required crew is available. Please choose another time.');
    }

    private function free($resource, array $context, array $intervals): bool
    {
        $from = $intervals['allocation_starts_at'];
        $until = $intervals['allocation_ends_at'];
        $covered = false;
        foreach ($context['calendars'][$resource->id] ?? [] as $window) {
            if (CarbonImmutable::parse($window['starts_at']) <= $from && CarbonImmutable::parse($window['ends_at']) >= $until) {
                $covered = true;
                break;
            }
        }
        if (! $covered) {
            return false;
        }
        foreach ($context['allocations']->get($resource->id, collect()) as $allocation) {
            if ($context['booking'] && $allocation->booking_id === $context['booking']->id && $allocation->status === 'confirmed') {
                continue;
            }
            if (($context['ignore_hold_id'] ?? null) === $allocation->hold_id) {
                continue;
            }
            if ($allocation->starts_at < $until && $allocation->ends_at > $from) {
                return false;
            }
        }

        return true;
    }
}
