<?php

namespace App\Http\Controllers;

use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\Vehicle;
use App\Domain\Tenancy\CompanySettings;
use App\Http\Requests\VehicleRequest;
use App\Http\Resources\OperationalRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class VehicleController extends Controller
{
    private function authorizeWrite(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->role->managesCompany(), 403);
    }

    private function query(): Builder
    {
        return Vehicle::query()->with(['depot', 'type']);
    }

    public function index(Request $request): Response
    {

        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:40'], 'archived' => ['nullable', 'boolean']]);
        $query = $this->query();
        $query->when($request->boolean('archived'), fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'));
        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters): void {
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($filters['search']).'%'])->orWhere('registration_number', 'like', '%'.strtoupper($filters['search']).'%');
            });
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return Inertia::render('operations/Index', ['summary' => [
            ['label' => 'Total vehicles', 'value' => Vehicle::whereNull('archived_at')->count(), 'hint' => 'Current fleet · excludes archived vehicles'],
            ['label' => 'Available', 'value' => Vehicle::whereNull('archived_at')->where('status', 'available')->count(), 'hint' => 'Availability still requires a calendar check'],
            ['label' => 'In maintenance', 'value' => Vehicle::whereNull('archived_at')->where('status', 'maintenance')->count(), 'hint' => 'Vehicles marked for maintenance'],
            ['label' => 'Inactive / out of service', 'value' => Vehicle::whereNull('archived_at')->whereIn('status', ['inactive', 'out_of_service'])->count(), 'hint' => 'Not ready for operational use'],
        ], 'module' => 'fleet', 'records' => $query->orderBy('id', 'desc')->paginate(20)->withQueryString()->through(fn ($row) => OperationalRecord::make($row)), 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeWrite($request);

        return Inertia::render('operations/Edit', ['module' => 'fleet', 'record' => null, 'companyTimezone' => CompanySettings::value('timezone')]);
    }

    public function edit(Request $request, string $company, string $record): Response
    {

        return Inertia::render('operations/Edit', ['module' => 'fleet', 'record' => OperationalRecord::make($this->query()->where('public_id', $record)->firstOrFail())]);
    }

    public function store(VehicleRequest $request, SaveVehicle $action): RedirectResponse
    {
        $model = $action->execute($request->attributesForSave(), $request->user()->id);

        return redirect('/c/'.$request->route('company').'/fleet/'.$model->public_id.'/edit')->with('success', 'Saved successfully.');
    }

    public function update(VehicleRequest $request, SaveVehicle $action, string $company, string $record): RedirectResponse
    {
        $model = Vehicle::where('public_id', $record)->firstOrFail();
        $action->execute($request->attributesForSave(), $request->user()->id, $model);

        return back()->with('success', 'Saved successfully.');
    }

    public function destroy(Request $request, SaveVehicle $action, string $company, string $record): RedirectResponse
    {
        $this->authorizeWrite($request);
        $action->archive(Vehicle::where('public_id', $record)->firstOrFail(), $request->user()->id);

        return redirect('/c/'.$company.'/fleet')->with('success', 'Record archived. Historical references are retained.');
    }
}
