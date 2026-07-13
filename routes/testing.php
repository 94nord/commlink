<?php

use App\Models\Corporation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Test-only routes
|--------------------------------------------------------------------------
|
| Loaded exclusively in the testing environment (see routes/web.php). They
| let browser-level E2E tests reset and seed backend state over HTTP, so the
| Node toolchain needs no database driver.
|
*/

Route::post('_test/reset', function () {
    Artisan::call('migrate:fresh', ['--force' => true]);

    return response()->noContent();
});

Route::post('_test/drain-outbox', function () {
    Artisan::call('app:send-outbox');

    return response()->noContent();
});

Route::post('_test/seed', function (Request $request) {
    $validated = $request->validate([
        'corporation' => ['required', 'string', 'max:255'],
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'email', 'max:255'],
        'password' => ['required', 'string'],
    ]);

    $corporation = Corporation::create(['name' => $validated['corporation']]);

    $user = $corporation->users()->forceCreate([
        'name' => $validated['name'],
        'email' => $validated['email'],
        'email_verified_at' => now(),
        'password' => $validated['password'],
    ]);

    // S2: a corporation always has at least one staff member — the founder,
    // who is the admin and can log in (so the E2E user sees admin surfaces).
    $staff = $corporation->staff()->create([
        'user_id' => $user->id,
        'name' => $validated['name'],
        'email' => $validated['email'],
        'is_admin' => true,
    ]);

    return response()->json([
        'corporation_id' => $corporation->id,
        'user_id' => $user->id,
        'staff_id' => $staff->id,
    ]);
});
