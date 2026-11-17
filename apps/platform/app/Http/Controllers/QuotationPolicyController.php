<?php

namespace App\Http\Controllers;

use App\Domain\Quotes\QuotationPolicies;
use App\Domain\Quotes\QuotationPolicy;
use App\Domain\Quotes\QuotePermissions;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class QuotationPolicyController extends Controller
{
    public function index(Request $request): Response
    {
        app(QuotePermissions::class)->manage($request->user()->id);

        return Inertia::render('company/quotes/Policies', ['services' => TenantService::orderBy('id')->get()->map(fn ($service) => ['public_id' => $service->public_id, 'name' => $service->name, 'policy' => app(QuotationPolicies::class)->forService($service->id)->toArray()])]);
    }

    public function update(Request $request, string $company, string $service): RedirectResponse
    {
        app(QuotePermissions::class)->manage($request->user()->id);
        $rules = ['outside_zone' => ['required', 'in:review,reject'], 'safety_margin_bps' => ['required', 'integer', 'between:0,5000'], 'borderline_bps' => ['required', 'integer', 'between:5000,10000'], 'max_auto_stops' => ['required', 'integer', 'between:0,8'], 'max_auto_stairs' => ['required', 'integer', 'between:0,100'], 'validity_hours' => ['required', 'integer', 'between:1,720'], 'terms' => ['required', 'string', 'max:10000'], 'exclusions' => ['nullable', 'string', 'max:10000']];
        foreach (['automatic_estimates', 'allow_catalogue_estimates', 'allow_customer_measurements', 'allow_cross_zone', 'notifications_enabled'] as $field) {
            $rules[$field] = ['required', 'boolean'];
        }
        $data = $request->validate($rules);
        $model = TenantService::where('public_id', $service)->firstOrFail();
        DB::transaction(function () use ($model, $data, $request): void {
            $policy = QuotationPolicy::updateOrCreate(['service_id' => $model->id], $data);
            app(RecordsAdministrativeChange::class)->record($policy, 'quotation_policy.saved', $request->user()->id);
        });

        return back()->with('success', 'Quotation policy saved.');
    }
}
