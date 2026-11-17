<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\CompanySettings;
use App\Http\Resources\OperationalRecord;
use Inertia\Inertia;
use Inertia\Response;

final class CompanyProfileController extends Controller
{
    public function __invoke(): Response
    {
        $settings = CompanySettings::with('defaultService')->firstOrFail();
        $data = OperationalRecord::make($settings);
        $data['default_service_public_id'] = $settings->defaultService?->public_id;
        $data['default_service_name'] = $settings->defaultService?->name;
        $data['has_logo'] = $settings->logo_path !== null;
        unset($data['logo_path']);

        return Inertia::render('operations/Edit', ['module' => 'settings', 'record' => $data]);
    }
}
