<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Stats\Histogram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class HistogramTest extends BaseTestCase
{
    /**
     * The exact value at rank ⌊q·(n−1)⌋, the same definition the histogram uses.
     *
     * @param  array<int,float>  $values
     */
    private static function exact(array $values, float $q): float
    {
        sort($values);

        return $values[(int) floor($q * (count($values) - 1))];
    }

    /** @return array<string,array{0:array<int,float>}> */
    public static function distributions(): array
    {
        mt_srand(20261117);
        $uniform = $lognormal = $bimodal = $exponential = [];

        for ($i = 0; $i < 5000; $i++) {
            $uniform[] = mt_rand(1, 200_000) / 100;
            // Box–Muller: response times are roughly log-normal.
            $u = max(1e-9, mt_rand() / mt_getrandmax());
            $v = mt_rand() / mt_getrandmax();
            $lognormal[] = exp(5 + 0.8 * sqrt(-2 * log($u)) * cos(2 * M_PI * $v));
            // Cached pages fast, the rest slow.
            $bimodal[] = (float) ($i % 4 === 0 ? 900 + mt_rand(0, 400) : 20 + mt_rand(0, 30));
            $exponential[] = -log(max(1e-9, mt_rand() / mt_getrandmax())) * 120;
        }

        return [
            'uniform' => [$uniform],
            'log-normal' => [$lognormal],
            'bimodal' => [$bimodal],
            'exponential' => [$exponential],
            'layout shift scores' => [[0.0, 0.0, 0.01, 0.02, 0.05, 0.08, 0.1, 0.12, 0.3, 0.5, 0.0, 0.004]],
            'single value' => [[42.0]],
        ];
    }

    /**
     * @param  array<int,float>  $values
     */
    #[DataProvider('distributions')]
    public function test_percentiles_are_within_the_bucket_error_of_the_exact_ones(array $values): void
    {
        $histogram = Histogram::of($values);

        foreach ([0.0, 0.25, 0.5, 0.75, 0.9, 0.95, 0.99, 1.0] as $q) {
            $exact = self::exact($values, $q);
            $estimate = $histogram->quantile($q);
            $this->assertNotNull($estimate);

            if ($exact < Histogram::MIN) {
                $this->assertSame(0.0, $estimate, "q=$q of zeros");

                continue;
            }

            $this->assertEqualsWithDelta($exact, $estimate, $exact * 0.025, "q=$q");
        }

        $this->assertSame(count($values), $histogram->count());
        $this->assertEqualsWithDelta(array_sum($values), $histogram->sum(), 1e-6 * max(1, array_sum($values)));
        $this->assertSame(min($values), $histogram->min());
        $this->assertSame(max($values), $histogram->max());
    }

    public function test_merged_days_give_the_percentiles_of_the_whole_week(): void
    {
        mt_srand(7);
        $all = [];
        $week = new Histogram;

        for ($day = 0; $day < 7; $day++) {
            // Each day slower than the one before: merging must not average percentiles.
            $values = array_map(static fn (): float => mt_rand(100, 1000) * (1 + $day / 2), range(1, 300 + $day * 50));
            $all = [...$all, ...$values];
            // Through the database format, as the report reads it back.
            $stored = Histogram::of($values)->toArray();
            $row = (object) ['count' => $stored['n'], 'sum' => $stored['s'], 'min' => $stored['lo'], 'max' => $stored['hi'], 'buckets' => Histogram::of($values)->toJson()];
            $week->merge(Histogram::fromRow($row));
        }

        $direct = Histogram::of($all);

        foreach ([0.5, 0.75, 0.95] as $q) {
            $this->assertSame($direct->quantile($q), $week->quantile($q));
            $this->assertEqualsWithDelta(self::exact($all, $q), $week->quantile($q), self::exact($all, $q) * 0.025);
        }

        $this->assertSame(count($all), $week->count());
    }

    public function test_an_empty_histogram_has_no_percentiles(): void
    {
        $histogram = new Histogram;

        $this->assertNull($histogram->quantile(0.5));
        $this->assertNull($histogram->mean());
        $this->assertNull($histogram->min());
        $this->assertNull($histogram->max());
        $this->assertSame(['n' => 0, 's' => 0.0, 'lo' => 0.0, 'hi' => 0.0, 'b' => []], $histogram->toArray());
        $this->assertSame('{}', $histogram->toJson());

        $histogram->merge(new Histogram);
        $this->assertSame(0, $histogram->count());
    }

    public function test_bad_values_are_ignored_and_negative_ones_count_as_zero(): void
    {
        $histogram = new Histogram;
        $histogram->add(NAN);
        $histogram->add(INF);
        $histogram->add(-INF);
        $histogram->add(5.0, 0);
        $this->assertSame(0, $histogram->count());

        $histogram->add(-3.0);
        $histogram->add(10.0, 3);

        $this->assertSame(4, $histogram->count());
        $this->assertSame(0.0, $histogram->min());
        $this->assertSame(30.0, $histogram->sum());
        $this->assertSame(7.5, $histogram->mean());
        $this->assertSame(0.0, $histogram->quantile(0));
        $this->assertSame(10.0, $histogram->quantile(1));
        // Out-of-range q is clamped, not an error.
        $this->assertSame(10.0, $histogram->quantile(7));
        $this->assertSame(0.0, $histogram->quantile(-1));
    }

    public function test_reading_back_rejects_broken_stored_data(): void
    {
        $this->assertSame(0, Histogram::fromArray(['n' => -5, 'b' => [3 => 5]])->count());
        $this->assertSame(0, Histogram::fromRow((object) ['count' => 0, 'buckets' => 'not json'])->count());

        $broken = Histogram::fromRow((object) ['count' => 2, 'sum' => 3, 'min' => 1, 'max' => 2, 'buckets' => 'not json']);
        $this->assertSame(2, $broken->count());
        // No buckets survive, so the percentile falls back to the maximum.
        $this->assertSame(2.0, $broken->quantile(0.5));

        $kept = Histogram::fromArray(['n' => 3, 's' => 6, 'lo' => 1, 'hi' => 3, 'b' => ['0' => 1, '14' => 2, '20' => 0, '22' => -4]]);
        $this->assertSame([0 => 1, 14 => 2], $kept->toArray()['b']);
    }

    public function test_bucket_values_stay_within_their_bounds(): void
    {
        foreach ([0.001, 0.0042, 1.0, 1.04, 1.05, 199.99, 2500.0, 123456.0] as $value) {
            $read = Histogram::value(Histogram::index($value));
            $this->assertEqualsWithDelta($value, $read, $value * 0.0244 + 1e-12, (string) $value);
        }

        $this->assertSame(0.0, Histogram::value(Histogram::index(0.0)));
        $this->assertSame(0.0, Histogram::value(Histogram::index(0.0009)));
        $this->assertSame(Histogram::index(1.0), Histogram::index(1.0 + 1e-12));
    }

    public function test_a_stored_histogram_is_read_back_whole(): void
    {
        $one = Histogram::fromArray(['n' => 1, 's' => 5, 'lo' => 5, 'hi' => 5, 'b' => [Histogram::index(5.0) => 1]]);
        $this->assertSame(1, $one->count());
        $this->assertSame(5.0, $one->sum());
        $this->assertSame(5.0, $one->min());
        $this->assertSame(5.0, $one->max());

        // Without the numbers, zeros.
        $bare = Histogram::fromArray(['n' => 2]);
        $this->assertSame(2, $bare->count());
        $this->assertSame(0.0, $bare->sum());
        $this->assertSame(0.0, $bare->min());
        $this->assertSame(0.0, $bare->max());
        $this->assertSame([], $bare->toArray()['b']);
    }

    public function test_a_stored_histogram_without_a_count_is_empty_whatever_else_it_holds(): void
    {
        foreach ([[], ['n' => null], ['n' => 0], ['n' => -3], ['n' => 'abc']] as $count) {
            $histogram = Histogram::fromArray($count + ['s' => 5, 'lo' => 1, 'hi' => 4, 'b' => [3 => 2]]);

            $this->assertSame(0, $histogram->count(), var_export($count, true));
            $this->assertSame(0.0, $histogram->sum());
            $this->assertNull($histogram->min());
            $this->assertSame(['n' => 0, 's' => 0.0, 'lo' => 0.0, 'hi' => 0.0, 'b' => []], $histogram->toArray());
        }
    }

    public function test_numbers_stored_as_text_are_read_as_numbers(): void
    {
        $histogram = Histogram::fromArray(['n' => '3', 's' => '6.5', 'lo' => '1.5', 'hi' => '3.5', 'b' => ['3' => '4', '5.5' => 2, '9' => 0.5, '10' => '0', 'abc' => 1]]);

        $this->assertSame(3, $histogram->count());
        $this->assertSame(6.5, $histogram->sum());
        $this->assertSame(1.5, $histogram->min());
        $this->assertSame(3.5, $histogram->max());
        $this->assertSame([0 => 1, 3 => 4, 5 => 2], $histogram->toArray()['b']);
    }

    public function test_a_database_row_is_read_back_whole(): void
    {
        // As a driver hands it over: everything as text.
        $row = Histogram::fromRow((object) ['count' => '3', 'sum' => '6.5', 'min' => '1.5', 'max' => '3.5', 'buckets' => '{"0":1,"14":2}']);

        $this->assertSame(3, $row->count());
        $this->assertSame(6.5, $row->sum());
        $this->assertSame(1.5, $row->min());
        $this->assertSame(3.5, $row->max());
        $this->assertSame([0 => 1, 14 => 2], $row->toArray()['b']);

        $bare = Histogram::fromRow((object) ['count' => 2]);
        $this->assertSame([2, 0.0, 0.0, 0.0, []], [$bare->count(), $bare->sum(), $bare->min(), $bare->max(), $bare->toArray()['b']]);

        foreach ([(object) ['sum' => 5, 'min' => 1, 'max' => 4, 'buckets' => '{"3":2}'], (object) ['count' => null, 'sum' => 5], (object) ['count' => 0, 'sum' => 5]] as $empty) {
            $this->assertSame(0, Histogram::fromRow($empty)->count());
            $this->assertSame(0.0, Histogram::fromRow($empty)->sum());
        }
    }

    public function test_merging_an_empty_histogram_changes_nothing_and_merging_into_one_takes_everything(): void
    {
        $histogram = Histogram::of([5.0, 7.0]);
        $histogram->merge(new Histogram);

        $this->assertSame(2, $histogram->count());
        $this->assertSame(12.0, $histogram->sum());
        $this->assertSame(5.0, $histogram->min());
        $this->assertSame(7.0, $histogram->max());

        $empty = new Histogram;
        $empty->merge($histogram);
        $this->assertSame($histogram->toArray(), $empty->toArray());
    }

    public function test_buckets_come_out_in_order_whatever_order_they_came_in(): void
    {
        $histogram = new Histogram;

        foreach ([1000.0, 10.0, 1.0] as $value) {
            $histogram->add($value);
        }

        $this->assertSame([Histogram::index(1.0), Histogram::index(10.0), Histogram::index(1000.0)], array_keys($histogram->toArray()['b']));
        $this->assertSame('{"0":1,"48":1,"142":1}', $histogram->toJson());
        $this->assertSame(1.0, $histogram->quantile(0.0));
        $this->assertEqualsWithDelta(1000.0, $histogram->quantile(1.0), 25.0);
    }

    public function test_the_sum_is_kept_to_six_decimals(): void
    {
        $histogram = new Histogram;
        $histogram->add(1.23456789);

        $this->assertSame(1.234568, $histogram->toArray()['s']);
        $this->assertSame(1.23456789, $histogram->sum());
    }

    public function test_a_value_on_the_edge_of_a_bucket_belongs_to_the_bucket_below(): void
    {
        $this->assertSame(0, Histogram::index(1.0));
        $this->assertSame(1, Histogram::index(1.05));
        $this->assertSame(10, Histogram::index(1.05 ** 10));
        $this->assertSame(11, Histogram::index(1.05 ** 10 * 1.0001));
    }
}
