<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Models\AdminRole;
use Atrium\Admin\Models\AdminUser;
use Atrium\Monitoring\Help\SiteSpeed;
use Atrium\Monitoring\MonitoringServiceProvider;
use Atrium\Monitoring\Stats\Aggregator;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Carbon;
use Laravel\Ai\Tools\Request;

/**
 * The helper's tool for the monitoring page: the page's numbers for whoever
 * may open the page, over the last days.
 */
final class SiteSpeedToolTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The AI module is there: its config says what its helper may use.
        $app['config']->set('atrium-ai.help', ['enabled' => true, 'tools' => []]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-17 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function hit(string $daysAgo, string $route, float $ms, int $status = 200, array $overrides = []): void
    {
        $at = Carbon::now()->subDays((int) $daysAgo);
        $aggregator = new Aggregator;
        $aggregator->addRequest($overrides + [
            'k' => 'r', 'at' => $at->getTimestamp(), 'site' => 1, 'loc' => 'en', 'route' => $route,
            'path' => '/'.$route, 'm' => 'GET', 's' => $status, 'd' => $ms, 'q' => 4, 'qm' => $ms / 10,
            'ch' => 2, 'cm' => 1, 'mem' => 20480,
        ], $at);
        $aggregator->save();
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function ask(array $arguments = [], ?AdminUser $user = null): array
    {
        $json = (new SiteSpeed($user ?? $this->admin()))->handle(new Request($arguments));

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_tool_is_one_of_the_helpers_when_the_ai_module_is_there(): void
    {
        $this->assertSame([SiteSpeed::class], config('atrium-ai.help.tools'));
    }

    public function test_the_tool_is_one_of_the_helpers_once_after_config_cache(): void
    {
        // A cached config already holds the tool: booting on top of it must not add it again.
        $this->app->getProvider(MonitoringServiceProvider::class)->boot();

        $this->assertSame([SiteSpeed::class], config('atrium-ai.help.tools'));
    }

    public function test_it_describes_itself_for_the_model(): void
    {
        $tool = new SiteSpeed($this->admin());

        $this->assertSame('site_speed', $tool->name());
        $this->assertStringContainsString('how fast the site is', strtolower($tool->description()));
        $this->assertSame(['days'], array_keys($tool->schema(new JsonSchemaTypeFactory)));
    }

    public function test_it_sums_up_the_last_week_of_every_site(): void
    {
        $this->hit('0', 'pages.show', 100.0);
        $this->hit('1', 'pages.show', 100.0, 500);
        $this->hit('2', 'news.show', 300.0, 200, ['site' => 2]);
        $this->hit('3', 'news.show', 300.0);
        $this->hit('7', 'pages.show', 9000.0);

        $summary = $this->ask();

        $this->assertSame('2026-11-11', $summary['from']);
        $this->assertSame('2026-11-17', $summary['to']);
        // The day 7 days ago is the eighth: out of the week.
        $this->assertSame(4, $summary['requests']);
        $this->assertEquals(25, $summary['failed_requests_percent']);
        $this->assertEqualsWithDelta(200.0, $summary['response_ms']['average'], 0.01);
        $this->assertEqualsWithDelta(20.0, $summary['database_ms']['average'], 0.01);
        $this->assertEqualsWithDelta(100.0, $summary['response_ms']['median'], 15.0);
        $this->assertGreaterThan(250.0, $summary['response_ms']['p95']);
        $this->assertLessThan(350.0, $summary['response_ms']['p95']);
        $this->assertSame(['news.show', 'pages.show'], array_column($summary['slowest_pages'], 'route'));
        $this->assertSame([2, 2], array_column($summary['slowest_pages'], 'requests'));
        $this->assertSame(url('/admin/monitoring'), $summary['monitoring_url']);
    }

    public function test_the_numbers_are_rounded_for_reading_not_cut(): void
    {
        $this->hit('0', 'pages.show', 100.0);
        $this->hit('0', 'pages.show', 100.0);
        $this->hit('0', 'pages.show', 101.0, 500);

        $summary = $this->ask();

        // 301 ms over 3 requests, one of them failed.
        $this->assertSame(100.3, $summary['response_ms']['average']);
        $this->assertSame(33.33, $summary['failed_requests_percent']);
    }

    public function test_the_answer_is_plain_json_with_text_and_addresses_as_they_are(): void
    {
        $this->hit('0', 'страница', 100.0);

        $json = (new SiteSpeed($this->admin()))->handle(new Request([]));

        $this->assertStringContainsString('"route":"страница"', $json);
        $this->assertStringContainsString('"monitoring_url":"'.url('/admin/monitoring').'"', $json);
    }

    public function test_the_days_asked_for_set_the_period_within_one_and_ninety(): void
    {
        $this->hit('3', 'pages.show', 100.0);
        $this->hit('40', 'pages.show', 100.0);

        $this->assertSame(1, $this->ask(['days' => 5])['requests']);
        $this->assertSame('2026-11-13', $this->ask(['days' => 5])['from']);
        $this->assertSame(2, $this->ask(['days' => 60])['requests']);
        $this->assertSame('2026-08-20', $this->ask(['days' => 1000])['from']);
        $this->assertSame('2026-11-17', $this->ask(['days' => -4])['from']);
        $this->assertSame('2026-11-11', $this->ask(['days' => 0])['from']);
    }

    public function test_only_the_five_slowest_pages_are_listed_slowest_first(): void
    {
        foreach (['a' => 100.0, 'b' => 200.0, 'c' => 300.0, 'd' => 400.0, 'e' => 500.0, 'f' => 600.0] as $route => $ms) {
            $this->hit('0', $route, $ms);
        }

        $this->assertSame(['f', 'e', 'd', 'c', 'b'], array_column($this->ask()['slowest_pages'], 'route'));
    }

    public function test_without_measurements_there_is_nothing_to_report(): void
    {
        $this->assertSame([
            'from' => '2026-11-11',
            'to' => '2026-11-17',
            'requests' => 0,
            'response_ms' => ['average' => null, 'median' => null, 'p95' => null],
            'database_ms' => ['average' => null, 'median' => null, 'p95' => null],
            'failed_requests_percent' => null,
            'slowest_pages' => [],
            'monitoring_url' => url('/admin/monitoring'),
        ], $this->ask());
    }

    public function test_an_administrator_who_may_not_open_the_page_is_told_so_and_sees_no_number(): void
    {
        $this->hit('0', 'pages.show', 100.0);
        $without = $this->admin(super: false);

        $answer = (new SiteSpeed($without))->handle(new Request([]));

        $this->assertSame('This administrator may not open the monitoring page, so the speed of the site is not shown to them.', $answer);
    }

    public function test_the_permission_of_the_page_is_enough(): void
    {
        $this->hit('0', 'pages.show', 100.0);
        $user = $this->admin(super: false);
        $user->roles()->attach(AdminRole::query()->create(['name' => 'Reader', 'permissions' => ['monitoring.view']])->id);

        $this->assertSame(1, $this->ask([], $user)['requests']);
    }
}
