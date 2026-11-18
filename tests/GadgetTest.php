<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Admin\MonitoringGadget;
use Atrium\Monitoring\Buffer\Flusher;
use Atrium\Monitoring\Models\UptimeCheck;
use Atrium\Monitoring\Uptime\UptimeChecker;
use Illuminate\Support\Carbon;

final class GadgetTest extends TestCase
{
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

    /** @param array<string,int|float> $vitals */
    private function visit(string $day, array $vitals): void
    {
        app(Flusher::class)->write([['k' => 'v', 'at' => Carbon::parse($day.' 09:00:00')->getTimestamp(), 'site' => 1, 'loc' => 'en', 'path' => '/', 'dev' => 'mobile', 'con' => '4g'] + $vitals]);
    }

    private function request(string $day, string $route, float $ms): void
    {
        app(Flusher::class)->write([['k' => 'r', 'at' => Carbon::parse($day.' 09:00:00')->getTimestamp(), 'site' => 1, 'loc' => 'en', 'route' => $route, 'path' => '/', 'm' => 'GET', 's' => 200, 'd' => $ms, 'q' => 1, 'qm' => 1.0, 'ch' => 0, 'cm' => 0, 'mem' => 1024]]);
    }

    private function check(string $name, array $attributes = []): UptimeCheck
    {
        $check = new UptimeCheck;
        $check->forceFill($attributes + ['site_id' => 1, 'name' => $name, 'url' => 'https://'.str_replace(' ', '-', strtolower($name)).'.example.test/', 'active' => true])->save();

        return $check;
    }

    private function checkResult(UptimeCheck $check, bool $ok, string $day): void
    {
        app(UptimeChecker::class)->record($check, $ok, $ok ? 200 : 503, 50.0, $ok ? null : 'HTTP 503', Carbon::parse($day.' 08:00:00'));
    }

    public function test_it_is_the_monitoring_tile(): void
    {
        $gadget = new MonitoringGadget;

        $this->assertSame('MonitoringGadget', $gadget->component());
        $this->assertSame('Speed and uptime', $gadget->title());
    }

    public function test_the_title_can_be_set_in_the_options(): void
    {
        $gadget = new MonitoringGadget(['title' => 'Site health']);

        $this->assertSame('Site health', $gadget->title());
    }

    public function test_it_is_for_those_who_may_open_the_page_it_links_to(): void
    {
        $gadget = new MonitoringGadget;

        $this->assertFalse($gadget->isVisible(), 'nobody signed in');

        $this->actingAs($this->admin(false), 'admin');
        $this->assertFalse($gadget->isVisible(), 'signed in without the permission');

        $this->actingAs($this->admin(), 'admin');
        $this->assertTrue($gadget->isVisible());
    }

    public function test_the_visitors_vitals_are_the_75th_percentile_of_the_last_seven_days(): void
    {
        // Seven days end today: the 11th is the first, the 10th is out.
        $this->visit('2026-11-10', ['lcp' => 4000, 'inp' => 900, 'cls' => 0.4, 'ttfb' => 2500, 'fcp' => 4000]);
        $this->visit('2026-11-11', ['lcp' => 1000, 'inp' => 100, 'cls' => 0.05, 'ttfb' => 400, 'fcp' => 1200]);
        $this->visit('2026-11-17', ['lcp' => 1000, 'inp' => 100, 'cls' => 0.05, 'ttfb' => 400, 'fcp' => 1200]);

        $vitals = (new MonitoringGadget)->data()['vitals'];

        $this->assertSame([
            ['key' => 'lcp', 'label' => 'Largest Contentful Paint', 'value' => '1.00 s', 'rating' => 'good'],
            ['key' => 'inp', 'label' => 'Interaction to Next Paint', 'value' => '100 ms', 'rating' => 'good'],
            ['key' => 'cls', 'label' => 'Cumulative Layout Shift', 'value' => '0.050', 'rating' => 'good'],
            ['key' => 'ttfb', 'label' => 'Time to First Byte', 'value' => '400 ms', 'rating' => 'good'],
            ['key' => 'fcp', 'label' => 'First Contentful Paint', 'value' => '1.20 s', 'rating' => 'good'],
        ], $vitals);
    }

