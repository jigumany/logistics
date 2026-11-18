<?php

use App\Domain\Bookings\BookingConflict;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\ResolvePublicTenant;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestContext::class);
        $middleware->validateCsrfTokens(except: ['webhooks/yoco/*']);
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias(['tenant' => ResolveTenant::class, 'public-tenant' => ResolvePublicTenant::class]);
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (BookingConflict $error, Request $request) => response()->json(['message' => $error->getMessage(), 'reasons' => $error->reasons, 'alternative_action' => 'search_availability'], 409));
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'secret_key', 'webhook_secret']);
    })->create();
