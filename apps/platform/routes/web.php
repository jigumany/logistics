<?php

use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyProfileController;
use App\Http\Controllers\CoverageController;
use App\Http\Controllers\DepotController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LoadCapacityController;
use App\Http\Controllers\OperationalLookupController;
use App\Http\Controllers\OperationalMediaController;
use App\Http\Controllers\ServiceTypeController;
use App\Http\Controllers\ServiceZoneController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StaffMemberController;
use App\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/companies');
Route::get('/health/ready', HealthController::class)->middleware('throttle:30,1');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login');
});
Route::middleware('auth')->group(function (): void {
    Route::get('/companies', [SessionController::class, 'companies'])->name('companies');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::prefix('/c/{company}')->middleware('tenant')->group(function (): void {

        Route::get('/settings', CompanyProfileController::class)->name('company.settings');
        Route::get('/lookups/{kind}', OperationalLookupController::class)->name('company.lookups');
        Route::get('/coverage', CoverageController::class)->name('company.coverage');
        Route::post('/fleet/{record}/capacity', LoadCapacityController::class)->name('company.fleet.capacity');
        Route::post('/media/{kind}/{record}', [OperationalMediaController::class, 'upload'])->name('company.media.upload');
        Route::get('/media/{kind}/{record}', [OperationalMediaController::class, 'download'])->name('company.media.download');
        Route::get('/staff/{record}/documents', [OperationalMediaController::class, 'index'])->name('company.staff.documents');
        Route::get('/services/create', [ServiceTypeController::class, 'create'])->name('company.services.create');
        Route::post('/services', [ServiceTypeController::class, 'store'])->name('company.services.store');
        Route::get('/services', [ServiceTypeController::class, 'index'])->name('company.services');
        Route::get('/services/{record}/edit', [ServiceTypeController::class, 'edit'])->name('company.services.edit');
        Route::get('/calendar/{kind}', [CalendarController::class, 'index'])->name('company.calendar');
        Route::get('/calendar/{kind}/create', [CalendarController::class, 'create'])->name('company.calendar.create');
        Route::get('/calendar/{kind}/{record}/edit', [CalendarController::class, 'edit'])->name('company.calendar.edit');
        Route::post('/calendar/{kind}', [CalendarController::class, 'store'])->name('company.calendar.store');
        Route::put('/calendar/{kind}/{record}', [CalendarController::class, 'update'])->name('company.calendar.update');
        Route::delete('/calendar/{kind}/{record}', [CalendarController::class, 'destroy'])->name('company.calendar.destroy');
        Route::get('/depots', [DepotController::class, 'index'])->name('company.depots.index');
        Route::get('/depots/create', [DepotController::class, 'create'])->name('company.depots.create');
        Route::get('/depots/{record}/edit', [DepotController::class, 'edit'])->name('company.depots.edit');
        Route::post('/depots', [DepotController::class, 'store'])->name('company.depots.store');
        Route::put('/depots/{record}', [DepotController::class, 'update'])->name('company.depots.update');
        Route::get('/fleet', [VehicleController::class, 'index'])->name('company.fleet.index');
        Route::get('/fleet/create', [VehicleController::class, 'create'])->name('company.fleet.create');
        Route::get('/fleet/{record}/edit', [VehicleController::class, 'edit'])->name('company.fleet.edit');
        Route::post('/fleet', [VehicleController::class, 'store'])->name('company.fleet.store');
        Route::put('/fleet/{record}', [VehicleController::class, 'update'])->name('company.fleet.update');
        Route::delete('/fleet/{record}', [VehicleController::class, 'destroy'])->name('company.fleet.destroy');
        Route::get('/staff', [StaffMemberController::class, 'index'])->name('company.staff.index');
        Route::get('/staff/create', [StaffMemberController::class, 'create'])->name('company.staff.create');
        Route::get('/staff/{record}/edit', [StaffMemberController::class, 'edit'])->name('company.staff.edit');
        Route::post('/staff', [StaffMemberController::class, 'store'])->name('company.staff.store');
        Route::put('/staff/{record}', [StaffMemberController::class, 'update'])->name('company.staff.update');
        Route::delete('/staff/{record}', [StaffMemberController::class, 'destroy'])->name('company.staff.destroy');
        Route::get('/zones', [ServiceZoneController::class, 'index'])->name('company.zones.index');
        Route::get('/zones/create', [ServiceZoneController::class, 'create'])->name('company.zones.create');
        Route::get('/zones/{record}/edit', [ServiceZoneController::class, 'edit'])->name('company.zones.edit');
        Route::post('/zones', [ServiceZoneController::class, 'store'])->name('company.zones.store');
        Route::put('/zones/{record}', [ServiceZoneController::class, 'update'])->name('company.zones.update');
        Route::get('/', [CompanyController::class, 'show'])->name('company.dashboard');
        Route::put('/settings', [CompanyController::class, 'update'])->name('company.settings.update');
        Route::patch('/services/{service}', [ServiceTypeController::class, 'update'])->name('company.services.update');
    });
});

require __DIR__.'/quotes.php';
