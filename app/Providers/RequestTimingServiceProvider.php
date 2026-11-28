<?php

namespace App\Providers;

use App\Support\Performance\RequestQueryTimingCollector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

final class RequestTimingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RequestQueryTimingCollector::class);
    }

    public function boot(): void
    {
        // Register once per application. Resolve the current lifecycle scope at
        // event time; the listener must never capture a request or its counters.
        DB::listen(static function (QueryExecuted $query): void {
            app(RequestQueryTimingCollector::class)->record((float) $query->time);
        });
    }
}
