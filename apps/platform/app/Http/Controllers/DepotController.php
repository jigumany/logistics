<?php

namespace App\Http\Controllers;

use App\Domain\Fleet\Depot;
use App\Domain\Fleet\SaveDepot;
use App\Domain\Tenancy\CompanySettings;
use App\Http\Requests\DepotRequest;
use App\Http\Resources\OperationalRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DepotController extends Controller
{
    private function authorizeWrite(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->role->managesCompany(), 403);
    }

    private function query(): Builder
    {
        return Depot::query();
    }

    public function index(Request $request): Response
    {

        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:40'], 'archived' => ['nullable', 'boolean']]);
        $query = $this->query();
        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters): void {
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($filters['search']).'%']);
            });
        }
        if (! empty($filters['status'])) {
            $query->where('active', $filters['status'] === 'active');
        }

        return Inertia::render('operations/Index', ['module' => 'depots', 'records' => $query->orderBy('id', 'desc')->paginate(20)->withQueryString()->through(fn ($row) => OperationalRecord::make($row)), 'filters' => $filters]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeWrite($request);

        return Inertia::render('operations/Edit', ['module' => 'depots', 'record' => null, 'companyTimezone' => CompanySettings::value('timezone')]);
    }

    public function edit(Request $request, string $company, string $record): Response
    {

        return Inertia::render('operations/Edit', ['module' => 'depots', 'record' => OperationalRecord::make($this->query()->where('public_id', $record)->firstOrFail())]);
    }

    public function store(DepotRequest $request, SaveDepot $action): RedirectResponse
    {
        $model = $action->execute($request->attributesForSave(), $request->user()->id);

        return redirect('/c/'.$request->route('company').'/depots/'.$model->public_id.'/edit')->with('success', 'Saved successfully.');
    }

    public function update(DepotRequest $request, SaveDepot $action, string $company, string $record): RedirectResponse
    {
        $model = Depot::where('public_id', $record)->firstOrFail();
        $action->execute($request->attributesForSave(), $request->user()->id, $model);

        return back()->with('success', 'Saved successfully.');
    }
}
