<?php

namespace App\Http\Controllers;

use App\Domain\Quotes\QuotationPolicies;
use App\Domain\Quotes\QuotationPolicy;
use App\Domain\Quotes\QuotationTerms;
use App\Domain\Quotes\QuotePermissions;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $rules['commercial_terms'] = ['sometimes', 'array'];
        $rules['commercial_terms.status'] = ['required_with:commercial_terms', 'in:draft,reviewed'];
        $rules['commercial_terms.insurance_status'] = ['required_with:commercial_terms', 'in:draft,reviewed'];
        $rules['commercial_terms.declared_value_required'] = ['sometimes', 'boolean'];
        foreach (QuotationTerms::SECTIONS as $field) {
            $rules['commercial_terms.'.$field] = ['nullable', 'string', 'max:5000', 'required_if:commercial_terms.status,reviewed'];
        }
        $data = $request->validate($rules);
        $model = TenantService::where('public_id', $service)->firstOrFail();
        DB::transaction(function () use ($model, $data, $request): void {
            $policy = QuotationPolicy::firstOrNew(['service_id' => $model->id]);
            $policy->fill($data);
            if ($policy->isDirty(['terms', 'exclusions', 'commercial_terms']) || ! $policy->terms_version) {
                $policy->terms_version = (string) Str::ulid();
            }
            if (isset($data['commercial_terms'])) {
                $policy->commercial_terms = [...$data['commercial_terms'], 'reviewed_by' => $data['commercial_terms']['status'] === 'reviewed' ? $request->user()->public_id : null, 'reviewed_at' => $data['commercial_terms']['status'] === 'reviewed' ? now()->toIso8601String() : null];
            }
            $policy->save();
            app(RecordsAdministrativeChange::class)->record($policy, 'quotation_policy.saved', $request->user()->id);
        });

        return back()->with('success', 'Quotation policy saved.');
    }
}