    public function test_the_seventh_day_back_is_counted_and_a_day_before_the_window_is_not(): void
    {
        $this->visit('2026-11-11', ['lcp' => 4800]);

        $this->assertSame(['key' => 'lcp', 'label' => 'Largest Contentful Paint', 'value' => '4.80 s', 'rating' => 'poor'], (new MonitoringGadget)->data()['vitals'][0]);

        Carbon::setTestNow('2026-11-18 12:00:00');
        $this->assertSame(['key' => 'lcp', 'label' => 'Largest Contentful Paint', 'value' => '—', 'rating' => null], (new MonitoringGadget)->data()['vitals'][0]);
    }

    public function test_uptime_is_the_share_of_good_checks_over_thirty_days_to_two_decimals(): void
    {
        $shop = $this->check('Shop');
        // Thirty days end today: 19 October is the first, the 18th is out.
        $this->checkResult($shop, false, '2026-10-18');
        $this->checkResult($shop, true, '2026-10-19');
        $this->checkResult($shop, true, '2026-11-01');
        $this->checkResult($shop, false, '2026-11-17');

        $this->assertSame(66.67, (new MonitoringGadget)->data()['uptime']);
    }

    public function test_the_thirtieth_day_back_is_counted_and_the_one_before_is_not(): void
    {
        $shop = $this->check('Shop');
        $this->checkResult($shop, false, '2026-10-18');
        $this->checkResult($shop, true, '2026-10-19');

        $this->assertSame(100.0, (new MonitoringGadget)->data()['uptime']);

        $this->checkResult($shop, true, '2026-10-20');
        $this->assertSame(100.0, (new MonitoringGadget)->data()['uptime']);
    }

    public function test_uptime_has_no_number_before_the_first_check(): void
    {
        $this->assertNull((new MonitoringGadget)->data()['uptime']);
        $this->assertSame([], (new MonitoringGadget)->data()['down']);
    }

    public function test_it_names_the_active_addresses_that_are_down_now(): void
    {
        config(['atrium-monitoring.uptime.failures' => 1]);
        $this->checkResult($this->check('Shop'), false, '2026-11-17');
        $this->checkResult($this->check('Blog'), true, '2026-11-17');
        $this->checkResult($this->check('Old shop', ['active' => false]), false, '2026-11-17');
        $this->checkResult($this->check('Docs', ['site_id' => 2]), false, '2026-11-17');

        $this->assertSame(['Shop', 'Docs'], (new MonitoringGadget)->data()['down']);
    }

    public function test_it_lists_the_five_slowest_routes_by_p75_of_the_last_week(): void
    {
        foreach (['alpha' => 100.0, 'bravo' => 200.0, 'charlie' => 300.0, 'delta' => 400.0, 'echo' => 500.0, 'foxtrot' => 600.0] as $route => $ms) {
            $this->request('2026-11-17', $route, $ms);
        }

        $this->request('2026-11-10', 'golf', 9000.0);

        $slowest = (new MonitoringGadget)->data()['slowest'];

        $this->assertSame(['foxtrot', 'echo', 'delta', 'charlie', 'bravo'], array_column($slowest, 'route'));
        $this->assertSame(['600 ms', '500 ms', '400 ms', '300 ms', '200 ms'], array_column($slowest, 'p75'));
        $this->assertSame(['route', 'p75'], array_keys($slowest[0]));
    }

    public function test_it_links_to_the_page_and_carries_its_wording(): void
    {
        $data = (new MonitoringGadget)->data();

        $this->assertSame(['vitals', 'uptime', 'down', 'slowest', 'url', 'labels', 'ratings'], array_keys($data));
        $this->assertSame(route('admin.monitoring.index'), $data['url']);
        $this->assertSame(trans('atrium-monitoring::monitoring.gadget'), $data['labels']);
        $this->assertSame('Speed and uptime', $data['labels']['title']);
        $this->assertSame(trans('atrium-monitoring::monitoring.ratings'), $data['ratings']);
        $this->assertNotSame([], $data['ratings']);
    }
}
