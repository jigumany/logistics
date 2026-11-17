<?php

namespace App\Http\Controllers;

use App\Domain\Availability\ResourceBlock;
use App\Domain\Availability\SaveCalendarConfiguration;
use App\Domain\Availability\ScheduleOverride;
use App\Domain\Availability\WorkingSchedule;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Http\Requests\CalendarRequest;
use App\Http\Resources\OperationalRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class CalendarController extends Controller
{
    private function model(string $kind): string
    {
        return match ($kind) {
            'weekly' => WorkingSchedule::class,'overrides' => ScheduleOverride::class,'blocks' => ResourceBlock::class,default => abort(404)
        };
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->role->managesCompany(), 403);
    }

    public function index(Request $request, string $company, string $kind = 'weekly'): Response
    {
        $this->authorizeAdmin($request);
        $model = $this->model($kind);
        $query = $model::with(['resource.vehicle', 'resource.staff']);
        $filter = $request->validate(['resource' => ['nullable', 'string', 'max:26']]);
        if ($request->filled('resource')) {
            $query->whereHas('resource', fn ($q) => $q->where('public_id', $filter['resource']));
        }

        return Inertia::render('operations/Index', ['module' => 'calendar/'.$kind, 'records' => $query->orderByDesc('id')->paginate(20)->withQueryString()->through(fn ($row) => OperationalRecord::make($row)), 'filters' => $filter]);
    }

    public function create(Request $request, string $company, string $kind): Response
    {
        $this->authorizeAdmin($request);
        $this->model($kind);

        return Inertia::render('operations/Edit', ['module' => 'calendar/'.$kind, 'record' => null, 'companyTimezone' => CompanySettings::value('timezone')]);
    }

    public function edit(Request $request, string $company, string $kind, string $record): Response
    {
        $this->authorizeAdmin($request);
        $model = $this->model($kind);

        return Inertia::render('operations/Edit', ['module' => 'calendar/'.$kind, 'record' => OperationalRecord::make($model::with(['resource.vehicle', 'resource.staff'])->where('public_id', $record)->firstOrFail())]);
    }

    public function store(CalendarRequest $request, SaveCalendarConfiguration $action, string $company, string $kind): RedirectResponse
    {
        $model = $action->execute($this->model($kind), $request->attributesForSave(), $request->user()->id);

        return redirect('/c/'.$company.'/calendar/'.$kind.'/'.$model->getAttribute('public_id').'/edit')->with('success', 'Calendar configuration saved.');
    }

    public function update(CalendarRequest $request, SaveCalendarConfiguration $action, string $company, string $kind, string $record): RedirectResponse
    {
        $model = $this->model($kind);
        $action->execute($model, $request->attributesForSave(), $request->user()->id, $model::where('public_id', $record)->firstOrFail());

        return back()->with('success', 'Calendar configuration saved.');
    }

    public function destroy(Request $request, string $company, string $kind, string $record): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $model = $this->model($kind);
        DB::transaction(function () use ($model, $record, $request): void {
            $row = $model::where('public_id', $record)->lockForUpdate()->firstOrFail();
            app(RecordsAdministrativeChange::class)->record($row, 'calendar.removed', $request->user()->id);
            $row->delete();
        });

        return redirect('/c/'.$company.'/calendar/'.$kind)->with('success', 'Calendar configuration removed.');
    }
}
