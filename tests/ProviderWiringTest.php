<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Facades\Hook;
use Atrium\Admin\Models\AdminRole;
use Atrium\Admin\Models\AdminUser;
use Atrium\Monitoring\Buffer\Buffer;
use Atrium\Monitoring\Buffer\Flusher;
use Atrium\Monitoring\Collector\Recorder;
use Atrium\Monitoring\MonitoringServiceProvider;
use Atrium\Monitoring\Rum\RumScript;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use ReflectionProperty;

/**
 * What the provider registers, down to the paths and the numbers.
 */
final class ProviderWiringTest extends TestCase
{
    private const GROUP = 'site-health';

    protected function twoMiddleware($app): void
    {
        $app['config']->set('atrium.middleware', ['web', 'throttle:60,1']);
    }

    protected function noMiddlewareSetting($app): void
    {
        // Gone after the admin package merged its own config: the default has to answer.
        $app->booting(function () use ($app): void {
            $atrium = $app['config']->get('atrium');
            unset($atrium['middleware']);
            $app['config']->set('atrium', $atrium);
        });
    }

    protected function otherPrefix($app): void
    {
        $app['config']->set('atrium.prefix', 'panel');
    }

    /** What the buffer was built with, as the container makes it now. */
    private function buffer(?int $maxBytes, bool $remove = false): Buffer
    {
        $settings = config('atrium-monitoring.buffer');

        if ($remove) {
            unset($settings['max_bytes']);
        } else {
            $settings['max_bytes'] = $maxBytes;
        }

        config(['atrium-monitoring.buffer' => $settings]);
        app()->forgetInstance(Buffer::class);

        return app(Buffer::class);
    }

    private function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    /** @return array<int,string> */
    private function middlewareOf(string $route): array
    {
        return Route::getRoutes()->getByName($route)->middleware();
    }

    public function test_the_buffer_lives_where_the_config_says(): void
    {
        $buffer = $this->buffer(5000);

        $this->assertSame($this->bufferPath, $this->property($buffer, 'directory'));
        $this->assertSame(5000, $this->property($buffer, 'maxBytes'));
    }

    public function test_the_buffer_limit_is_never_below_a_kilobyte(): void
    {
        $this->assertSame(1024, $this->property($this->buffer(10), 'maxBytes'));
        $this->assertSame(1024, $this->property($this->buffer(1024), 'maxBytes'));
        $this->assertSame(1025, $this->property($this->buffer(1025), 'maxBytes'));
        $this->assertSame(1024, $this->property($this->buffer(-5), 'maxBytes'));
    }

    public function test_the_buffer_limit_defaults_to_a_quarter_of_a_megabyte(): void
    {
        $this->assertSame(262144, $this->property($this->buffer(null, remove: true), 'maxBytes'));
    }

    public function test_the_buffer_limit_is_read_as_a_number(): void
    {
        config(['atrium-monitoring.buffer.max_bytes' => '4096']);
        app()->forgetInstance(Buffer::class);

        $this->assertSame(4096, $this->property(app(Buffer::class), 'maxBytes'));
    }

    public function test_the_buffer_flusher_and_script_are_one_each(): void
    {
        $this->assertSame(app(Buffer::class), app(Buffer::class));
        $this->assertSame(app(Flusher::class), app(Flusher::class));
        $this->assertSame(app(RumScript::class), app(RumScript::class));
    }

    public function test_the_recorder_is_one_per_request(): void
    {
        $first = app(Recorder::class);

        $this->assertSame($first, app(Recorder::class));

        app()->forgetScopedInstances();

        $this->assertNotSame($first, app(Recorder::class));
    }

    public function test_the_views_come_from_the_package(): void
    {
        $views = realpath(__DIR__.'/../resources/views');
        $hints = array_map('realpath', app('view')->getFinder()->getHints()['atrium-monitoring']);

        $this->assertNotFalse($views);
        $this->assertContains($views, $hints);
    }

