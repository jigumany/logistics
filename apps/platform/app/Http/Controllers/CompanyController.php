<?php

namespace App\Http\Controllers;

use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\UpdateCompanySettings;
use App\Http\Requests\UpdateCompanySettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class CompanyController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('company/Dashboard', ['settings' => CompanySettings::firstOrFail(), 'services' => TenantService::orderBy('code')->get()]);
    }

    public function update(UpdateCompanySettingsRequest $request, UpdateCompanySettings $action): RedirectResponse
    {
        $action->execute($request->attributesForSave(), $request->user()->id);

        return back()->with('success', 'Company settings saved.');
    }
}
