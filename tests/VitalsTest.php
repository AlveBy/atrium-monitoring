<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Stats\Vitals;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The thresholds are Google's: up to the first number is good, past the
 * second is poor, and exactly on a number is the kinder rating.
 */
final class VitalsTest extends TestCase
{
    /** @return iterable<string,array{string,float,string}> */
    public static function ratings(): iterable
    {
        foreach ([
            'lcp' => [2500.0, 4000.0], 'inp' => [200.0, 500.0], 'cls' => [0.1, 0.25], 'ttfb' => [800.0, 1800.0], 'fcp' => [1800.0, 3000.0],
        ] as $metric => [$good, $poor]) {
            yield $metric.' zero' => [$metric, 0.0, 'good'];
            yield $metric.' on the good limit' => [$metric, $good, 'good'];
            yield $metric.' past the good limit' => [$metric, $good * 1.001, 'needs_improvement'];
            yield $metric.' on the poor limit' => [$metric, $poor, 'needs_improvement'];
            yield $metric.' past the poor limit' => [$metric, $poor * 1.001, 'poor'];
        }
    }

    #[DataProvider('ratings')]
    public function test_a_value_is_rated_against_its_metric(string $metric, float $value, string $rating): void
    {
        $this->assertSame($rating, Vitals::rating($metric, $value));
    }

    public function test_nothing_measured_or_an_unknown_metric_has_no_rating(): void
    {
        $this->assertNull(Vitals::rating('lcp', null));
        $this->assertNull(Vitals::rating('tbt', 100.0));
        $this->assertNull(Vitals::rating('tbt', null));
    }

    public function test_the_constants_name_every_rating_and_limit(): void
    {
        $this->assertSame(['good', 'needs_improvement', 'poor'], [Vitals::GOOD, Vitals::NEEDS_IMPROVEMENT, Vitals::POOR]);
        $this->assertSame(['lcp', 'inp', 'cls', 'ttfb', 'fcp'], array_keys(Vitals::THRESHOLDS));
        $this->assertSame(['lcp', 'inp', 'cls', 'ttfb', 'fcp'], array_keys(Vitals::LIMITS));
        $this->assertSame(['lcp' => [2500, 4000, 'ms'], 'inp' => [200, 500, 'ms'], 'cls' => [0.1, 0.25, ''], 'ttfb' => [800, 1800, 'ms'], 'fcp' => [1800, 3000, 'ms']], Vitals::THRESHOLDS);
        $this->assertSame(['lcp' => 120000, 'inp' => 60000, 'cls' => 50, 'ttfb' => 120000, 'fcp' => 120000], Vitals::LIMITS);
    }

    /** @return iterable<string,array{string,float|null,string}> */
    public static function formats(): iterable
    {
        yield 'nothing' => ['lcp', null, '—'];
        yield 'nothing, shift' => ['cls', null, '—'];
        yield 'zero' => ['ttfb', 0.0, '0.0 ms'];
        yield 'a tenth under ten' => ['ttfb', 7.5, '7.5 ms'];
        yield 'a whole number under ten keeps its tenth' => ['ttfb', 5.0, '5.0 ms'];
        yield 'tenths rounded' => ['ttfb', 2.46, '2.5 ms'];
        yield 'just under ten' => ['ttfb', 9.94, '9.9 ms'];
        yield 'ten is whole' => ['ttfb', 10.0, '10 ms'];
        yield 'over ten is whole' => ['ttfb', 10.4, '10 ms'];
        yield 'rounded down' => ['lcp', 999.4, '999 ms'];
        yield 'rounded up' => ['lcp', 120.6, '121 ms'];
        yield 'rounded up to a thousand' => ['lcp', 999.6, '1000 ms'];
        yield 'a second' => ['lcp', 1000.0, '1.00 s'];
        yield 'seconds to two places' => ['inp', 1234.567, '1.23 s'];
        yield 'seconds rounded up' => ['fcp', 1239.0, '1.24 s'];
        yield 'many seconds' => ['ttfb', 1234567.0, '1234.57 s'];
        yield 'shift' => ['cls', 0.12, '0.120'];
        yield 'shift to three places' => ['cls', 0.12349, '0.123'];
        yield 'shift rounded up' => ['cls', 0.1235, '0.124'];
        yield 'large shift' => ['cls', 1234.5, '1234.500'];
        yield 'shift is never seconds' => ['cls', 2000.0, '2000.000'];
    }

    #[DataProvider('formats')]
    public function test_a_value_is_written_for_its_metric(string $metric, ?float $value, string $text): void
    {
        $this->assertSame($text, Vitals::format($metric, $value));
    }

    public function test_numbers_use_the_decimal_separator_of_the_panel_language(): void
    {
        app()->setLocale('ru');
        $this->assertSame('2,91 с', Vitals::format('lcp', 2905.0));
        $this->assertSame('0,038', Vitals::format('cls', 0.0375));
        $this->assertSame('150 мс', Vitals::format('inp', 150.0));
        $this->assertSame('7,5 мс', Vitals::format('ttfb', 7.5));

        app()->setLocale('en');
        $this->assertSame('2.91 s', Vitals::format('lcp', 2905.0));
        $this->assertSame('0.038', Vitals::format('cls', 0.0375));
    }
}
