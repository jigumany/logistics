<?php

namespace App\Http\Controllers;

use App\Domain\Availability\OperatingCalendar;
use App\Domain\Availability\ResourceBlock;
use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingChangeRequest;
use App\Domain\Bookings\BookingHold;
use App\Domain\Bookings\BookingOperations;
use App\Domain\Bookings\BookingPermissions;
use App\Domain\Bookings\ResourceAllocation;
use App\Domain\Customers\Customer;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Resources\Resource;
use App\Domain\Resources\ResourceRegistry;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Http\Resources\BookingView;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BookingController extends Controller
{
    private function authorize(Request $request): void
    {
        app(BookingPermissions::class)->manage($request->user()->id);
    }

    private function resourceOptions(): array
    {
        return app(ResourceRegistry::class)->eligible()->with(['vehicle', 'staff'])->orderBy('id')->limit(2000)->get()->map(fn ($r) => ['public_id' => $r->public_id, 'kind' => $r->kind, 'label' => $r->label])->all();
    }

    public function index(Request $request): Response
    {
        $this->authorize($request);
        $filter = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:pending_confirmation,pending_payment,confirmed,cancelled,expired'], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'service' => ['nullable', 'ulid']]);
        $query = Booking::query();
        if (! empty($filter['status'])) {
            $query->where('status', $filter['status']);
        }
        if (! empty($filter['search'])) {
            $term = '%'.mb_strtolower($filter['search']).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(reference) LIKE ?', [$term])->orWhereIn('customer_id', Customer::whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(email) LIKE ?', [$term])->select('id')));
        }
        $zone = CompanySettings::value('timezone');
        if (! empty($filter['from'])) {
            $query->where('service_starts_at', '>=', CarbonImmutable::parse($filter['from'], $zone)->utc());
        }
        if (! empty($filter['until'])) {
            $query->where('service_starts_at', '<', CarbonImmutable::parse($filter['until'], $zone)->addDay()->utc());
        }
        if (! empty($filter['service'])) {
            $query->whereIn('service_id', TenantService::where('public_id', $filter['service'])->select('id'));
        }
        $pages = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $revisions = QuoteRevision::whereIn('id', $pages->pluck('accepted_revision_id'))->get()->keyBy('id');
        $pages->through(fn ($b) => [...$b->only('public_id', 'reference', 'status', 'financial_state', 'service_starts_at', 'service_ends_at'), 'customer' => $revisions->get($b->accepted_revision_id)?->contact_snapshot['name'] ?? '', 'service' => $revisions->get($b->accepted_revision_id)?->service_name, 'total_minor' => $revisions->get($b->accepted_revision_id)?->total_minor, 'currency' => $b->currency]);
        $holds = BookingHold::where('status', 'active')->where('expires_at', '>', now())->orderBy('expires_at')->limit(20)->get();
        $quotes = Quote::whereIn('id', $holds->pluck('quote_id'))->get()->keyBy('id');

        return Inertia::render('company/bookings/Index', ['bookings' => $pages, 'filters' => $filter, 'timezone' => $zone, 'services' => TenantService::get(['public_id', 'name']), 'holds' => $holds->map(fn ($h) => [...$h->only('public_id', 'status', 'purpose', 'expires_at', 'service_starts_at'), 'quote_public_id' => $quotes->get($h->quote_id)?->public_id])->all()]);
    }

    public function quote(Request $request, string $company, string $quote, BookingView $view): Response|JsonResponse
    {
        $this->authorize($request);
        $model = Quote::where('public_id', $quote)->where('status', 'accepted')->whereNull('simulation_price_book_id')->firstOrFail();
        if ($request->expectsJson()) {
            return response()->json($view->forQuote($model, true));
        }

        return Inertia::render('company/bookings/Show', ['resourceOptions' => $this->resourceOptions(), 'initial' => $view->forQuote($model, true), 'operationBase' => '/c/'.$company.'/bookings/quote/'.$quote]);
    }

    public function show(Request $request, string $company, string $booking, BookingView $view): Response
    {
        $this->authorize($request);
        $model = Booking::where('public_id', $booking)->firstOrFail();
        $quote = Quote::whereKey($model->quote_id)->firstOrFail();

        return Inertia::render('company/bookings/Show', ['resourceOptions' => $this->resourceOptions(), 'initial' => $view->forQuote($quote, true), 'operationBase' => '/c/'.$company.'/bookings/quote/'.$quote->public_id]);
    }

    public function operate(Request $request, string $company, string $quote, string $operation, BookingOperations $actions, BookingView $view): JsonResponse
    {
        $this->authorize($request);
        $model = Quote::where('public_id', $quote)->where('status', 'accepted')->whereNull('simulation_price_book_id')->firstOrFail();
        $result = $actions->execute($model, $operation, $request->all(), $request->user()->id);

        return response()->json($operation === 'availability' ? $result : $view->forQuote($model->fresh(), true));
    }

    public function planner(Request $request): Response
    {
        $this->authorize($request);
        $filter = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'view' => ['nullable', 'in:day,week'], 'kind' => ['nullable', 'in:vehicle,staff'], 'resource' => ['nullable', 'ulid'], 'status' => ['nullable', 'in:held,confirmed']]);
        $zone = CompanySettings::value('timezone');
        $from = CarbonImmutable::parse($filter['date'] ?? now($zone)->toDateString(), $zone)->startOfDay();
        $days = ($filter['view'] ?? 'week') === 'day' ? 1 : 7;
        $until = $from->addDays($days);
        $resourceQuery = Resource::with(['vehicle', 'staff'])->when(($filter['kind'] ?? 'vehicle') === 'vehicle', fn ($q) => $q->where('kind', 'vehicle'), fn ($q) => $q->whereIn('kind', ['driver', 'mover']))->when(! empty($filter['resource']), fn ($q) => $q->where('public_id', $filter['resource']));
        $resourcePages = $resourceQuery->orderBy('id')->paginate(50, ['*'], 'resources_page')->withQueryString();
        $resources = collect($resourcePages->items());
        $allocations = ResourceAllocation::whereIn('resource_id', $resources->pluck('id'))->whereIn('status', ['held', 'confirmed'])->where('starts_at', '<', $until)->where('ends_at', '>', $from)->where(fn ($q) => $q->where('status', 'confirmed')->orWhereIn('hold_id', BookingHold::where('status', 'active')->where('expires_at', '>', now())->select('id')))->when(! empty($filter['status']), fn ($q) => $q->where('status', $filter['status']))->orderBy('starts_at')->get();
        $holds = BookingHold::whereIn('id', $allocations->pluck('hold_id'))->get()->keyBy('id');
        $books = Booking::whereIn('id', $allocations->pluck('booking_id')->filter())->get()->keyBy('id');
        $quotes = Quote::whereIn('id', $holds->pluck('quote_id'))->get()->keyBy('id');
        $events = $allocations->map(function ($a) use ($resources, $holds, $books, $quotes) {
            $hold = $holds->get($a->hold_id);
            $book = $books->get($a->booking_id);

            return ['public_id' => $a->public_id, 'resource_public_id' => $resources->firstWhere('id', $a->resource_id)?->public_id, 'starts_at' => $a->starts_at, 'ends_at' => $a->ends_at, 'pickup_at' => ($book ? $book->service_starts_at : $hold?->service_starts_at), 'service_minutes' => $hold?->duration_snapshot['service_minutes'] ?? 0, 'state' => $a->status, 'label' => ($book ? $book->reference : 'Temporary hold'), 'booking_public_id' => $book?->public_id, 'quote_public_id' => $quotes->get($hold?->quote_id)?->public_id];
        })->all();
        foreach (ResourceBlock::whereIn('resource_id', $resources->pluck('id'))->where('starts_at', '<', $until)->where('ends_at', '>', $from)->get() as $block) {
            $events[] = ['public_id' => $block->public_id, 'resource_public_id' => $resources->firstWhere('id', $block->resource_id)?->public_id, 'starts_at' => $block->starts_at, 'ends_at' => $block->ends_at, 'state' => 'blocked', 'label' => ucfirst($block->reason)];
        }
        $windows = app(OperatingCalendar::class)->batch($from, $until, $resources->all());
        $resourcePages->through(function ($r) use ($allocations, $windows, $from, $until) {
            $available = array_sum(array_map(fn ($w) => CarbonImmutable::parse($w['starts_at'])->diffInMinutes(CarbonImmutable::parse($w['ends_at'])), $windows[$r->id] ?? []));
            $used = $allocations->where('resource_id', $r->id)->sum(fn ($a) => max($from, $a->starts_at)->diffInMinutes(min($until, $a->ends_at)));

            return ['public_id' => $r->public_id, 'kind' => $r->kind, 'label' => $r->label, 'active' => $r->active, 'utilisation_percent' => $available > 0 ? min(100, (int) round($used / $available * 100)) : null];
        });

        return Inertia::render('company/bookings/Planner', ['resources' => $resourcePages, 'events' => $events, 'filters' => $filter, 'date' => $from->toDateString(), 'days' => $days, 'timezone' => $zone, 'conflicts' => BookingChangeRequest::where('kind', 'hold_conflict')->where('created_at', '>=', now()->subDays(7))->orderByDesc('id')->limit(20)->get(['reason', 'details', 'created_at'])]);
    }
}
