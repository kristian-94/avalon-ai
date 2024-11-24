<?php

namespace App\Providers;

use App\Facades\Agent;
use App\Services\BasicAgentService;
use App\Services\OpenAIService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Agent::class, function ($app) {
            return env('OPEN_AI_API_KEY') ? new OpenAIService() : new BasicAgentService();
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
