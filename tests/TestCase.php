<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\AtriumServiceProvider;
use Atrium\Admin\Models\AdminUser;
use Atrium\Monitoring\MonitoringServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Tighten\Ziggy\ZiggyServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected string $bufferPath;

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            ZiggyServiceProvider::class,
            AtriumServiceProvider::class,
            MonitoringServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('app.name', 'Demo site');
        $config->set('app.url', 'http://localhost');
        // CI runs the suite against MySQL and PostgreSQL too.
        $target = env('DB_CONNECTION');
        $config->set('database.default', in_array($target, ['mysql', 'pgsql'], true) ? $target : 'testing');
        $config->set('atrium.demo', false);
        $config->set('atrium.auth.two_factor.enabled', false);
        $config->set('atrium.dashboard.timings.enabled', false);
        // Installed, as atrium:module:install leaves it.
        $config->set('atrium.modules', [MonitoringServiceProvider::MODULE]);

        $this->bufferPath = sys_get_temp_dir().'/atrium-monitoring-test-'.getmypid();
        $config->set('atrium-monitoring.buffer.path', $this->bufferPath);
        $config->set('atrium-monitoring.server.sample_rate', 1.0);
        $config->set('atrium-monitoring.rum.sample_rate', 1.0);
        $config->set('atrium-monitoring.telegram.token', null);
        $config->set('atrium-monitoring.telegram.chat_id', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->bufferPath);

        parent::tearDown();
    }

    protected function admin(bool $super = true): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin'.random_int(1, 1_000_000).'@example.com',
            'password' => 'secret-password',
            'is_active' => true,
        ]);

        $admin->forceFill(['is_superadmin' => $super])->save();

        return $admin;
    }
}
