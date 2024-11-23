<?php

namespace App\Providers;

use App\Facades\Agent;
use App\Services\RandomAgentService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Agent::class, function ($app) {
            return new RandomAgentService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
