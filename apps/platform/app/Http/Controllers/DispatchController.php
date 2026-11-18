<?php

namespace App\Http\Controllers;

use App\Domain\Bookings\Booking;
use App\Domain\Dispatch\CaptureDeliveryProof;
use App\Domain\Dispatch\DeliveryProof;
use App\Domain\Dispatch\DispatchAccess;
use App\Domain\Dispatch\DispatchBooking;
use App\Domain\Dispatch\DispatchJob;
use App\Domain\Dispatch\TransitionJob;
use App\Domain\Resources\StaffMember;
use App\Http\Resources\DispatchView;
use App\Infrastructure\Storage\PrivateTenantFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DispatchController extends Controller
{
    private function access(Request $r, DispatchJob $job): bool
    {
        $operator = $r->attributes->get('membership')->role->value !== 'driver';
        if ($operator) {
            app(DispatchAccess::class)->manage($r->user()->id);
        } else {
            app(DispatchAccess::class)->assigned($job, $r->user()->id);
        }

        return $operator;
    }

    public function index(Request $r, DispatchView $view): Response
    {
        $operator = $r->attributes->get('membership')->role->value !== 'driver';
        $q = DispatchJob::query();
        if ($operator) {
            app(DispatchAccess::class)->manage($r->user()->id);
        } else {
            $ids = app(DispatchAccess::class)->driverIds($r->user()->id);
            $q->assignedTo($ids);
        }
        $filter = $r->validate(['status' => ['nullable', 'in:assigned,accepted,en_route,arrived,loading,in_transit,delivered,completed,recalled']]);
        if ($filter['status'] ?? null) {
            $q->where('status', $filter['status']);
        }
        $jobs = $q->orderByDesc('id')->paginate(20)->withQueryString()->through(fn ($j) => $view->make($j, $operator));

        return Inertia::render('company/dispatch/Index', ['jobs' => $jobs, 'operator' => $operator, 'drivers' => $r->attributes->get('membership')->role->managesCompany() ? StaffMember::where('role', 'driver')->where('active', true)->whereNull('user_id')->whereNull('archived_at')->orderBy('full_name')->limit(100)->get(['public_id', 'full_name', 'email']) : [], 'awaiting' => $operator ? Booking::where('status', 'confirmed')->where('allocation_ends_at', '>', now())->whereNotIn('id', DispatchJob::where('status', '<>', 'recalled')->select('booking_id'))->orderBy('service_starts_at')->limit(50)->get(['public_id', 'reference', 'service_starts_at']) : []]);
    }

    public function store(Request $r, string $company, string $booking): JsonResponse
    {
        $j = app(DispatchBooking::class)->execute(Booking::where('public_id', $booking)->firstOrFail(), $r->user()->id);

        return response()->json(['public_id' => $j->public_id]);
    }

    public function show(Request $r, string $company, string $job, DispatchView $view): Response|JsonResponse
    {
        $j = DispatchJob::where('public_id', $job)->firstOrFail();
        $operator = $this->access($r, $j);
        $data = $view->make($j, $operator);

        return $r->expectsJson() ? response()->json($data) : Inertia::render('company/dispatch/Show', ['job' => $data, 'operator' => $operator]);
    }

    public function transition(Request $r, string $company, string $job, DispatchView $view): JsonResponse
    {
        $j = DispatchJob::where('public_id', $job)->firstOrFail();
        $operator = $this->access($r, $j);
        $v = $r->validate(['status' => ['required', 'string', 'in:accepted,en_route,arrived,loading,in_transit,delivered,completed'], 'version' => ['required', 'integer', 'min:0'], 'idempotency_key' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:2000']]);
        if ($operator) {
            $r->validate(['note' => ['required', 'string', 'min:5', 'max:2000']]);
        }
        $j = app(TransitionJob::class)->execute($j, $v['status'], $v['version'], $v['idempotency_key'], $r->user()->id, $v['note'] ?? null, $operator);

        return response()->json($view->make($j, $operator));
    }

    public function proof(Request $r, string $company, string $job, DispatchView $view): JsonResponse
    {
        $j = DispatchJob::where('public_id', $job)->firstOrFail();
        $this->access($r, $j);
        $v = $r->validate(['recipient_name' => ['required', 'string', 'max:120'], 'recipient_acknowledged' => ['required', 'accepted'], 'notes' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'uuid'], 'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096']]);
        app(CaptureDeliveryProof::class)->execute($j, $v, $r->file('photo'), $r->user()->id);

        return response()->json($view->make($j));
    }

    public function download(Request $r, string $company, string $job, PrivateTenantFiles $files): StreamedResponse
    {
        $j = DispatchJob::where('public_id', $job)->firstOrFail();
        $this->access($r, $j);
        $p = DeliveryProof::where('job_id', $j->id)->firstOrFail();

        return $files->download($p->photo_path, 'delivery-proof.'.match ($p->mime_type) {
            'image/png' => 'png','image/webp' => 'webp',default => 'jpg'
        });
    }
}
