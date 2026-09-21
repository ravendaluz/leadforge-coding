<?php

namespace App\Providers;

use App\Services\Validation\FakeValidationProvider;
use App\Services\Validation\Repositories\EloquentLeadValidationResultRepository;
use App\Services\Validation\Repositories\LeadValidationResultRepository;
use App\Services\Validation\ValidationProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ValidationProvider::class,
            FakeValidationProvider::class
        );

        $this->app->bind(
            LeadValidationResultRepository::class,
            EloquentLeadValidationResultRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
