<?php

namespace App\Http\Controllers;

use App\Domain\Pricing\QuotationReadiness;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\UpdateCompanySettings;
use App\Http\Requests\UpdateCompanySettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CompanyController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('company/Dashboard', ['readiness' => $request->attributes->get('membership')?->role->managesCompany() ? TenantService::where('active', true)->where('enabled', true)->orderBy('id')->get()->map(fn ($service) => app(QuotationReadiness::class)->evaluate($service))->all() : [], 'settings' => CompanySettings::firstOrFail(), 'services' => TenantService::orderBy('code')->get()]);
    }

    public function update(UpdateCompanySettingsRequest $request, UpdateCompanySettings $action): RedirectResponse
    {
        $action->execute($request->attributesForSave(), $request->user()->id);

        return back()->with('success', 'Company settings saved.');
    }
}
