<?php

namespace App\Http\Controllers;

use App\Domain\Bookings\Booking;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceDocumentRenderer;
use App\Domain\Payments\CreateCheckout;
use App\Domain\Payments\ProcessYocoEvent;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuotePermissions;
use App\Infrastructure\Payments\VerifyYocoWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PaymentController extends Controller
{
    private function customerBooking(string $quote): Booking
    {
        $q = Quote::where('public_id', $quote)->where('status', 'accepted')->whereNull('simulation_price_book_id')->firstOrFail();

        return Booking::where('quote_id', $q->id)->where('customer_id', $q->customer_id)->firstOrFail();
    }

    public function customerCheckout(Request $r, string $company, string $quote): JsonResponse
    {
        return $this->checkout($r, $this->customerBooking($quote));
    }

    public function operatorCheckout(Request $r, string $company, string $booking): JsonResponse
    {
        app(QuotePermissions::class)->manage($r->user()->id);

        return $this->checkout($r, Booking::where('public_id', $booking)->firstOrFail());
    }

    private function checkout(Request $r, Booking $b): JsonResponse
    {
        $v = $r->validate(['idempotency_key' => ['required', 'uuid'], 'purpose' => ['required', 'in:reservation,balance']]);
        $p = app(CreateCheckout::class)->execute($b, $v['idempotency_key'], $v['purpose']);

        return response()->json($p->only(['public_id', 'status', 'amount_minor', 'currency', 'redirect_url']));
    }

    public function webhook(Request $r, VerifyYocoWebhook $verify, ProcessYocoEvent $process): JsonResponse
    {
        $event = $verify->execute($r);
        $process->execute($event, hash('sha256', $r->getContent()));

        return response()->json(['received' => true]);
    }

    public function customerInvoice(Request $r, string $company, string $quote, InvoiceDocumentRenderer $renderer): Response
    {
        $b = $this->customerBooking($quote);
        $invoice = Invoice::where('booking_id', $b->id)->firstOrFail();

        return response($renderer->render($invoice), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
