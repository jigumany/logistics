<?php

use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\BookingPolicy;
use App\Domain\Bookings\CancelBooking;
use App\Domain\Bookings\ConfirmBooking;
use App\Domain\Bookings\CreateBookingHold;
use App\Domain\Bookings\ExpireBookingHolds;
use App\Domain\Bookings\ResourceAllocation;
use App\Domain\Bookings\SchedulingTransaction;
use App\Domain\Dispatch\CaptureDeliveryProof;
use App\Domain\Dispatch\DeliveryProof;
use App\Domain\Dispatch\DispatchBooking;
use App\Domain\Dispatch\DispatchEvent;
use App\Domain\Dispatch\DispatchJob;
use App\Domain\Dispatch\TransitionJob;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceDocumentRenderer;
use App\Domain\Invoicing\IssueInvoice;
use App\Domain\Payments\CreateCheckout;
use App\Domain\Payments\PaymentAccount;
use App\Domain\Payments\PaymentAttempt;
use App\Domain\Payments\PaymentWebhookEvent;
use App\Domain\Payments\ProcessYocoEvent;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Resources\StaffMember;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../Support/BookingFixtures.php';
function phaseFiveFixture(bool $paid = false, bool $manual = false): array
{
    [$tenant,$owner,$service,$quotes,$start] = bookingFixture();
    if ($manual) {
        CompanySettings::query()->update(['booking_policy' => [...BookingPolicy::DEFAULTS, 'reviewed' => true, 'manual_confirmation' => true, 'cancellation_notice_minutes' => 0]]);
    }
    if ($paid) {
        CompanySettings::query()->update(['payment_terms' => 'deposit', 'deposit_basis_points' => 2500]);
    }
    $hold = app(CreateBookingHold::class)->execute($quotes[0], $start, (string) Str::uuid());
    $b = app(ConfirmBooking::class)->execute($hold, (string) Str::uuid());
    $driver = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $driver->id, 'role' => Role::Driver]);
    StaffMember::where('role', 'driver')->firstOrFail()->update(['user_id' => $driver->id]);
    config(['payments.yoco.accounts.'.$tenant->slug => ['mode' => 'test', 'secret_key' => 'sk_test_fixture', 'webhook_secret' => 'whsec_'.base64_encode(str_repeat('s', 32))]]);

    return [$tenant, $owner, $driver, $b, $hold, $quotes, $start];
}
function fakePhaseFiveYoco(): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['payments.yoco.com/api/checkouts' => fn ($r) => Http::response(['id' => 'ch_'.Str::random(12), 'amount' => $r['amount'], 'currency' => $r['currency'], 'processingMode' => 'test', 'redirectUrl' => 'https://c.yoco.com/checkout/test'])]);
}
function phaseFiveEvent(PaymentAttempt $p, string $id = 'evt_test', string $type = 'payment.succeeded'): array
{
    return ['id' => $id, 'type' => $type, 'payload' => ['id' => 'p_'.substr($p->public_id, -12), 'type' => 'payment', 'status' => 'succeeded', 'amount' => $p->amount_minor, 'currency' => $p->currency, 'mode' => $p->mode, 'metadata' => $p->checkout_request['metadata']]];
}
function deliverPhaseFiveEvent(array $e): void
{
    app(ProcessYocoEvent::class)->execute($e, hash('sha256', json_encode($e, JSON_THROW_ON_ERROR)));
}
it('dispatches only a confirmed complete team exactly once and freezes reservation changes', function () {
    [$t,$o,$d,$b] = phaseFiveFixture();
    $j = app(DispatchBooking::class)->execute($b, $o->id);
    expect(app(DispatchBooking::class)->execute($b, $o->id)->id)->toBe($j->id)->and(DispatchJob::count())->toBe(1)->and($j->resource_snapshot)->toHaveCount(3);
    expect(fn () => app(CancelBooking::class)->execute($b, (string) Str::uuid(), 'Attempt cancellation', $b->lock_version, $o->id))->toThrow(BookingConflict::class);
    expect(ResourceAllocation::where('status', 'confirmed')->count())->toBe(3);
});
it('rejects dispatch before payment and rejects unauthorized operators', function () {
    [$t,$o,$d,$b] = phaseFiveFixture(true);
    expect(fn () => app(DispatchBooking::class)->execute($b, $o->id))->toThrow(BookingConflict::class);
    expect(fn () => app(DispatchBooking::class)->execute($b, $d->id))->toThrow(AuthorizationException::class);
});
it('runs the driver lifecycle with immutable acknowledged proof and idempotent updates', function () {
    Storage::fake();
    [$t,$o,$d,$b] = phaseFiveFixture();
    $j = app(DispatchBooking::class)->execute($b, $o->id);
    foreach (['accepted', 'en_route', 'arrived', 'loading', 'in_transit'] as $s) {
        $key = (string) Str::uuid();
        $v = $j->lock_version;
        $j = app(TransitionJob::class)->execute($j, $s, $v, $key, $d->id);
        expect(app(TransitionJob::class)->execute($j, $s, $v, $key, $d->id)->id)->toBe($j->id);
    }
    expect(fn () => app(TransitionJob::class)->execute($j, 'delivered', $j->lock_version, (string) Str::uuid(), $d->id))->toThrow(BookingConflict::class);
    $data = ['recipient_name' => 'Test Recipient', 'recipient_acknowledged' => true, 'notes' => 'All items received', 'idempotency_key' => (string) Str::uuid()];
    $photo = UploadedFile::fake()->image('delivery.jpg');
    $proof = app(CaptureDeliveryProof::class)->execute($j, $data, $photo, $d->id);
    Storage::assertExists($proof->photo_path);
    expect(app(CaptureDeliveryProof::class)->execute($j, $data, $photo, $d->id)->id)->toBe($proof->id)->and(DeliveryProof::count())->toBe(1);
    foreach (['delivered', 'completed'] as $s) {
        $j = app(TransitionJob::class)->execute($j, $s, $j->lock_version, (string) Str::uuid(), $d->id);
    }
    expect($j->status)->toBe('completed')->and($j->completed_at)->not->toBeNull()->and(DispatchEvent::count())->toBe(8);
    expect(fn () => $proof->update(['recipient_name' => 'Changed']))->toThrow(LogicException::class);
});
it('limits drivers to their jobs and does not expose quotation financial data', function () {
    [$t,$o,$d,$b] = phaseFiveFixture();
    $j = app(DispatchBooking::class)->execute($b, $o->id);
    $url = '/c/'.$t->slug.'/dispatch/'.$j->public_id;
    $this->actingAs($d)->get('/c/'.$t->slug.'/dispatch')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('company/dispatch/Index')->has('jobs.data', 1)->where('jobs.data.0.public_id', $j->public_id)->etc());
    $this->actingAs($d)->getJson($url)->assertOk()->assertJsonPath('work_snapshot.reference', $b->reference)->assertJsonMissingPath('work_snapshot.total_minor')->assertJsonMissingPath('work_snapshot.contact.email');
    $this->actingAs($d)->get('/c/'.$t->slug.'/finance')->assertForbidden();
    $stranger = User::factory()->create();
    Membership::create(['tenant_id' => $t->id, 'user_id' => $stranger->id, 'role' => Role::Driver]);
    $this->actingAs($stranger)->getJson($url)->assertForbidden();
});
it('rejects status skips stale commands and overwritten idempotency keys', function () {
    [$t,$o,$d,$b] = phaseFiveFixture();
    $j = app(DispatchBooking::class)->execute($b, $o->id);
    expect(fn () => app(TransitionJob::class)->execute($j, 'loading', 0, (string) Str::uuid(), $d->id))->toThrow(BookingConflict::class);
    $key = (string) Str::uuid();
    $j = app(TransitionJob::class)->execute($j, 'accepted', 0, $key, $d->id);
    expect(fn () => app(TransitionJob::class)->execute($j, 'en_route', 0, (string) Str::uuid(), $d->id))->toThrow(BookingConflict::class);
    expect(fn () => app(TransitionJob::class)->execute($j, 'en_route', 1, $key, $d->id))->toThrow(BookingConflict::class);
});
it('creates idempotent hosted checkout and confirms only verified signed payment evidence', function () {
    [$t,$o,$d,$b] = phaseFiveFixture(true);
    fakePhaseFiveYoco();
    $key = (string) Str::uuid();
    $p = app(CreateCheckout::class)->execute($b, $key, 'reservation');
    expect($p->amount_minor)->toBe($b->required_payment_minor)->and($b->fresh()->status)->toBe('pending_payment');
    expect(app(CreateCheckout::class)->execute($b, $key, 'reservation')->id)->toBe($p->id);
    Http::assertSentCount(1);
    $event = phaseFiveEvent($p);
    deliverPhaseFiveEvent($event);
    deliverPhaseFiveEvent($event);
    expect($b->fresh()->status)->toBe('confirmed')->and($b->fresh()->financial_state)->toBe('verified')->and(PaymentWebhookEvent::count())->toBe(1)->and(PaymentAttempt::where('status', 'succeeded')->count())->toBe(1);
});
it('preserves verified late money without reacquiring expired allocations', function () {
    [$t,$o,$d,$b] = phaseFiveFixture(true);
    fakePhaseFiveYoco();
    $p = app(CreateCheckout::class)->execute($b, (string) Str::uuid(), 'reservation');
    $this->travel(16)->minutes();
    deliverPhaseFiveEvent(phaseFiveEvent($p));
    expect($b->fresh()->status)->toBe('expired')->and($b->fresh()->financial_state)->toBe('manual_resolution')->and($b->fresh()->refund_state)->toBe('review_required')->and(ResourceAllocation::where('status', 'confirmed')->count())->toBe(0)->and($p->fresh()->status)->toBe('succeeded');
});
it('rejects wrong amounts mode currency tenant and reused evidence identifiers', function () {
    [$t,$o,$d,$b] = phaseFiveFixture(true);
    fakePhaseFiveYoco();
    $p = app(CreateCheckout::class)->execute($b, (string) Str::uuid(), 'reservation');
    $e = phaseFiveEvent($p);
    $bad = $e;
    $bad['payload']['amount']++;
    expect(fn () => deliverPhaseFiveEvent($bad))->toThrow(BookingConflict::class);
    $bad = $e;
    $bad['payload']['metadata']['tenantPublicId'] = (string) Str::ulid();
    expect(fn () => deliverPhaseFiveEvent($bad))->toThrow(BookingConflict::class);
    $bad = $e;
    $bad['payload']['mode'] = 'live';
    expect(fn () => deliverPhaseFiveEvent($bad))->toThrow(ValidationException::class);
    deliverPhaseFiveEvent($e);
    $e['payload']['id'] = 'different';
    expect(fn () => deliverPhaseFiveEvent($e))->toThrow(BookingConflict::class);
});
it('validates raw webhook signatures timestamp and duplicate delivery through HTTP', function () {
    [$t,$o,$d,$b] = phaseFiveFixture(true);
    fakePhaseFiveYoco();
    $p = app(CreateCheckout::class)->execute($b, (string) Str::uuid(), 'reservation');
    $raw = json_encode(phaseFiveEvent($p), JSON_THROW_ON_ERROR);
    $ts = (string) now()->timestamp;
    $id = 'msg_fixture';
    $sig = base64_encode(hash_hmac('sha256', $id.'.'.$ts.'.'.$raw, str_repeat('s', 32), true));
    $url = '/webhooks/yoco/'.$t->slug;
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_WEBHOOK_ID' => $id, 'HTTP_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_WEBHOOK_SIGNATURE' => 'v1,bad'], $raw)->assertForbidden();
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_WEBHOOK_ID' => $id, 'HTTP_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_WEBHOOK_SIGNATURE' => 'v1,'.$sig];
    $this->call('POST', $url, [], [], [], $headers, $raw)->assertOk();
    $this->call('POST', $url, [], [], [], $headers, $raw)->assertOk();
    app(TenantContext::class)->set($t);
    expect(PaymentWebhookEvent::count())->toBe(1);
    $headers['HTTP_WEBHOOK_TIMESTAMP'] = (string) (now()->timestamp - 400);
    $this->call('POST', $url, [], [], [], $headers, $raw)->assertForbidden();
});
it('does not downgrade a paid checkout when failed events arrive out of order', function () {
    [$t,$o,$d,$b] = phaseFiveFixture(true);
    fakePhaseFiveYoco();
    $p = app(CreateCheckout::class)->execute($b, (string) Str::uuid(), 'reservation');
    deliverPhaseFiveEvent(phaseFiveEvent($p));
    deliverPhaseFiveEvent(phaseFiveEvent($p, 'evt_failure', 'payment.failed'));
    expect($p->fresh()->status)->toBe('succeeded')->and($b->fresh()->status)->toBe('confirmed');
});
it('preserves retryable checkout after gateway timeout and prevents a second concurrent checkout', function () {
    [$t,$o,$d,$b] = phaseFiveFixture(true);
    Http::fake(['*' => Http::response([], 503)]);
    $key = (string) Str::uuid();
    expect(fn () => app(CreateCheckout::class)->execute($b, $key, 'reservation'))->toThrow(BookingConflict::class);
    expect(PaymentAttempt::count())->toBe(1);
    expect(fn () => app(CreateCheckout::class)->execute($b, (string) Str::uuid(), 'reservation'))->toThrow(BookingConflict::class);
    fakePhaseFiveYoco();
    expect(app(CreateCheckout::class)->execute($b, $key, 'reservation')->status)->toBe('ready');
});
it('issues one immutable itemised invoice from accepted totals and renders a private PDF', function () {
    [$t,$o,$d,$b] = phaseFiveFixture();
    CompanySettings::query()->update(['registered_name' => 'Test Movers Ltd', 'billing_address' => '10 Test Road, Cape Town', 'contact_email' => 'billing@example.test', 'vat_number' => 'TEST-VAT']);
    $i = app(IssueInvoice::class)->execute($b, $o->id, now()->addDays(7)->toDateString());
    expect(app(IssueInvoice::class)->execute($b, $o->id, now()->addDays(8)->toDateString())->id)->toBe($i->id)->and(Invoice::count())->toBe(1)->and($i->total_minor)->toBe($b->fresh()->accepted_revision_id ? QuoteRevision::findOrFail($b->accepted_revision_id)->total_minor : 0)->and($i->lines)->not->toBeEmpty();
    CompanySettings::query()->update(['registered_name' => 'Changed Company']);
    expect($i->fresh()->supplier_snapshot['registered_name'])->toBe('Test Movers Ltd');
    expect(fn () => $i->update(['total_minor' => 1]))->toThrow(LogicException::class);
    expect(app(InvoiceDocumentRenderer::class)->render($i))->toStartWith('%PDF-');
    $this->actingAs($d)->get('/c/'.$t->slug.'/invoices/'.$i->public_id.'/pdf')->assertForbidden();
});
it('requires supplier identity and finance authority before issuing invoices', function () {
    [$t,$o,$d,$b] = phaseFiveFixture();
    CompanySettings::query()->update(['registered_name' => null]);
    expect(fn () => app(IssueInvoice::class)->execute($b, $o->id, now()->toDateString()))->toThrow(BookingConflict::class);
    expect(fn () => app(IssueInvoice::class)->execute($b, $d->id, now()->toDateString()))->toThrow(AuthorizationException::class);
});
it('protects tenant foreign keys and raw immutable evidence in PostgreSQL', function () {
    [$t,$o,$d,$b] = phaseFiveFixture();
    $j = app(DispatchBooking::class)->execute($b, $o->id);
    if (DB::getDriverName() !== 'pgsql') {
        return;
    }
    expect(function () use ($j) {
        DB::transaction(fn () => DB::table('dispatch_events')->where('job_id', $j->id)->update(['note' => 'tamper']));
    })->toThrow(QueryException::class);
    expect(function () use ($b) {
        DB::transaction(fn () => DB::table('bookings')->where('id', $b->id)->increment('lock_version'));
    })->toThrow(QueryException::class);
});
it('recalls only before departure and preserves history when redispatching', function () {
    [$t,$o,$d,$b] = phaseFiveFixture();
    $j = app(DispatchBooking::class)->execute($b, $o->id);
    $j = app(TransitionJob::class)->execute($j, 'recalled', 0, (string) Str::uuid(), $o->id, 'Recall to correct team.', true);
    expect($j->status)->toBe('recalled');
    $new = app(DispatchBooking::class)->execute($b, $o->id);
    expect($new->id)->not->toBe($j->id)->and(DispatchJob::count())->toBe(2);
    $new = app(TransitionJob::class)->execute($new, 'accepted', 0, (string) Str::uuid(), $d->id);
    $new = app(TransitionJob::class)->execute($new, 'en_route', 1, (string) Str::uuid(), $d->id);
    expect(fn () => app(TransitionJob::class)->execute($new, 'recalled', 2, (string) Str::uuid(), $o->id, 'Already departed.', true))->toThrow(BookingConflict::class);
});
it('handles checkout expiry without losing a later successful payment', function () {
    [$t,$o,$d,$b] = phaseFiveFixture(true);
    fakePhaseFiveYoco();
    $p = app(CreateCheckout::class)->execute($b, (string) Str::uuid(), 'reservation');
    $e = ['id' => 'evt_expiry', 'type' => 'checkout.expired', 'payload' => ['id' => $p->provider_checkout_id, 'type' => 'checkout', 'status' => 'expired', 'amount' => $p->amount_minor, 'currency' => $p->currency, 'mode' => $p->mode]];
    deliverPhaseFiveEvent($e);
    deliverPhaseFiveEvent($e);
    expect($p->fresh()->status)->toBe('failed');
    deliverPhaseFiveEvent(phaseFiveEvent($p));
    expect($p->fresh()->status)->toBe('succeeded')->and($b->fresh()->status)->toBe('confirmed');
});
it('rejects unacknowledged proof and invalid uploads without retaining files', function () {
    Storage::fake();
    [$t,$o,$d,$b] = phaseFiveFixture();
    $j = app(DispatchBooking::class)->execute($b, $o->id);
    expect(fn () => app(CaptureDeliveryProof::class)->execute($j, ['recipient_name' => 'Recipient', 'recipient_acknowledged' => false, 'idempotency_key' => (string) Str::uuid()], UploadedFile::fake()->image('pod.jpg'), $d->id))->toThrow(BookingConflict::class);
    $this->actingAs($d)->postJson('/c/'.$t->slug.'/dispatch/'.$j->public_id.'/proof', ['recipient_name' => 'Recipient', 'recipient_acknowledged' => true, 'idempotency_key' => (string) Str::uuid(), 'photo' => UploadedFile::fake()->create('not-an-image.txt', 1, 'text/plain')])->assertUnprocessable();
    expect(Storage::allFiles())->toBe([]);
});
it('collects only the remaining balance without changing invoice totals', function () {
    [$t,$o,$d,$b] = phaseFiveFixture(true);
    fakePhaseFiveYoco();
    $p = app(CreateCheckout::class)->execute($b, (string) Str::uuid(), 'reservation');
    deliverPhaseFiveEvent(phaseFiveEvent($p));
    $total = QuoteRevision::findOrFail($b->accepted_revision_id)->total_minor;
    $balance = app(CreateCheckout::class)->execute($b->fresh(), (string) Str::uuid(), 'balance');
    expect($balance->amount_minor)->toBe($total - $p->amount_minor);
    deliverPhaseFiveEvent(phaseFiveEvent($balance, 'evt_balance'));
    expect((int) PaymentAttempt::where('status', 'succeeded')->sum('amount_minor'))->toBe($total)->and($b->fresh()->status)->toBe('confirmed');
    expect(fn () => app(CreateCheckout::class)->execute($b->fresh(), (string) Str::uuid(), 'balance'))->toThrow(BookingConflict::class);
});
it('keeps the second customers confirmed allocation when a first customer pays too late', function () {
    [$t,$o,$d,$b,$hold,$quotes,$start] = phaseFiveFixture(true);
    fakePhaseFiveYoco();
    $p = app(CreateCheckout::class)->execute($b, (string) Str::uuid(), 'reservation');
    $this->travel(16)->minutes();
    CompanySettings::query()->update(['payment_terms' => 'invoice']);
    $replacement = app(CreateBookingHold::class)->execute($quotes[1], $start, (string) Str::uuid());
    $winner = app(ConfirmBooking::class)->execute($replacement, (string) Str::uuid());
    deliverPhaseFiveEvent(phaseFiveEvent($p));
    expect($b->fresh()->status)->toBe('expired')->and($winner->fresh()->status)->toBe('confirmed')->and(ResourceAllocation::where('booking_id', $winner->id)->where('status', 'confirmed')->count())->toBe(3)->and(ResourceAllocation::where('booking_id', $b->id)->where('status', 'confirmed')->count())->toBe(0);
});
it('rejects cross tenant job access and database references', function () {
    [$t,$o,$d,$b] = phaseFiveFixture();
    $j = app(DispatchBooking::class)->execute($b, $o->id);
    $other = Tenant::create(['name' => 'Other Dispatch Company', 'slug' => 'other-dispatch-company']);
    Membership::create(['tenant_id' => $other->id, 'user_id' => $o->id, 'role' => Role::Owner]);
    $this->actingAs($o)->getJson('/c/'.$other->slug.'/dispatch/'.$j->public_id)->assertNotFound();
    app(TenantContext::class)->set($other);
    if (DB::getDriverName() === 'pgsql') {
        expect(fn () => DB::transaction(fn () => DispatchJob::create(['booking_id' => $b->id, 'booking_version' => $b->lock_version, 'status' => 'assigned', 'resource_snapshot' => [], 'work_snapshot' => []])))->toThrow(QueryException::class);
    }
});
it('encrypts Yoco credentials and exposes only configuration status to administrators', function () {
    [$t,$o,$d,$b] = phaseFiveFixture();
    $secret = 'sk_test_private_fixture';
    $webhook = 'whsec_'.base64_encode(str_repeat('w', 32));
    $this->actingAs($o)->putJson('/c/'.$t->slug.'/finance/yoco', ['mode' => 'test', 'secret_key' => $secret, 'webhook_secret' => $webhook])->assertOk();
    app(TenantContext::class)->set($t);
    $raw = DB::table('payment_accounts')->where('tenant_id', $t->id)->first();
    expect($raw->secret_key)->not->toBe($secret)->and($raw->webhook_secret)->not->toBe($webhook);
    $account = PaymentAccount::firstOrFail();
    expect($account->secret_key)->toBe($secret)->and($account->toArray())->not->toHaveKeys(['secret_key', 'webhook_secret']);
    $this->actingAs($d)->putJson('/c/'.$t->slug.'/finance/yoco', ['mode' => 'live', 'secret_key' => 'sk_live_attempt', 'webhook_secret' => $webhook])->assertForbidden();
});
it('flags paid manual approval holds for financial review when expired or released', function (string $operation) {
    [$t,$o,$d,$b,$hold] = phaseFiveFixture(true, true);
    fakePhaseFiveYoco();
    $p = app(CreateCheckout::class)->execute($b, (string) Str::uuid(), 'reservation');
    deliverPhaseFiveEvent(phaseFiveEvent($p));
    expect($b->fresh()->status)->toBe('pending_confirmation');
    if ($operation === 'expire') {
        $this->travel(16)->minutes();
    }app(SchedulingTransaction::class)->execute(function () use ($operation, $hold) {
        if ($operation === 'expire') {
            app(ExpireBookingHolds::class)->execute();
        } else {
            app(ExpireBookingHolds::class)->release($hold->fresh());
        }
    });
    expect($b->fresh()->status)->toBe('expired')->and($b->fresh()->financial_state)->toBe('manual_resolution')->and($b->fresh()->refund_state)->toBe('review_required')->and(ResourceAllocation::where('status', 'confirmed')->count())->toBe(0);
})->with(['expire', 'release']);
it('does not dispatch a historical reservation as if it allocated resources today', function () {
    [$t,$o,$d,$b] = phaseFiveFixture();
    $this->travelTo($b->allocation_ends_at->addMinute());
    expect(fn () => app(DispatchBooking::class)->execute($b, $o->id))->toThrow(BookingConflict::class);
    expect(DispatchJob::count())->toBe(0);
});
it('does not offer an existing reservation checkout after its hold expires', function () {
    [$t,$o,$d,$b] = phaseFiveFixture(true);
    fakePhaseFiveYoco();
    $key = (string) Str::uuid();
    app(CreateCheckout::class)->execute($b, $key, 'reservation');
    $this->travel(16)->minutes();
    expect(fn () => app(CreateCheckout::class)->execute($b, $key, 'reservation'))->toThrow(BookingConflict::class);
    Http::assertSentCount(1);
});
