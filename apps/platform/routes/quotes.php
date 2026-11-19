<?php

use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\GuestQuoteController;
use App\Http\Controllers\PriceBookController;
use App\Http\Controllers\QuotationPolicyController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteDocumentController;
use App\Http\Controllers\QuoteMediaController;
use App\Http\Controllers\QuoteSimulationController;
use Illuminate\Support\Facades\Route;

Route::prefix('/q/{company}')->middleware(['public-tenant', 'throttle:quote-public'])->group(function (): void {
    Route::get('/', [GuestQuoteController::class, 'wizard'])->name('quote.wizard');
    Route::get('/catalogue', [GuestQuoteController::class, 'catalogue']);
    Route::get('/catalogue/{item}/image', [QuoteMediaController::class, 'catalogueImage']);
    Route::get('/addresses/search', [GuestQuoteController::class, 'addressSearch'])->middleware('throttle:quote-provider');
    Route::get('/addresses/details', [GuestQuoteController::class, 'addressDetails'])->middleware('throttle:quote-provider');
    Route::post('/preview/{operation}', [GuestQuoteController::class, 'preview'])->whereIn('operation', ['inventory', 'route', 'coverage'])->middleware('throttle:quote-provider');
    Route::post('/drafts', [GuestQuoteController::class, 'create'])->middleware('throttle:quote-create');
    Route::middleware('signed')->group(function (): void {
        Route::get('/requests/{quote}', [GuestQuoteController::class, 'show'])->name('quote.guest.view');
        Route::get('/requests/{quote}/revisions', [GuestQuoteController::class, 'revisions'])->name('quote.guest.revisions');
        Route::get('/requests/{quote}/pdf', [QuoteDocumentController::class, 'guest'])->name('quote.guest.pdf');
        Route::get('/requests/{quote}/attachments/{attachment}', [QuoteMediaController::class, 'guest'])->name('quote.guest.attachment');
        Route::post('/requests/{quote}/photo', [QuoteMediaController::class, 'upload'])->middleware('throttle:quote-media');
        Route::post('/requests/{quote}/calculate', [GuestQuoteController::class, 'operate'])->defaults('operation', 'calculate')->middleware(['throttle:quote-mutation', 'throttle:quote-calculation']);
        Route::post('/requests/{quote}/{operation}', [GuestQuoteController::class, 'operate'])->whereIn('operation', ['decline', 'cancel', 'save', 'calculate', 'submit', 'accept', 'respond', 'photo'])->name('quote.guest.operation')->middleware('throttle:quote-mutation');
    });
});
Route::prefix('/c/{company}')->middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('/quotes', [QuoteController::class, 'index']);
    Route::get('/quotes/{quote}', [QuoteController::class, 'show']);
    Route::post('/quotes/{quote}/review', [QuoteController::class, 'review']);
    Route::post('/quotes/{quote}/inventory-verification', [QuoteController::class, 'verifyInventory'])->middleware('throttle:booking-mutation');
    Route::get('/quotes/{quote}/pdf', [QuoteDocumentController::class, 'internal']);
    Route::get('/inventory', [CatalogueController::class, 'index']);
    Route::get('/inventory/create', [CatalogueController::class, 'edit']);
    Route::get('/inventory/{item}', [CatalogueController::class, 'edit']);
    Route::post('/inventory', [CatalogueController::class, 'store']);
    Route::put('/inventory/{item}', [CatalogueController::class, 'update']);
    Route::post('/inventory/{item}/image', [QuoteMediaController::class, 'catalogueUpload']);
    Route::get('/price-books', [PriceBookController::class, 'index']);
    Route::get('/price-books/create', [PriceBookController::class, 'edit']);
    Route::get('/price-books/{book}/simulate', [QuoteSimulationController::class, 'show'])->middleware('cache.headers:private;no_store');
    Route::post('/price-books/{book}/simulate/drafts', [QuoteSimulationController::class, 'create'])->middleware(['throttle:quote-create', 'cache.headers:private;no_store']);
    Route::get('/price-books/{book}/simulate/{quote}/view', [QuoteSimulationController::class, 'operate'])->defaults('operation', 'view')->middleware('cache.headers:private;no_store');
    Route::post('/price-books/{book}/simulate/{quote}/{operation}', [QuoteSimulationController::class, 'operate'])->whereIn('operation', ['save', 'calculate'])->middleware(['throttle:quote-mutation', 'cache.headers:private;no_store']);
    Route::get('/price-books/{book}', [PriceBookController::class, 'edit']);
    Route::post('/price-books', [PriceBookController::class, 'store']);
    Route::put('/price-books/{book}', [PriceBookController::class, 'update']);
    Route::post('/price-books/{book}/{operation}', [PriceBookController::class, 'transition'])->whereIn('operation', ['publish', 'retire']);
    Route::get('/quotation-policies', [QuotationPolicyController::class, 'index']);
    Route::put('/quotation-policies/{service}', [QuotationPolicyController::class, 'update']);
});
