<?php

namespace App\Http\Controllers;

use App\Domain\Fleet\LoadCapacity;
use App\Domain\Fleet\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoadCapacityController extends Controller
{
    public function __invoke(Request $request, LoadCapacity $capacity, string $company, string $record): JsonResponse
    {
        $data = $request->validate(['volume_m3' => ['required', 'numeric', 'min:0', 'max:1000000'], 'weight_kg' => ['required', 'numeric', 'min:0', 'max:10000000'], 'largest_item' => ['sometimes', 'array', 'size:3'], 'largest_item.*' => ['required', 'numeric', 'gt:0', 'max:1000'], 'restrictions_acknowledged' => ['sometimes', 'boolean']]);

        return response()->json($capacity->evaluate(Vehicle::where('public_id', $record)->firstOrFail(), (float) $data['volume_m3'], (float) $data['weight_kg'], $data['largest_item'] ?? [], $request->boolean('restrictions_acknowledged')));
    }
}
