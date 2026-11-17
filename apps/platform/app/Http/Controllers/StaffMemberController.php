<?php

namespace App\Http\Controllers;

use App\Domain\Resources\SaveStaffMember;
use App\Domain\Resources\StaffMember;
use App\Domain\Tenancy\CompanySettings;
use App\Http\Requests\StaffMemberRequest;
use App\Http\Resources\OperationalRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class StaffMemberController extends Controller
{
    private function authorizeWrite(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->role->managesCompany(), 403);
    }

    private function query(): Builder
    {
        return StaffMember::query()->with(['depot', 'eligibleClasses', 'user']);
    }

    public function index(Request $request): Response
    {
        $this->authorizeWrite($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:40'], 'archived' => ['nullable', 'boolean']]);
        $query = $this->query();
        $query->when($request->boolean('archived'), fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'));
        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters): void {
                $q->whereRaw('LOWER(full_name) LIKE ?', ['%'.strtolower($filters['search']).'%']);
            });
        }
        if (! empty($filters['status'])) {
            $query->where('role', $filters['status']);
        }

        return Inertia::render('operations/Index', ['summary' => [
            ['label' => 'Team members', 'value' => StaffMember::whereNull('archived_at')->count(), 'hint' => 'Company team · excludes archived records'],
            ['label' => 'Active drivers', 'value' => StaffMember::whereNull('archived_at')->where('active', true)->where('role', 'driver')->count(), 'hint' => 'Driver records marked active'],
            ['label' => 'Active movers', 'value' => StaffMember::whereNull('archived_at')->where('active', true)->where('role', 'mover')->count(), 'hint' => 'Mover records marked active'],
            ['label' => 'Inactive members', 'value' => StaffMember::whereNull('archived_at')->where('active', false)->count(), 'hint' => 'Team records marked inactive'],
        ], 'module' => 'staff', 'records' => $query->orderBy('id', 'desc')->paginate(20)->withQueryString()->through(fn ($row) => OperationalRecord::make($row)), 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeWrite($request);

        return Inertia::render('operations/Edit', ['module' => 'staff', 'record' => null, 'companyTimezone' => CompanySettings::value('timezone')]);
    }

    public function edit(Request $request, string $company, string $record): Response
    {
        $this->authorizeWrite($request);

        return Inertia::render('operations/Edit', ['module' => 'staff', 'record' => OperationalRecord::make($this->query()->where('public_id', $record)->firstOrFail())]);
    }

    public function store(StaffMemberRequest $request, SaveStaffMember $action): RedirectResponse
    {
        $model = $action->execute($request->attributesForSave(), $request->user()->id);

        return redirect('/c/'.$request->route('company').'/staff/'.$model->public_id.'/edit')->with('success', 'Saved successfully.');
    }

    public function update(StaffMemberRequest $request, SaveStaffMember $action, string $company, string $record): RedirectResponse
    {
        $model = StaffMember::where('public_id', $record)->firstOrFail();
        $action->execute($request->attributesForSave(), $request->user()->id, $model);

        return back()->with('success', 'Saved successfully.');
    }

    public function destroy(Request $request, SaveStaffMember $action, string $company, string $record): RedirectResponse
    {
        $this->authorizeWrite($request);
        $action->archive(StaffMember::where('public_id', $record)->firstOrFail(), $request->user()->id);

        return redirect('/c/'.$company.'/staff')->with('success', 'Record archived. Historical references are retained.');
    }
}
