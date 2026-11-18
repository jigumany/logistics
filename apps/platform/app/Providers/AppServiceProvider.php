<?php

namespace App\Providers;

use App\Domain\Availability\CalendarWindows;
use App\Domain\Availability\OperatingCalendar;
use App\Domain\Bookings\BookingPaymentVerifier;
use App\Domain\Geography\CoverageLookup;
use App\Domain\Invoicing\InvoiceDocumentRenderer;
use App\Domain\Notifications\DomainEvent;
use App\Domain\Notifications\QueueBookingNotice;
use App\Domain\Notifications\QueueQuoteArtifacts;
use App\Domain\Payments\CheckoutGateway;
use App\Domain\Payments\RecordedBookingPayments;
use App\Domain\Quotes\QuotationDocumentRenderer;
use App\Domain\Routing\AddressProvider;
use App\Domain\Routing\RoutingProvider;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Maps\GoogleAddressProvider;
use App\Infrastructure\Maps\GoogleRoutingProvider;
use App\Infrastructure\Maps\PostgisCoverageLookup;
use App\Infrastructure\Payments\YocoCheckoutGateway;
use App\Infrastructure\Storage\DompdfInvoiceRenderer;
use App\Infrastructure\Storage\DompdfQuoteRenderer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BookingPaymentVerifier::class, RecordedBookingPayments::class);
        $this->app->bind(CheckoutGateway::class, YocoCheckoutGateway::class);
        $this->app->bind(InvoiceDocumentRenderer::class, DompdfInvoiceRenderer::class);
        $this->app->bind(QuotationDocumentRenderer::class, DompdfQuoteRenderer::class);
        $this->app->bind(RoutingProvider::class, GoogleRoutingProvider::class);
        $this->app->bind(AddressProvider::class, GoogleAddressProvider::class);
        $this->app->bind(CoverageLookup::class, PostgisCoverageLookup::class);
        $this->app->bind(OperatingCalendar::class, CalendarWindows::class);
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
    }

    public function boot(): void
    {
        Event::listen(DomainEvent::class, [QueueQuoteArtifacts::class, 'handle']);
        Event::listen(DomainEvent::class, [QueueBookingNotice::class, 'handle']);
        Model::preventLazyLoading(! $this->app->isProduction());
        Gate::define('viewHorizon', fn () => false);
        RateLimiter::for('quote-public', fn (Request $request) => Limit::perMinute(90)->by($request->ip().'|'.$request->route('company')));
        RateLimiter::for('quote-provider', fn (Request $request) => Limit::perMinute(20)->by($request->ip().'|'.$request->route('company')));
        RateLimiter::for('quote-create', fn (Request $request) => Limit::perHour(10)->by($request->ip().'|'.$request->route('company')));
        RateLimiter::for('quote-mutation', fn (Request $request) => Limit::perMinute(15)->by($request->ip().'|'.$request->route('quote')));
        RateLimiter::for('quote-calculation', fn (Request $request) => [Limit::perMinute(3)->by('minute|'.$request->ip().'|'.$request->route('company')), Limit::perHour(30)->by('hour|'.$request->ip().'|'.$request->route('company')), Limit::perHour(10)->by('quote|'.$request->route('quote'))]);
        RateLimiter::for('quote-media', fn (Request $request) => Limit::perMinute(60)->by($request->ip().'|'.$request->route('quote')));
        RateLimiter::for('booking-public', fn (Request $request) => Limit::perMinute(30)->by($request->ip().'|'.$request->route('quote')));
        RateLimiter::for('booking-mutation', fn (Request $request) => Limit::perMinute(15)->by($request->ip().'|'.$request->route('quote')));
        RateLimiter::for('login', fn (Request $request) => [Limit::perMinute(5)->by(hash('sha256', Str::lower((string) $request->input('email')).'|'.$request->ip())), Limit::perMinute(30)->by($request->ip())]);
    }
}
