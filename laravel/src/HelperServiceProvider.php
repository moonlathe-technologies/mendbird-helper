<?php

namespace Mendbird\Helper;

use Illuminate\Support\ServiceProvider;

/**
 * Adds one address, /mendbird-helper, and one command, php artisan mendbird:show. The address is added with no
 * middleware, so it sets no cookies and starts no session.
 */
class HelperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mendbird.php', 'mendbird');
    }

    public function boot(): void
    {
        $this->app['router']->get('mendbird-helper', AnswerController::class);
        if ($this->app->runningInConsole()) {
            $this->commands([ShowCommand::class]);
        }
    }
}
