<?php

namespace App\Domain\Bookings;

use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use Illuminate\Support\Facades\Validator;

final class BookingPolicy
{
    public const DEFAULTS = ['reviewed' => false, 'hold_minutes' => 15, 'search_days' => 14, 'slot_minutes' => 30, 'arrival_window_minutes' => 30, 'manual_confirmation' => true, 'customer_cancellation' => true, 'cancellation_notice_minutes' => 1440, 'hold_attempts_per_hour' => 3, 'conservative_job_minutes' => null, 'stop_minutes' => 10, 'stairs_minutes' => 5, 'reposition_minutes' => 0];

    public function configuration(): array
    {
        return [...self::DEFAULTS, ...(CompanySettings::value('booking_policy') ?? [])];
    }

    public function forService(TenantService $service): array
    {
        $settings = CompanySettings::firstOrFail();

        return [...$this->configuration(), 'timezone' => $settings->timezone, 'notice_minutes' => max($settings->booking_notice_minutes, $service->notice_minutes), 'buffer_minutes' => $settings->job_buffer_minutes, 'payment_terms' => $settings->payment_terms, 'deposit_basis_points' => $settings->deposit_basis_points, 'booking_enabled' => $settings->booking_enabled && $settings->operational_status === 'active' && $service->active && $service->enabled && $service->publicly_bookable, 'service_rules' => ['weekdays' => [0, 1, 2, 3, 4, 5, 6], 'opens_at' => null, 'closes_at' => null, 'vehicles' => 1, 'minimum_movers' => 0, 'depot_public_id' => null, ...($service->booking_rules ?? [])]];
    }

    public function validate(array $input): array
    {
        return Validator::make($input, [
            'reviewed' => ['required', 'boolean'], 'hold_minutes' => ['required', 'integer', 'between:5,30'], 'search_days' => ['required', 'integer', 'between:1,30'], 'slot_minutes' => ['required', 'integer', 'in:15,30,60'], 'arrival_window_minutes' => ['required', 'integer', 'between:1,120'], 'manual_confirmation' => ['required', 'boolean'], 'customer_cancellation' => ['required', 'boolean'], 'cancellation_notice_minutes' => ['required', 'integer', 'between:0,43200'], 'hold_attempts_per_hour' => ['required', 'integer', 'between:1,5'], 'conservative_job_minutes' => ['nullable', 'integer', 'between:30,2880'], 'stop_minutes' => ['required', 'integer', 'between:0,240'], 'stairs_minutes' => ['required', 'integer', 'between:0,120'], 'reposition_minutes' => ['required', 'integer', 'between:0,720'],
        ])->validate();
    }
}