    public function test_the_anonymous_components_come_from_the_package(): void
    {
        $components = realpath(__DIR__.'/../resources/views/components');
        $mine = array_values(array_filter(
            Blade::getAnonymousComponentPaths(),
            static fn (array $entry): bool => $entry['prefix'] === 'atrium-monitoring',
        ));

        $this->assertNotFalse($components);
        $this->assertCount(1, $mine);
        $this->assertSame($components, realpath($mine[0]['path']));
    }

    public function test_the_config_can_be_published(): void
    {
        $paths = ServiceProvider::pathsToPublish(MonitoringServiceProvider::class, 'atrium-monitoring-config');

        $this->assertCount(1, $paths);
        $this->assertSame(realpath(__DIR__.'/../config/atrium-monitoring.php'), realpath((string) array_key_first($paths)));
        $this->assertSame(config_path('atrium-monitoring.php'), array_values($paths)[0]);
    }

    public function test_the_admin_pages_wear_the_admin_middleware(): void
    {
        $this->assertSame(['web', 'atrium.inertia', 'admin.auth', 'admin.can'], $this->middlewareOf('admin.monitoring.index'));
        $this->assertSame(['web', 'atrium.inertia', 'admin.auth', 'admin.can'], $this->middlewareOf('admin.monitoring.query'));
    }

    #[DefineEnvironment('twoMiddleware')]
    public function test_the_admin_pages_keep_every_middleware_the_app_chose(): void
    {
        $this->assertSame(['web', 'throttle:60,1', 'atrium.inertia', 'admin.auth', 'admin.can'], $this->middlewareOf('admin.monitoring.index'));
    }

    #[DefineEnvironment('noMiddlewareSetting')]
    public function test_without_a_setting_the_admin_pages_get_the_web_group(): void
    {
        $this->assertNull(config('atrium.middleware'));
        $this->assertSame(['web', 'atrium.inertia', 'admin.auth', 'admin.can'], $this->middlewareOf('admin.monitoring.index'));
    }

    #[DefineEnvironment('otherPrefix')]
    public function test_the_admin_pages_live_under_the_admin_prefix(): void
    {
        $this->assertSame('panel/monitoring', Route::getRoutes()->getByName('admin.monitoring.index')->uri());
    }

    public function test_the_default_prefix_is_admin(): void
    {
        $this->assertSame('admin/monitoring', Route::getRoutes()->getByName('admin.monitoring.index')->uri());
    }

    /** @param array<int,string> $permissions */
    private function reader(array $permissions): AdminUser
    {
        $user = $this->admin(false);
        $user->roles()->attach(AdminRole::query()->create(['name' => 'Role '.random_int(1, 99999), 'permissions' => $permissions])->id);

        return $user->fresh();
    }

    /**
     * @param  array<int,array<string,mixed>>  $menu
     * @return array<int,string>
     */
    private function keysOf(array $menu): array
    {
        return array_values(array_filter(array_map(static fn (array $item): ?string => $item['key'] ?? null, $menu)));
    }

    public function test_the_menu_gets_the_group_when_there_is_no_user_to_ask(): void
    {
        $menu = Hook::apply('atrium.admin.menu', [['label' => 'Home']]);

        $this->assertSame([self::GROUP], $this->keysOf($menu));
        $this->assertSame('Home', $menu[0]['label']);
    }

    public function test_the_menu_gets_the_group_for_a_superadmin(): void
    {
        $menu = Hook::apply('atrium.admin.menu', [['label' => 'Home']], $this->admin());

        $this->assertSame([self::GROUP], $this->keysOf($menu));
    }

    public function test_the_menu_gets_the_group_for_whoever_may_view(): void
    {
        $menu = Hook::apply('atrium.admin.menu', [['label' => 'Home']], $this->reader(['monitoring.view']));

        $this->assertSame([self::GROUP], $this->keysOf($menu));
    }

    public function test_the_menu_stays_as_it_was_for_whoever_may_not_view(): void
    {
        $items = [['label' => 'Home'], ['label' => 'News']];

        $this->assertSame($items, Hook::apply('atrium.admin.menu', $items, $this->admin(false)));
        $this->assertSame($items, Hook::apply('atrium.admin.menu', $items, $this->reader(['errors.view'])));
    }
}
