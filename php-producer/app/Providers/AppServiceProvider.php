<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
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
        // В dev ловим опечатки вида «поле не в тот массив» вместо тихой потери данных
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}