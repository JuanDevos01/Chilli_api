<?php

namespace App\Providers;

use App\Domain\Matches\Services\CompatibilityJudge;
use App\Domain\Matches\Services\MinimaxCompatibilityJudge;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CompatibilityJudge::class, function ($app) {
            $config = $app['config']->get('services.minimax');

            return new MinimaxCompatibilityJudge(
                apiKey: (string) ($config['key'] ?? ''),
                baseUrl: (string) ($config['base_url'] ?? 'https://api.minimax.io/v1/'),
                model: (string) ($config['model'] ?? 'MiniMax-M3'),
                timeoutSeconds: (int) ($config['timeout_seconds'] ?? 20),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
