<?php

use App\Http\Controllers\DispatchController;
use App\Http\Controllers\DriverInvitationController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('/c/{company}')->middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('/dispatch', [DispatchController::class, 'index']);
    Route::post('/dispatch/booking/{booking}', [DispatchController::class, 'store']);
    Route::get('/dispatch/{job}', [DispatchController::class, 'show']);
    Route::post('/dispatch/{job}/transition', [DispatchController::class, 'transition'])->middleware('throttle:60,1');
    Route::post('/dispatch/{job}/proof', [DispatchController::class, 'proof'])->middleware('throttle:20,1');
    Route::get('/dispatch/{job}/proof', [DispatchController::class, 'download']);
    Route::put('/finance/yoco', [FinanceController::class, 'configureYoco'])->middleware('throttle:10,1');
    Route::get('/finance', [FinanceController::class, 'index']);
    Route::post('/finance/{booking}/invoice', [FinanceController::class, 'issue']);
    Route::post('/finance/{booking}/checkout', [PaymentController::class, 'operatorCheckout'])->middleware('throttle:15,1');
    Route::get('/finance/{booking}/payments', [FinanceController::class, 'payments']);
    Route::get('/invoices/{invoice}/pdf', [FinanceController::class, 'pdf']);
});
Route::prefix('/q/{company}/requests/{quote}/booking')->middleware(['public-tenant', 'signed', 'throttle:booking-mutation'])->group(function (): void {
    Route::post('/checkout', [PaymentController::class, 'customerCheckout'])->name('booking.payment.checkout');
    Route::get('/invoice', [PaymentController::class, 'customerInvoice'])->name('booking.payment.invoice');
});
Route::post('/webhooks/yoco/{company}', [PaymentController::class, 'webhook'])->middleware(['public-tenant', 'throttle:120,1']);

Route::post('/c/{company}/drivers/{staff}/invitation', [DriverInvitationController::class, 'store'])->middleware(['auth', 'tenant', 'throttle:10,1']);
Route::prefix('/driver/{company}/invitations/{token}')->where(['token' => '[A-Za-z0-9]{64}'])->middleware(['public-tenant', 'throttle:20,1'])->group(function (): void {
    Route::get('/', [DriverInvitationController::class, 'show']);
    Route::post('/', [DriverInvitationController::class, 'accept']);
});
