<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Admin\Menu;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class MenuTest extends BaseTestCase
{
    private const ENTRY = ['label' => 'atrium-monitoring::monitoring.menu', 'route' => 'admin.monitoring.index', 'icon' => 'chart'];

    private const GROUP = ['key' => 'site-health', 'label' => 'atrium-monitoring::monitoring.health', 'icon' => 'chart', 'children' => [self::ENTRY]];

    public function test_alone_it_creates_the_group_before_modules(): void
    {
        $home = ['label' => 'Home', 'route' => 'admin.dashboard'];
        $news = ['label' => 'News'];
        $modules = ['label' => 'Modules', 'route' => 'admin.modules.index'];

        $this->assertSame([$home, $news, self::GROUP, $modules], Menu::add([$home, $news, $modules]));
    }

    public function test_without_modules_the_group_goes_last(): void
    {
        $home = ['label' => 'Home', 'route' => 'admin.dashboard'];

        $this->assertSame([$home, self::GROUP], Menu::add([$home]));
        $this->assertSame([self::GROUP], Menu::add([]));
    }

    public function test_it_joins_the_group_the_error_log_made(): void
    {
        $home = ['label' => 'Home', 'route' => 'admin.dashboard'];
        $errors = ['label' => 'atrium-errors::errors.title', 'route' => 'admin.errors.index', 'icon' => 'info'];
        $group = ['key' => 'site-health', 'label' => 'atrium-errors::errors.health', 'icon' => 'chart', 'children' => [$errors]];
        $modules = ['label' => 'Modules', 'route' => 'admin.modules.index'];

        $expected = $group;
        $expected['children'] = [$errors, self::ENTRY];

        $this->assertSame([$home, $expected, $modules], Menu::add([$home, $group, $modules]));
    }

    public function test_a_group_with_no_children_yet_gets_the_entry(): void
    {
        $group = ['key' => 'site-health', 'label' => 'Site health'];
        $other = ['key' => 'content', 'label' => 'Content', 'children' => [['label' => 'News']]];

        $this->assertSame([$other, $group + ['children' => [self::ENTRY]]], Menu::add([$other, $group]));
    }

    public function test_what_follows_modules_stays_after_it(): void
    {
        $home = ['label' => 'Home', 'route' => 'admin.dashboard'];
        $modules = ['label' => 'Modules', 'route' => 'admin.modules.index'];
        $after = ['label' => 'After', 'route' => 'admin.after'];

        $this->assertSame([$home, self::GROUP, $modules, $after], Menu::add([$home, $modules, $after]));
    }

    public function test_the_modules_own_lists_move_into_the_group_after_the_report(): void
    {
        $home = ['label' => 'Home', 'route' => 'admin.dashboard'];
        $checks = ['label' => 'Uptime checks', 'route' => 'admin.uptime_checks.index', 'icon' => 'folder'];
        $news = ['label' => 'News', 'route' => 'admin.news.index', 'icon' => 'folder'];
        $incidents = ['label' => 'Incidents', 'route' => 'admin.monitoring_incidents.index', 'icon' => 'folder'];
        $modules = ['label' => 'Modules', 'route' => 'admin.modules.index'];

        $group = self::GROUP;
        $group['children'] = [self::ENTRY, $checks, $incidents];

        $this->assertSame([$home, $news, $group, $modules], Menu::add([$home, $checks, $news, $incidents, $modules]));
    }

    public function test_the_lists_follow_the_report_into_the_error_logs_group(): void
    {
        $errors = ['label' => 'Errors', 'route' => 'admin.errors.index'];
        $group = ['key' => 'site-health', 'label' => 'Site health', 'children' => [$errors]];
        $incidents = ['label' => 'Incidents', 'route' => 'admin.monitoring_incidents.index'];
        $modules = ['label' => 'Modules', 'route' => 'admin.modules.index'];

        $expected = $group;
        $expected['children'] = [$errors, self::ENTRY, $incidents];

        $this->assertSame([$expected, $modules], Menu::add([$incidents, $group, $modules]));
    }

    public function test_a_list_someone_already_put_in_a_group_stays_there(): void
    {
        $checks = ['label' => 'Uptime checks', 'route' => 'admin.uptime_checks.index'];
        $content = ['key' => 'content', 'label' => 'Content', 'children' => [$checks]];

        $this->assertSame([$content, self::GROUP], Menu::add([$content]));
    }
}
