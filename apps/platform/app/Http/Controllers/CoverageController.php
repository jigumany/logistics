<?php

namespace App\Http\Controllers;

use App\Domain\Geography\CoverageLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CoverageController extends Controller
{
    public function __invoke(Request $request, CoverageLookup $lookup): JsonResponse
    {
        $data = $request->validate(['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'purpose' => ['required', Rule::in(['pickup', 'delivery'])]]);

        return response()->json($lookup->check((float) $data['latitude'], (float) $data['longitude'], $data['purpose']));
    }
}
