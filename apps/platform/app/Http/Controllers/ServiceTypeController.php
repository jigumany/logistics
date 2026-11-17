<?php

namespace App\Http\Controllers;

use App\Domain\Services\CreateTenantService;
use App\Domain\Services\TenantService;
use App\Domain\Services\UpdateServiceType;
use App\Http\Requests\CreateTenantServiceRequest;
use App\Http\Requests\UpdateServiceTypeRequest;
use App\Http\Resources\OperationalRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ServiceTypeController extends Controller
{
    public function create(Request $request): Response
    {
        abort_unless($request->attributes->get('membership')->role->managesCompany(), 403);

        return Inertia::render('operations/Edit', ['module' => 'services', 'record' => null]);
    }

    public function store(CreateTenantServiceRequest $request, CreateTenantService $action): RedirectResponse
    {
        $service = $action->execute($request->validated(), $request->user()->id);

        return redirect('/c/'.$request->route('company').'/services/'.$service->public_id.'/edit')->with('success', 'Service configured.');
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $query = TenantService::with('definition');
        if (! empty($filters['search'])) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($filters['search']).'%']);
        }

        return Inertia::render('operations/Index', ['module' => 'services', 'records' => $query->orderBy('id')->paginate(20)->withQueryString()->through(fn ($row) => OperationalRecord::make($row)), 'filters' => $filters]);
    }

    public function edit(Request $request, string $company, string $record): Response
    {

        return Inertia::render('operations/Edit', ['module' => 'services', 'record' => OperationalRecord::make(TenantService::with('definition')->where('public_id', $record)->firstOrFail())]);
    }

    public function update(UpdateServiceTypeRequest $request, UpdateServiceType $action, string $company, string $service): RedirectResponse
    {
        $action->execute($service, $request->validated(), $request->user()->id);

        return back()->with('success', 'Service updated.');
    }
}
