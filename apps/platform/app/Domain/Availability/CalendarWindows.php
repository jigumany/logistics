<?php

namespace App\Domain\Availability;

use App\Domain\Resources\Resource;
use App\Domain\Resources\ResourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class CalendarWindows implements OperatingCalendar
{
    private ?Collection $weeklyRows = null;

    private ?Collection $overrideRows = null;

    private ?Collection $blockRows = null;

    private ?array $eligibleIds = null;

    public function batch(CarbonImmutable $from, CarbonImmutable $until, array $resources): array
    {
        $copy = clone $this;
        $ids = array_map(fn ($r) => $r->id, $resources);
        $copy->weeklyRows = WorkingSchedule::where(fn ($q) => $q->whereNull('resource_id')->orWhereIn('resource_id', $ids))->where('active', true)->get();
        $copy->overrideRows = ScheduleOverride::where(fn ($q) => $q->whereNull('resource_id')->orWhereIn('resource_id', $ids))->whereBetween('date', [$from->subDays(2)->toDateString(), $until->addDays(2)->toDateString()])->get();
        $copy->blockRows = ResourceBlock::whereIn('resource_id', $ids)->where('starts_at', '<', $until)->where('ends_at', '>', $from)->orderBy('starts_at')->get();
        $copy->eligibleIds = app(ResourceRegistry::class)->eligible()->whereIn('id', $ids)->pluck('id')->all();
        $result = [];
        foreach ($resources as $resource) {
            $result[$resource->id] = $copy->windows($from, $until, $resource);
        }

        return $result;
    }

    public function windows(CarbonImmutable $from, CarbonImmutable $until, ?Resource $resource = null): array
    {
        if ($until <= $from || $from->diffInDays($until) > 93) {
            throw new InvalidArgumentException('Query a positive interval of at most 93 days.');
        }
        if ($resource && ! ($this->eligibleIds !== null ? in_array($resource->id, $this->eligibleIds, true) : app(ResourceRegistry::class)->eligible()->whereKey($resource->id)->exists())) {
            return [];
        }
        $company = $this->scopeWindows($from, $until, null);
        if (! $resource) {
            return $this->serialize($company);
        }
        $personal = $this->scopeWindows($from, $until, $resource->id);
        $windows = [];
        foreach ($company as [$a,$b]) {
            foreach ($personal as [$c,$d]) {
                if (max($a, $c) < min($b, $d)) {
                    $windows[] = [max($a, $c), min($b, $d)];
                }
            }
        }
        $blocks = $this->blockRows?->where('resource_id', $resource->id) ?? ResourceBlock::where('resource_id', $resource->id)->where('starts_at', '<', $until)->where('ends_at', '>', $from)->orderBy('starts_at')->get();
        foreach ($blocks as $block) {
            $next = [];
            foreach ($windows as [$a,$b]) {
                if ($block->ends_at <= $a || $block->starts_at >= $b) {
                    $next[] = [$a, $b];

                    continue;
                }if ($a < $block->starts_at) {
                    $next[] = [$a, $block->starts_at];
                }if ($block->ends_at < $b) {
                    $next[] = [$block->ends_at, $b];
                }
            }$windows = $next;
        }

        return $this->serialize($windows);
    }

    private function scopeWindows(CarbonImmutable $from, CarbonImmutable $until, ?int $resourceId): array
    {
        $weekly = $this->weeklyRows?->where('resource_id', $resourceId) ?? WorkingSchedule::where('resource_id', $resourceId)->where('active', true)->get();
        $overrides = $this->overrideRows?->where('resource_id', $resourceId) ?? ScheduleOverride::where('resource_id', $resourceId)->whereBetween('date', [$from->subDays(2)->toDateString(), $until->addDays(2)->toDateString()])->get();
        $zones = $weekly->pluck('timezone')->merge($overrides->pluck('timezone'))->unique();
        $windows = [];
        $resolver = app(LocalTimeResolver::class);
        foreach ($zones as $zone) {
            for ($day = $from->setTimezone($zone)->startOfDay()->subDay(); $day <= $until->setTimezone($zone)->startOfDay(); $day = $day->addDay()) {
                $override = $overrides->first(fn ($row) => $row->date->format('Y-m-d') === $day->format('Y-m-d'));
                $schedule = $override ?? $weekly->firstWhere('weekday', $day->dayOfWeek);
                if (! $schedule || $schedule->timezone !== $zone || ($override && $override->closed)) {
                    continue;
                }
                $start = $resolver->resolve($day->format('Y-m-d'), $schedule->opens_at, $zone);
                $end = $resolver->resolve(($schedule->ends_next_day ? $day->addDay() : $day)->format('Y-m-d'), $schedule->closes_at, $zone, true);
                if (! $start || ! $end || $end <= $start) {
                    continue;
                }
                // A closed-date override also suppresses spillover from the previous day's overnight window.
                $nextOverride = $overrides->first(fn ($row) => $row->date->format('Y-m-d') === $day->addDay()->format('Y-m-d'));
                if ($schedule->ends_next_day && $nextOverride?->closed) {
                    $end = $resolver->resolve($day->addDay()->format('Y-m-d'), '00:00', $zone, true) ?? $end;
                }
                $a = max($start, $from);
                $b = min($end, $until);
                if ($a < $b) {
                    $windows[] = [$a, $b];
                }
            }
        }

        return $windows;
    }

    private function serialize(array $windows): array
    {
        usort($windows, fn ($a, $b) => $a[0]->timestamp <=> $b[0]->timestamp);
        $merged = [];
        foreach ($windows as [$start,$end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return array_map(fn ($window) => ['starts_at' => $window[0]->utc()->toIso8601String(), 'ends_at' => $window[1]->utc()->toIso8601String()], $merged);
    }
}
