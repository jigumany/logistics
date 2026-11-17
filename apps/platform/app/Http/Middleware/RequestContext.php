<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();
        Log::withContext(['request_id' => $id]);
        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $id);
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->headers->set('X-Frame-Options', 'DENY');

            return $response;
        } finally {
            Log::withoutContext();
        }
    }
}
