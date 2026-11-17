<?php

namespace App\Http\Controllers;

use App\Domain\Fleet\Depot;
use App\Domain\Fleet\VehicleType;
use App\Domain\Identity\Membership;
use App\Domain\Resources\Resource;
use App\Domain\Services\ServiceType;
use App\Domain\Services\TenantServiceCatalogue;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class OperationalLookupController extends Controller
{
    public function __invoke(Request $request, string $company, string $kind): JsonResponse
    {
        abort_unless($request->attributes->get('membership')->role->managesCompany(), 403);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'selected' => ['nullable', 'string', 'max:100']]);
        $search = $data['search'] ?? '';
        $selected = $data['selected'] ?? null;
        if ($kind === 'service-definitions') {
            $items = ServiceType::orderBy('id')->get()->map(fn ($row) => ['value' => $row->key, 'label' => $row->name]);
        } elseif ($kind === 'vehicle-types') {
            $items = collect(Cache::remember('system:vehicle-types:v1', 3600, fn () => VehicleType::where('active', true)->orderBy('name')->limit(50)->get()->toArray()))->map(fn ($row) => ['value' => is_array($row) ? $row['key'] : $row->key, 'label' => (is_array($row) ? $row['name'] : $row->name).(str_starts_with(is_array($row) ? $row['key'] : $row->key, 'demo_') ? ' · demo specifications' : '')]);
        } elseif ($kind === 'depots') {
            $items = Depot::where(fn ($q) => $q->where('active', true)->orWhere('public_id', $selected))->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%'])->orderBy('name')->limit(50)->get()->map(fn ($row) => ['value' => $row->public_id, 'label' => $row->name]);
        } elseif ($kind === 'services') {
            $items = collect(app(TenantServiceCatalogue::class)->enabled())->map(fn ($row) => ['value' => $row['public_id'], 'label' => $row['name']]);
        } elseif ($kind === 'resources') {
            $items = Resource::with(['vehicle', 'staff'])->where(fn ($query) => $query->whereHas('vehicle', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%']))->orWhereHas('staff', fn ($q) => $q->whereRaw('LOWER(full_name) LIKE ?', ['%'.strtolower($search).'%'])))->orderBy('id')->limit(50)->get()->map(fn ($row) => ['value' => $row->public_id, 'label' => $row->label.' ('.$row->kind.')']);
        } elseif ($kind === 'members') {
            $items = User::whereIn('id', Membership::where('tenant_id', app(TenantContext::class)->tenant()->id)->select('user_id'))->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%'])->orderBy('name')->limit(50)->get()->map(fn ($row) => ['value' => $row->public_id, 'label' => $row->name.' — '.$row->email]);
        } else {
            abort(404);
        }

        if ($selected && ! $items->contains(fn ($item) => $item['value'] === $selected)) {
            $row = match ($kind) {
                'depots' => Depot::where('public_id', $selected)->first(),
                'resources' => Resource::with(['vehicle', 'staff'])->where('public_id', $selected)->first(),
                'members' => User::whereIn('id', Membership::where('tenant_id', app(TenantContext::class)->tenant()->id)->select('user_id'))->where('public_id', $selected)->first(),
                default => null,
            };
            if ($row) {
                $items->prepend(['value' => $selected, 'label' => $kind === 'resources' ? $row->getAttribute('label') : $row->getAttribute('name')]);
            }
        }

        return response()->json(['options' => $items]);
    }
}
