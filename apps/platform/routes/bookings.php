<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingPolicyController;
use App\Http\Controllers\GuestBookingController;
use Illuminate\Support\Facades\Route;

Route::prefix('/q/{company}/requests/{quote}/booking')->middleware(['public-tenant', 'signed', 'throttle:booking-public'])->group(function (): void {
    Route::get('/', [GuestBookingController::class, 'show'])->name('booking.guest.view');
    Route::post('/{operation}', [GuestBookingController::class, 'operate'])->whereIn('operation', ['availability', 'hold', 'release', 'confirm', 'reschedule', 'cancel'])->name('booking.guest.operation')->middleware('throttle:booking-mutation');
});
Route::prefix('/c/{company}')->middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/planner', [BookingController::class, 'planner']);
    Route::get('/booking-policy', [BookingPolicyController::class, 'show']);
    Route::put('/booking-policy', [BookingPolicyController::class, 'update']);
    Route::get('/bookings/quote/{quote}', [BookingController::class, 'quote']);
    Route::post('/bookings/quote/{quote}/{operation}', [BookingController::class, 'operate'])->whereIn('operation', ['availability', 'hold', 'release', 'confirm', 'reschedule', 'cancel', 'note'])->middleware('throttle:booking-mutation');
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);
});
