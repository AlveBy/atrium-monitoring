<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Admin;

/**
 * The "Site health" group of the admin sidebar, shared with the error log:
 * whichever module comes first creates the group (marked `key` =>
 * `site-health`), the other adds its entry to it. Each carries the group's
 * label, so either works alone. The module's own lists (uptime checks,
 * incidents) follow the report into the group instead of sitting on top.
 */
final class Menu
{
    public const GROUP = 'site-health';

    public const ROUTE = 'admin.monitoring.index';

    /** The module's resources, which the core menu puts on top by themselves. */
    public const LISTS = ['admin.uptime_checks.index', 'admin.monitoring_incidents.index'];

    /**
     * @param  array<int,array<string,mixed>>  $menu
     * @return array<int,array<string,mixed>>
     */
    public static function add(array $menu): array
    {
        $entries = [['label' => 'atrium-monitoring::monitoring.menu', 'route' => self::ROUTE, 'icon' => 'chart']];

        foreach ($menu as $index => $item) {
            if (in_array($item['route'] ?? null, self::LISTS, true)) {
                $entries[] = $item;
                unset($menu[$index]);
            }
        }

        $menu = array_values($menu);

        foreach ($menu as $index => $item) {
            if (($item['key'] ?? null) === self::GROUP) {
                $menu[$index]['children'] = [...($item['children'] ?? []), ...$entries];

                return $menu;
            }
        }

        $group = ['key' => self::GROUP, 'label' => 'atrium-monitoring::monitoring.health', 'icon' => 'chart', 'children' => $entries];

        // Before "Modules", which stays last.
        $modules = array_search('admin.modules.index', array_map(static fn (array $item): mixed => $item['route'] ?? null, $menu), true);

        if ($modules === false) {
            $menu[] = $group;
        } else {
            array_splice($menu, $modules, 0, [$group]);
        }

        return $menu;
    }
}
