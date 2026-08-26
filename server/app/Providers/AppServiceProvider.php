<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Optional external reference (X2): host-linked or standalone.
        // Pair with the App\Models\Concerns\HasExternalReference trait.
        Blueprint::macro('externalRef', function () {
            /** @var Blueprint $this */
            return $this->string('external_ref')->nullable()->index();
        });
    }
}
