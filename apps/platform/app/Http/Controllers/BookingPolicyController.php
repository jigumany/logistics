<?php

namespace App\Http\Controllers;

use App\Domain\Bookings\BookingPolicy;
use App\Domain\Bookings\SchedulingTransaction;
use App\Domain\Fleet\Depot;
use App\Domain\Quotes\QuotePermissions;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class BookingPolicyController extends Controller
{
    public function show(Request $request): Response
    {
        app(QuotePermissions::class)->manage($request->user()->id);

        return Inertia::render('company/bookings/Policy', ['configuration' => app(BookingPolicy::class)->configuration(), 'bookingEnabled' => CompanySettings::value('booking_enabled'), 'paymentTerms' => CompanySettings::value('payment_terms'), 'services' => TenantService::get()->map(fn (TenantService $s): array => ['public_id' => $s->public_id, 'name' => $s->name, 'publicly_bookable' => $s->publicly_bookable, 'manual_approval' => $s->manual_approval, 'notice_minutes' => $s->notice_minutes, 'rules' => ['weekdays' => [0, 1, 2, 3, 4, 5, 6], 'opens_at' => null, 'closes_at' => null, 'vehicles' => 1, 'minimum_movers' => 0, 'depot_public_id' => null, ...($s->booking_rules ?? [])]]), 'depots' => Depot::where('active', true)->where('coordinates_verified', true)->get(['public_id', 'name'])]);
    }

    public function update(Request $request): RedirectResponse
    {
        app(QuotePermissions::class)->manage($request->user()->id);
        $tenantId = app(TenantContext::class)->tenant()->id;
        $data = $request->validate(['configuration' => ['required', 'array'], 'booking_enabled' => ['required', 'boolean'], 'services' => ['required', 'array', 'max:50'], 'services.*.public_id' => ['required', 'distinct', Rule::exists('tenant_services', 'public_id')->where('tenant_id', $tenantId)], 'services.*.publicly_bookable' => ['required', 'boolean'], 'services.*.rules.weekdays' => ['required', 'array', 'min:1', 'max:7'], 'services.*.rules.weekdays.*' => ['integer', 'between:0,6'], 'services.*.rules.opens_at' => ['nullable', 'required_with:services.*.rules.closes_at', 'date_format:H:i'], 'services.*.rules.closes_at' => ['nullable', 'required_with:services.*.rules.opens_at', 'date_format:H:i'], 'services.*.rules.vehicles' => ['required', 'integer', 'between:1,4'], 'services.*.rules.minimum_movers' => ['required', 'integer', 'between:0,20'], 'services.*.rules.depot_public_id' => ['nullable', Rule::exists('depots', 'public_id')->where('tenant_id', $tenantId)->where('active', true)->where('coordinates_verified', true)]]);
        $configuration = app(BookingPolicy::class)->validate($data['configuration']);
        app(SchedulingTransaction::class)->execute(function () use ($data, $configuration, $request) {
            $settings = CompanySettings::firstOrFail();
            $settings->update(['booking_enabled' => $data['booking_enabled'], 'booking_policy' => $configuration]);
            foreach ($data['services'] as $row) {
                $row['rules']['weekdays'] = array_values(array_unique(array_map('intval', $row['rules']['weekdays'])));
                $row['rules']['vehicles'] = (int) $row['rules']['vehicles'];
                $row['rules']['minimum_movers'] = (int) $row['rules']['minimum_movers'];
                TenantService::where('public_id', $row['public_id'])->firstOrFail()->update(['publicly_bookable' => $row['publicly_bookable'], 'booking_rules' => $row['rules']]);
            }
            app(RecordsAdministrativeChange::class)->record($settings, 'booking.policy_updated', $request->user()->id);
        });

        return back()->with('success', 'Booking policy saved. Existing accepted prices remain unchanged.');
    }
}
