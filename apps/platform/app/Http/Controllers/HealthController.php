<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            Redis::connection()->ping();

            return response()->json(['status' => 'ready']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['status' => 'unavailable'], 503);
        }
    }
}
