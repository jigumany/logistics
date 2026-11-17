<?php

namespace App\Http\Controllers;

use App\Domain\Bookings\BookingOperations;
use App\Domain\Quotes\Quote;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\BookingView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class GuestBookingController extends Controller
{
    private function quote(string $id): Quote
    {
        return Quote::where('public_id', $id)->whereNull('simulation_price_book_id')->where('status', 'accepted')->whereNotNull('customer_id')->firstOrFail();
    }

    public function show(Request $request, string $company, string $quote, BookingView $view): Response|JsonResponse
    {
        $data = $view->forQuote($this->quote($quote));

        return $request->expectsJson() ? response()->json($data) : Inertia::render('customer/Booking', ['business' => app(TenantContext::class)->tenant()->only('name', 'slug'), 'initial' => $data]);
    }

    public function operate(Request $request, string $company, string $quote, string $operation, BookingOperations $actions, BookingView $view): JsonResponse
    {
        $model = $this->quote($quote);
        $result = $actions->execute($model, $operation, $request->all());

        return response()->json($operation === 'availability' ? $result : $view->forQuote($model->fresh()));
    }
}
