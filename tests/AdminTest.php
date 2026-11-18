<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Models\AdminRole;
use Atrium\Admin\Models\AdminUser;
use Atrium\Monitoring\Admin\MonitoringGadget;
use Atrium\Monitoring\Buffer\Flusher;
use Atrium\Monitoring\Collector\SqlNormalizer;
use Atrium\Monitoring\Models\Incident;
use Atrium\Monitoring\Models\UptimeCheck;
use Atrium\Monitoring\Uptime\UptimeChecker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

final class AdminTest extends TestCase
{
    private string $hash;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-17 12:00:00');
        $sql = SqlNormalizer::normalize('select * from "products" where "price" > 100 order by "name"');
        $this->hash = SqlNormalizer::hash($sql, 'testing');
        $now = Carbon::now()->getTimestamp();
        $records = [];

        foreach ([['catalog.show', '/catalog/blue-mug', 'de', 640.0], ['home', '/', 'en', 90.0], ['news.show', '/news/opening', 'en', 210.0]] as [$route, $path, $locale, $ms]) {
            foreach (range(1, 4) as $n) {
                $records[] = ['k' => 'r', 'at' => $now - $n * 3600, 'site' => 1, 'loc' => $locale, 'route' => $route, 'path' => $path, 'm' => 'GET', 's' => 200, 'd' => $ms + $n, 'q' => 6, 'qm' => 4.0, 'ch' => 3, 'cm' => 1, 'mem' => 18432];
            }
        }

        $records[] = ['k' => 'r', 'at' => $now, 'site' => 1, 'loc' => 'en', 'route' => 'catalog.show', 'path' => '/catalog/broken', 'm' => 'GET', 's' => 500, 'd' => 900.0, 'q' => 1, 'qm' => 1, 'ch' => 0, 'cm' => 0, 'mem' => 18432];
        $records[] = ['k' => 'q', 'at' => $now, 'site' => 1, 'route' => 'catalog.show', 'path' => '/catalog/blue-mug', 'h' => $this->hash, 'sql' => $sql, 'c' => 'testing', 'd' => 340.0];
        $records[] = ['k' => 'q', 'at' => $now, 'site' => 1, 'route' => 'catalog.show', 'path' => '/catalog/red-mug', 'h' => $this->hash, 'sql' => $sql, 'c' => 'testing', 'd' => 260.0];

        foreach ([['/', 'mobile', 1800], ['/catalog/blue-mug', 'mobile', 4600], ['/catalog/blue-mug', 'desktop', 2900]] as [$path, $device, $lcp]) {
            $records[] = ['k' => 'v', 'at' => $now, 'site' => 1, 'loc' => 'en', 'path' => $path, 'dev' => $device, 'con' => '4g', 'lcp' => $lcp, 'inp' => 150, 'cls' => 0.04, 'ttfb' => 500, 'fcp' => 1200];
        }

        app(Flusher::class)->write($records);

        $check = new UptimeCheck;
        $check->forceFill(['site_id' => 1, 'name' => 'Demo shop', 'url' => 'https://shop.example.test/'])->save();
        config(['atrium-monitoring.uptime.failures' => 1]);
        app(UptimeChecker::class)->record($check, false, 503, 40.0, 'HTTP 503', Carbon::now()->subMinutes(30));
        app(UptimeChecker::class)->record($check, true, 200, 120.0, null, Carbon::now()->subMinutes(10));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function reader(array $permissions): AdminUser
    {
        $user = $this->admin(false);
        $user->roles()->attach(AdminRole::query()->create(['name' => 'Role '.random_int(1, 99999), 'permissions' => $permissions])->id);

        return $user->fresh();
    }

