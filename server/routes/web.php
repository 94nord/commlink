<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\CorporationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectWorkOrderController;
use App\Http\Controllers\RoundController;
use App\Http\Controllers\RoundWorkOrderController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\VisitController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
})->name('home');

// Customer-facing magic-link booking (A1): public, no account or login —
// the unguessable token is the identity.
Route::get('book/{token}', [BookingController::class, 'show'])->name('booking.show');
Route::post('book/{token}', [BookingController::class, 'store'])->name('booking.store');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');

    Route::middleware('admin')->group(function () {
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::patch('staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
        Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');
        Route::post('staff/{staff}/invite', [StaffController::class, 'invite'])->name('staff.invite');

        Route::get('corporation', [CorporationController::class, 'edit'])->name('corporation.edit');
        Route::patch('corporation', [CorporationController::class, 'update'])->name('corporation.update');
        Route::get('corporation/logo', [CorporationController::class, 'logo'])->name('corporation.logo');
        Route::post('corporation/logo', [CorporationController::class, 'uploadLogo'])->name('corporation.logo.store');
        Route::delete('corporation/logo', [CorporationController::class, 'deleteLogo'])->name('corporation.logo.destroy');
    });

    Route::get('cases', [WorkOrderController::class, 'index'])->name('cases.index');
    Route::get('cases/{workOrder}', [WorkOrderController::class, 'show'])->name('cases.show');
    Route::post('work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
    Route::patch('work-orders/{workOrder}', [WorkOrderController::class, 'update'])->name('work-orders.update');
    Route::delete('work-orders/{workOrder}', [WorkOrderController::class, 'destroy'])->name('work-orders.destroy');

    Route::get('visits', [VisitController::class, 'index'])->name('visits.index');
    Route::post('visits', [VisitController::class, 'storeFromIndex'])->name('visits.store');
    Route::get('visits/{visit}', [VisitController::class, 'show'])->name('visits.show');
    Route::patch('visits/{visit}', [VisitController::class, 'update'])->name('visits.update');
    Route::delete('visits/{visit}', [VisitController::class, 'destroy'])->name('visits.destroy');
    Route::post('visits/{visit}/cancel', [VisitController::class, 'cancel'])->name('visits.cancel');
    Route::post('cases/{workOrder}/visits', [VisitController::class, 'store'])->name('cases.visits.store');

    Route::get('rounds', [RoundController::class, 'index'])->name('rounds.index');
    Route::post('rounds', [RoundController::class, 'store'])->name('rounds.store');
    Route::get('rounds/{round}', [RoundController::class, 'show'])->name('rounds.show');
    Route::patch('rounds/{round}', [RoundController::class, 'update'])->name('rounds.update');
    Route::delete('rounds/{round}', [RoundController::class, 'destroy'])->name('rounds.destroy');
    Route::post('rounds/{round}/open', [RoundController::class, 'open'])->name('rounds.open');
    Route::post('rounds/{round}/close', [RoundController::class, 'close'])->name('rounds.close');
    Route::post('rounds/{round}/cases', [RoundWorkOrderController::class, 'store'])->name('rounds.cases.store');
    Route::delete('rounds/{round}/cases/{workOrder}', [RoundWorkOrderController::class, 'destroy'])->name('rounds.cases.destroy');
    Route::post('rounds/{round}/cases/{workOrder}/send-invite', [RoundWorkOrderController::class, 'sendInvite'])->name('rounds.cases.send-invite');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::post('projects/{project}/work-orders', [ProjectWorkOrderController::class, 'store'])
        ->name('projects.work-orders.store');
    Route::delete('projects/{project}/work-orders/{workOrder}', [ProjectWorkOrderController::class, 'destroy'])
        ->name('projects.work-orders.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

if (app()->environment('testing')) {
    require __DIR__.'/testing.php';
}
