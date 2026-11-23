<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring;

use Atrium\Admin\Facades\Hook;
use Atrium\Admin\Models\AdminUser;
use Atrium\Admin\Modules\Modules;
use Atrium\Admin\Support\ConfigList;
use Atrium\Monitoring\Admin\Menu;
use Atrium\Monitoring\Admin\MonitoringController;
use Atrium\Monitoring\Admin\MonitoringGadget;
use Atrium\Monitoring\Buffer\Buffer;
use Atrium\Monitoring\Buffer\Flusher;
use Atrium\Monitoring\Collector\CollectMetrics;
use Atrium\Monitoring\Collector\Recorder;
use Atrium\Monitoring\Help\SiteSpeed;
use Atrium\Monitoring\Rum\RumScript;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

/**
 * The models, the migrations and the settings come from the manifest in
 * composer.json once `atrium:module:install alveby/atrium-monitoring` has
 * run; this wires the rest: the middleware, the beacon, the admin page and
 * gadget, the scheduled tasks.
 */
final class MonitoringServiceProvider extends ServiceProvider
{
    public const MODULE = 'alveby/atrium-monitoring';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atrium-monitoring.php', 'atrium-monitoring');

        $this->app->singleton(Buffer::class, fn (): Buffer => new Buffer(
            (string) config('atrium-monitoring.buffer.path'),
            max(1024, (int) config('atrium-monitoring.buffer.max_bytes', 262144)),
        ));
        // Per request, so a long-running worker starts every request clean.
        $this->app->scoped(Recorder::class);
        $this->app->singleton(Flusher::class);
        $this->app->singleton(RumScript::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'atrium-monitoring');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'atrium-monitoring');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'atrium-monitoring');

        $this->publishes([
            __DIR__.'/../config/atrium-monitoring.php' => config_path('atrium-monitoring.php'),
        ], 'atrium-monitoring-config');

        // Required but not installed: no tables, so nothing to measure into.
        if (! $this->app->make(Modules::class)->isInstalled(self::MODULE)) {
            return;
        }

        $this->registerCollector();
        $this->registerRoutes();
        $this->registerAdmin();
        $this->registerHelper();
        $this->registerTheme();
        $this->registerConsole();
    }

    private function registerCollector(): void
    {
        if (! config('atrium-monitoring.server.enabled', true)) {
            return;
        }

        $this->app->make(HttpKernel::class)->appendMiddlewareToGroup('web', CollectMetrics::class);

        Event::listen(QueryExecuted::class, fn (QueryExecuted $event) => $this->app->make(Recorder::class)->query($event));
        Event::listen(CacheHit::class, fn () => $this->app->make(Recorder::class)->hit());
        Event::listen(CacheMissed::class, fn () => $this->app->make(Recorder::class)->miss());
    }

    private function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        Route::prefix((string) config('atrium.prefix', 'admin'))
            ->middleware([...config('atrium.middleware', ['web']), 'atrium.inertia', 'admin.auth', 'admin.can'])
            ->group(__DIR__.'/../routes/admin.php');
    }

    private function registerAdmin(): void
    {
        ConfigList::add('atrium.dashboard.gadgets', MonitoringGadget::class);

        Hook::filter('atrium.admin.menu', function (array $items, ?AdminUser $user = null): array {
            if ($user !== null && ! $user->hasAdminPermission(MonitoringController::PERMISSION)) {
                return $items;
            }

            return Menu::add($items);
        });
    }

    /**
     * The AI module's helper answers questions about the speed of the site
     * with it, when that module is there (it has its config) and the admin
     * may open the page.
     */
    private function registerHelper(): void
    {
        if (config()->has('atrium-ai.help')) {
            ConfigList::add('atrium-ai.help.tools', SiteSpeed::class);
        }
    }

    private function registerTheme(): void
    {
        if (! config('atrium-monitoring.rum.enabled', true) || ! config('atrium-monitoring.rum.theme', true)) {
            return;
        }

        View::composer('atrium-theme::layout', function (ViewInstance $view): void {
            $tag = $this->app->make(RumScript::class)->tag(request());

            if ($tag !== '') {
                $view->getFactory()->startPush('head', $tag);
            }
        });
    }

    private function registerConsole(): void
    {
        $this->commands([
            Console\FlushCommand::class,
            Console\UptimeCommand::class,
            Console\PruneCommand::class,
            Console\BenchmarkCommand::class,
        ]);

        if (! config('atrium-monitoring.schedule', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('atrium:monitoring:flush')->everyMinute()->withoutOverlapping(10);
            $schedule->command('atrium:monitoring:uptime')->everyMinute()->withoutOverlapping(5);
            $schedule->command('atrium:monitoring:prune')->dailyAt('03:17')->withoutOverlapping(60);
        });
    }
}
