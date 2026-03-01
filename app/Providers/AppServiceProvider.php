<?php

namespace App\Providers;

use App\Facades\Agent;
use App\Services\GroqService;
use App\Services\OpenAIService;
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
            return match (env('AI_PROVIDER', 'openai')) {
                'groq'   => new GroqService,
                'random' => new RandomAgentService,
                default  => new OpenAIService,
            };
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