    public function test_the_page_shows_server_visitors_and_uptime(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/monitoring')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Monitoring/Index', false)
                ->where('filters.period', 'day')
                ->where('range.to', '2026-11-17')
                ->where('server.summary.requests', 13)
                ->where('server.summary.errors', fn ($value) => abs($value - 7.69) < 0.01)
                ->where('server.summary.cache_ratio', fn ($value) => abs($value - 75.0) < 0.1)
                ->where('server.routes.0.route', 'catalog.show')
                ->where('server.routes.0.sample', '/catalog/blue-mug')
                ->where('server.queries.0.hash', $this->hash)
                ->where('server.queries.0.sql', 'select * from "products" where "price" > ? order by "name"')
                ->has('server.charts', 2)
                ->where('server.charts.0.svg', fn (string $svg) => str_contains($svg, '<svg') && str_contains($svg, 'p95'))
                ->has('server.charts.0.points', 30)
                ->has('vitals.metrics', 5)
                ->where('vitals.metrics.0.key', 'lcp')
                ->where('vitals.metrics.0.rating', 'needs_improvement')
                ->where('vitals.pages.0.page', '/catalog/blue-mug')
                ->where('uptime.checks.0.uptime', fn ($value) => (float) $value === 50.0)
                ->where('uptime.incidents.0.duration', '20 minutes')
                ->where('t.title', 'Monitoring'));
    }

    public function test_filters_narrow_the_report_and_bad_values_are_ignored(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/monitoring?period=month&route=home&site=1')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', 'month')
                ->where('filters.route', 'home')
                ->where('filters.site', 1)
                ->where('server.summary.requests', 4)
                ->has('server.charts.0.points', 13));

        $this->actingAs($admin, 'admin')
            ->get('/admin/monitoring?period=decade&site=999&device=fridge&locale=')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', 'day')
                ->where('filters.site', null)
                ->where('filters.device', '*')
                ->where('filters.locale', '*'));

        $this->actingAs($admin, 'admin')
            ->get('/admin/monitoring?device=mobile')
            ->assertInertia(fn (Assert $page) => $page
                ->where('vitals.metrics.0.raw', fn ($value) => abs($value - 1800) / 1800 < 0.025)
                ->where('vitals.pages', []));

        $this->actingAs($admin, 'admin')
            ->get('/admin/monitoring?page=/&period=week&locale=de')
            ->assertInertia(fn (Assert $page) => $page
                ->where('vitals.metrics.0.count', 1)
                ->where('vitals.metrics.1.p75', '150 ms')
                ->where('vitals.pages', [])
                ->where('server.summary.requests', 4)
                ->has('server.charts.0.points', 26));

        $this->actingAs($admin, 'admin')
            ->get('/admin/monitoring?locale=en')
            ->assertInertia(fn (Assert $page) => $page->where('vitals.metrics.0.count', 3)->where('vitals.pages', []));

        $this->actingAs($admin, 'admin')
            ->get('/admin/monitoring?page=/nothing-here')
            ->assertInertia(fn (Assert $page) => $page
                ->where('vitals.metrics.0.p75', '—')
                ->where('vitals.metrics.0.rating', null));
    }

    public function test_a_query_page_shows_where_it_runs(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/monitoring/queries/'.$this->hash)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Monitoring/Query', false)
                ->where('query.sql', 'select * from "products" where "price" > ? order by "name"')
                ->where('routes.0.route', 'catalog.show')
                ->where('routes.0.times', 2)
                ->where('routes.0.slowest', '340 ms')
                ->where('routes.0.average', '300 ms')
                ->has('points', 30));

        $this->actingAs($this->admin(), 'admin')->get('/admin/monitoring/queries/'.str_repeat('0', 40))->assertNotFound();
        $this->actingAs($this->admin(), 'admin')->get('/admin/monitoring/queries/not-a-hash')->assertNotFound();
    }

    public function test_only_those_with_the_permission_get_in(): void
    {
        $this->get('/admin/monitoring')->assertRedirect();

        $this->actingAs($this->reader(['news.view']), 'admin')->get('/admin/monitoring')->assertForbidden();
        $this->actingAs($this->reader(['news.view']), 'admin')->get('/admin/monitoring/queries/'.$this->hash)->assertForbidden();
        $this->actingAs($this->reader(['monitoring.view']), 'admin')->get('/admin/monitoring')->assertOk();
    }

    private function withErrorLog(): void
    {
        Route::get('admin/errors', static fn () => 'errors')->name('admin.errors.index');
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_pages_link_to_their_errors_when_the_error_log_is_there(): void
    {
        $this->withErrorLog();

        $this->actingAs($this->reader(['monitoring.view', 'errors.view']), 'admin')->get('/admin/monitoring?site=1')
            ->assertInertia(fn (Assert $page) => $page
                ->where('vitals.pages.0.page', '/catalog/blue-mug')
                ->where('vitals.pages.0.errors_url', 'http://localhost/admin/errors?status=all&path=%2Fcatalog%2Fblue-mug&site=1'));

        $this->actingAs($this->admin(), 'admin')->get('/admin/monitoring')
            ->assertInertia(fn (Assert $page) => $page->where('vitals.pages.0.errors_url', 'http://localhost/admin/errors?status=all&path=%2Fcatalog%2Fblue-mug'));

        $this->actingAs($this->admin(), 'admin')->get('/admin/monitoring/queries/'.$this->hash)
            ->assertInertia(fn (Assert $page) => $page
                ->where('routes.0.path', fn ($path) => in_array($path, ['/catalog/blue-mug', '/catalog/red-mug'], true))
                ->where('routes.0.errors_url', fn ($url) => str_starts_with((string) $url, 'http://localhost/admin/errors?status=all&path=%2Fcatalog%2F')));
    }

    public function test_no_errors_link_without_the_error_log_or_its_permission(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin/monitoring')
            ->assertInertia(fn (Assert $page) => $page->where('vitals.pages.0.errors_url', null));
        $this->actingAs($this->admin(), 'admin')->get('/admin/monitoring/queries/'.$this->hash)
            ->assertInertia(fn (Assert $page) => $page->where('routes.0.errors_url', null));

        $this->withErrorLog();

        $this->actingAs($this->reader(['monitoring.view']), 'admin')->get('/admin/monitoring')
            ->assertInertia(fn (Assert $page) => $page->where('vitals.pages.0.errors_url', null));
        $this->actingAs($this->reader(['monitoring.view']), 'admin')->get('/admin/monitoring/queries/'.$this->hash)
            ->assertInertia(fn (Assert $page) => $page->where('routes.0.errors_url', null));
    }

    public function test_the_menu_entry_follows_the_permission(): void
    {
        $this->actingAs($this->reader(['monitoring.view']), 'admin')->get('/admin/monitoring')
            ->assertInertia(fn (Assert $page) => $page->where('menu', fn ($menu) => str_contains(json_encode($menu), 'admin.monitoring.index')));
        $this->actingAs($this->reader(['uptime_checks.view']), 'admin')->get('/admin/uptime_checks')
            ->assertInertia(fn (Assert $page) => $page->where('menu', fn ($menu) => ! str_contains(json_encode($menu), 'admin.monitoring.index')));
    }

    public function test_the_gadget_shows_vitals_uptime_and_slow_routes(): void
    {
        $gadget = new MonitoringGadget;
        $this->assertFalse($gadget->isVisible(), 'nobody signed in');

        $this->actingAs($this->reader(['news.view']), 'admin');
        $this->assertFalse($gadget->isVisible());

        $this->actingAs($this->admin(), 'admin');
        $this->assertTrue($gadget->isVisible());
        $this->assertSame('Speed and uptime', $gadget->title());
        $data = $gadget->data();
        $this->assertSame('lcp', $data['vitals'][0]['key']);
        $this->assertSame('needs_improvement', $data['vitals'][0]['rating']);
        $this->assertSame(50.0, $data['uptime']);
        $this->assertSame([], $data['down']);
        $this->assertSame('catalog.show', $data['slowest'][0]['route']);
        $this->assertStringEndsWith('/admin/monitoring', $data['url']);

        $this->actingAs($this->admin(), 'admin')->get('/admin')
            ->assertInertia(fn (Assert $page) => $page->where('gadgets', fn ($gadgets) => collect($gadgets)->contains('component', 'MonitoringGadget')));
    }

    public function test_incidents_are_listed_read_only_and_checks_are_managed_in_the_panel(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/admin/monitoring_incidents')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/monitoring_incidents/create')->assertForbidden();
        $this->actingAs($admin, 'admin')->get('/admin/uptime_checks')->assertOk();

        $this->actingAs($admin, 'admin')->post('/admin/uptime_checks', ['name' => 'Price list', 'url' => 'ftp://files.example.test/', 'active' => true])
            ->assertSessionHasErrors('url');
        $this->actingAs($admin, 'admin')->post('/admin/uptime_checks', ['name' => 'Price list', 'url' => 'https://shop.example.test/prices', 'active' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame(UptimeCheck::UNKNOWN, UptimeCheck::query()->withoutGlobalScopes()->where('name', 'Price list')->value('status'));

        $this->assertSame([], Incident::getAdministrationFields(), 'nothing to edit');
        $this->assertFalse(Incident::isEditableInAdmin());
        $incident = Incident::query()->firstOrFail();
        $this->assertSame('Demo shop', $incident->check_name);
        $this->assertSame(20, $incident->minutes());
    }
}
