<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use App\Services\Payments\TinkoffClient;
use App\Services\Payments\TinkoffGateway;

class AppServiceProvider extends ServiceProvider
{

    public function register(): void
    {
        $this->app->scoped(TinkoffClient::class, fn () => TinkoffClient::fromConfig());
        $this->app->scoped(TinkoffGateway::class);
    }

    public function boot(): void
    {
        // В dev ловим опечатки вида «поле не в тот массив» вместо тихой потери данных
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}