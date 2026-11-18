<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Stats;

/**
 * Web Vitals thresholds, as Google rates a page at the 75th percentile:
 * up to the first number is good, past the second is poor.
 */
final class Vitals
{
    public const GOOD = 'good';

    public const NEEDS_IMPROVEMENT = 'needs_improvement';

    public const POOR = 'poor';

    /** metric => [good up to, poor from, unit] */
    public const THRESHOLDS = [
        'lcp' => [2500, 4000, 'ms'],
        'inp' => [200, 500, 'ms'],
        'cls' => [0.1, 0.25, ''],
        'ttfb' => [800, 1800, 'ms'],
        'fcp' => [1800, 3000, 'ms'],
    ];

    /** The largest value a beacon may carry per metric; anything above is junk. */
    public const LIMITS = [
        'lcp' => 120000,
        'inp' => 60000,
        'cls' => 50,
        'ttfb' => 120000,
        'fcp' => 120000,
    ];

    public static function rating(string $metric, ?float $value): ?string
    {
        if ($value === null || ! isset(self::THRESHOLDS[$metric])) {
            return null;
        }

        [$good, $poor] = self::THRESHOLDS[$metric];

        return match (true) {
            $value <= $good => self::GOOD,
            $value > $poor => self::POOR,
            default => self::NEEDS_IMPROVEMENT,
        };
    }

    public static function format(string $metric, ?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($metric === 'cls') {
            return number_format($value, 3, self::decimal(), '');
        }

        if ($value >= 1000) {
            return __('atrium-monitoring::monitoring.units.s', ['value' => number_format($value / 1000, 2, self::decimal(), '')]);
        }

        // Under 10 ms a tenth tells apart what whole milliseconds would not
        // (a database time of 2.5 against 3, the steps of a small axis).
        return __('atrium-monitoring::monitoring.units.ms', ['value' => number_format($value, $value < 10 ? 1 : 0, self::decimal(), '')]);
    }

    /** The decimal separator of the panel's language: 2,91 s in Russian, 2.91 s in English. */
    private static function decimal(): string
    {
        return __('atrium-monitoring::monitoring.units.decimal');
    }
}
