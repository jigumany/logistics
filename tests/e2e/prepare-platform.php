<?php

use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../apps/platform/vendor/autoload.php';
$app = require __DIR__.'/../../apps/platform/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(__DIR__.'/../../.cache').DIRECTORY_SEPARATOR.'playwright.sqlite';
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $expected) {
    throw new RuntimeException('Browser tests require their dedicated SQLite database.');
}
\Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode=WAL');
\Illuminate\Support\Facades\DB::statement('PRAGMA synchronous=NORMAL');
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => \Database\Seeders\VehicleTypeCatalogueSeeder::class, '--force' => true]);
$owner = User::create(['name' => 'Browser Owner', 'email' => 'browser-owner@example.test', 'password' => 'browser-tests-only-password']);
$viewer = User::create(['name' => 'Browser Viewer', 'email' => 'browser-viewer@example.test', 'password' => 'browser-tests-only-password']);
$tenant = Tenant::create(['name' => 'Browser Movers', 'slug' => 'browser-movers']);
Membership::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => Role::Owner]);
Membership::create(['tenant_id' => $tenant->id, 'user_id' => $viewer->id, 'role' => Role::Viewer]);
app(TenantContext::class)->set($tenant);
CompanySettings::create(['display_name' => 'Browser Movers', 'contact_email' => 'browser@example.test', 'payment_terms' => 'deposit', 'deposit_basis_points' => 2500]);
TenantService::create(['code' => 'household', 'name' => 'Household moving', 'enabled' => true]);
app(TenantContext::class)->clear();
Tenant::create(['name' => 'Private Company', 'slug' => 'private-company']);
$demo = Tenant::create(['name' => 'Cape Town Demo', 'slug' => 'cape-town-movers']);
Membership::create(['tenant_id' => $demo->id, 'user_id' => $owner->id, 'role' => Role::Owner]);
app(TenantContext::class)->set($demo);
CompanySettings::create(['display_name' => 'Cape Town Demo', 'contact_email' => 'demo@example.test']);
TenantService::create(['code' => 'household', 'name' => 'Household moving', 'enabled' => true, 'active' => true]);
app(\Database\Seeders\DemonstrationPriceBookSeeder::class)->run();
app(TenantContext::class)->clear();
require __DIR__.'/../../apps/platform/tests/Support/QuotationFixtures.php';
require __DIR__.'/../../apps/platform/tests/Support/BookingFixtures.php';
[$bookingTenant,$bookingOwner,$bookingService,$bookingQuotes,$bookingStart]=bookingFixture();
Membership::create(['tenant_id'=>$bookingTenant->id,'user_id'=>$owner->id,'role'=>Role::Owner]);
$input=quoteInput($bookingService,['preferred_date'=>$bookingStart->setTimezone('Africa/Johannesburg')->toDateString(),'preferred_time'=>'09:00']);
$expiryQuote=app(\App\Domain\Quotes\CreateQuoteDraft::class)->execute($input,(string)\Illuminate\Support\Str::uuid());
$expiryQuote=app(\App\Domain\Quotes\CalculateQuote::class)->execute($expiryQuote,0);
$expiryQuote=app(\App\Domain\Quotes\SubmitQuote::class)->execute($expiryQuote,[...quoteContact(),'email'=>'expiry-browser@example.test'],$expiryQuote->lock_version);
$expiryQuote=app(\App\Domain\Quotes\AcceptQuote::class)->execute($expiryQuote,$expiryQuote->currentRevision->public_id);
file_put_contents(__DIR__.'/../../.cache/booking-browser.json',json_encode(['date'=>$bookingStart->setTimezone('Africa/Johannesburg')->toDateString(),'start'=>$bookingStart->toIso8601String(),'customers'=>array_map(fn($quote)=>app(\App\Domain\Bookings\GuestBookingLinks::class)->forQuote($quote),[...$bookingQuotes,$expiryQuote])],JSON_THROW_ON_ERROR));

$dispatchStart=$bookingStart->addDay();
$dispatchInput=quoteInput($bookingService,['preferred_date'=>$dispatchStart->setTimezone('Africa/Johannesburg')->toDateString(),'preferred_time'=>'09:00']);
$dispatchQuote=app(\App\Domain\Quotes\CreateQuoteDraft::class)->execute($dispatchInput,(string)\Illuminate\Support\Str::uuid());
$dispatchQuote=app(\App\Domain\Quotes\CalculateQuote::class)->execute($dispatchQuote,0);
$dispatchQuote=app(\App\Domain\Quotes\SubmitQuote::class)->execute($dispatchQuote,[...quoteContact(),'email'=>'dispatch-browser@example.test'],$dispatchQuote->lock_version);
$dispatchQuote=app(\App\Domain\Quotes\AcceptQuote::class)->execute($dispatchQuote,$dispatchQuote->currentRevision->public_id);
$dispatchHold=app(\App\Domain\Bookings\CreateBookingHold::class)->execute($dispatchQuote,$dispatchStart,(string)\Illuminate\Support\Str::uuid());
$dispatchBooking=app(\App\Domain\Bookings\ConfirmBooking::class)->execute($dispatchHold,(string)\Illuminate\Support\Str::uuid());
$driverUser=User::create(['name'=>'Browser Driver','email'=>'browser-driver@example.test','password'=>'browser-tests-only-password']);
Membership::create(['tenant_id'=>$bookingTenant->id,'user_id'=>$driverUser->id,'role'=>Role::Driver]);
\App\Domain\Resources\StaffMember::where('role','driver')->firstOrFail()->update(['user_id'=>$driverUser->id]);
CompanySettings::query()->update(['registered_name'=>'Browser Legal Movers Ltd','billing_address'=>'10 Test Road, Cape Town','contact_email'=>'billing@example.test','vat_number'=>'BROWSER-VAT']);
file_put_contents(__DIR__.'/../../.cache/phase5-browser.json',json_encode(['company'=>$bookingTenant->slug,'booking'=>$dispatchBooking->public_id,'reference'=>$dispatchBooking->reference,'guest'=>app(\App\Domain\Bookings\GuestBookingLinks::class)->forQuote($dispatchQuote)],JSON_THROW_ON_ERROR));
app(TenantContext::class)->set($tenant);
$inviteDriver=app(\App\Domain\Resources\SaveStaffMember::class)->execute(['full_name'=>'New Driver Invite','email'=>'new-driver-browser@example.test','role'=>'driver','active'=>true,'engagement_status'=>'employed','licence_category'=>'C1','licence_expires_on'=>now()->addYear()->toDateString(),'eligible_vehicle_classes'=>['light']],$owner->id);
file_put_contents(__DIR__.'/../../.cache/driver-invitation-browser.json',json_encode(['company'=>$tenant->slug,'staff'=>$inviteDriver->public_id],JSON_THROW_ON_ERROR));
app(TenantContext::class)->clear();
