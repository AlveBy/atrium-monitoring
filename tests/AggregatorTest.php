<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Stats\Aggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which totals each measurement goes to, and how a batch is merged into the
 * daily rows.
 */
final class AggregatorTest extends TestCase
{
    private function at(string $day = '2026-11-16 10:00:00'): Carbon
    {
        return Carbon::parse($day);
    }

    /**
     * What the batch holds: "source|metric|site|locale|subject|device" => [count, sum].
     *
     * @return array<string,array{0:int,1:float}>
     */
    private function held(Aggregator $aggregator): array
    {
        $held = [];

        foreach ($aggregator->pending() as $entry) {
            $key = $entry['key'];
            $held[implode('|', [$key['source'], $key['metric'], $key['site_id'], $key['locale'], $key['subject'], $key['device']])] = [$entry['histogram']->count(), $entry['histogram']->sum()];
        }

        ksort($held);

        return $held;
    }

    /** @return array<int,array{0:string,1:float}> */
    private function sums(Aggregator $aggregator, string $label): array
    {
        return array_filter($this->held($aggregator), fn (string $key): bool => $key === $label, ARRAY_FILTER_USE_KEY);
    }

    public function test_a_request_goes_to_the_site_the_language_and_the_route(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addRequest(['site' => 7, 'loc' => 'en', 'route' => 'blog.show', 'd' => 12.5, 'q' => 3, 'qm' => 4.5, 'ch' => 2, 'cm' => 1, 'mem' => 2048, 's' => 200], $this->at());

        $expected = [];
        foreach (['ttfb' => 12.5, 'db_count' => 3.0, 'db_ms' => 4.5, 'cache_hit' => 2.0, 'cache_miss' => 1.0, 'memory' => 2.0, 'error' => 0.0] as $metric => $value) {
            $expected["request|$metric|7|*|*|*"] = [1, $value];
            $expected["request|$metric|7|en|*|*"] = [1, $value];
            $expected["request|$metric|7|*|blog.show|*"] = [1, $value];
        }
        ksort($expected);

        $this->assertSame($expected, $this->held($aggregator));
        foreach ($aggregator->pending() as $entry) {
            $this->assertSame('2026-11-16', $entry['key']['day']);
        }
    }

    public function test_a_record_without_figures_counts_as_zeros_for_the_first_site(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addRequest([], $this->at());

        $held = $this->held($aggregator);
        $this->assertCount(7, $held, 'seven measures, all of them for the whole site');
        foreach ($held as $label => [$count, $sum]) {
            $this->assertStringStartsWith('request|', $label);
            $this->assertStringEndsWith('|0|*|*|*', substr($label, strpos($label, '|', 8) ?: 0));
            $this->assertSame(0.0, $sum, $label);
        }
    }

    public function test_numbers_written_as_text_are_numbers(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addRequest(['site' => '7', 'loc' => 'en', 'route' => 'r', 'd' => '12.5', 'q' => '3', 'qm' => '4.5', 'ch' => '2', 'cm' => '1', 'mem' => '1024', 's' => '503'], $this->at());

        $this->assertSame([1, 12.5], $this->held($aggregator)['request|ttfb|7|*|*|*']);
        $this->assertSame([1, 3.0], $this->held($aggregator)['request|db_count|7|*|*|*']);
        $this->assertSame([1, 4.5], $this->held($aggregator)['request|db_ms|7|*|*|*']);
        $this->assertSame([1, 2.0], $this->held($aggregator)['request|cache_hit|7|*|*|*']);
        $this->assertSame([1, 1.0], $this->held($aggregator)['request|cache_miss|7|*|*|*']);
        $this->assertSame([1, 1.0], $this->held($aggregator)['request|memory|7|*|*|*']);
        $this->assertSame([1, 1.0], $this->held($aggregator)['request|error|7|*|*|*']);
    }

    /** @return array<string,array{0:mixed,1:float}> */
    public static function statuses(): array
    {
        return [
            '200' => [200, 0.0],
            '404' => [404, 0.0],
            '499' => [499, 0.0],
            '500' => [500, 1.0],
            '503' => [503, 1.0],
            'text' => ['502', 1.0],
            'text below' => ['302', 0.0],
            'nothing' => [null, 0.0],
            'garbage' => ['abc', 0.0],
        ];
    }

    #[DataProvider('statuses')]
    public function test_server_errors_are_counted_from_500(mixed $status, float $error): void
    {
        $aggregator = new Aggregator;
        $aggregator->addRequest(['site' => 1, 'loc' => 'en', 'route' => 'r', 's' => $status], $this->at());

        $this->assertSame([[1, $error]], array_values($this->sums($aggregator, 'request|error|1|*|*|*')));
    }

    public function test_a_record_with_no_status_is_no_error(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addRequest(['site' => 1, 'loc' => 'en', 'route' => 'r'], $this->at());

        $this->assertSame([[1, 0.0]], array_values($this->sums($aggregator, 'request|error|1|*|*|*')));
    }

    /** @return array<string,array{0:int,1:float}> */
    public static function memories(): array
    {
        return ['two megabytes' => [2048, 2.0], 'one' => [1024, 1.0], 'half' => [512, 0.5], 'none' => [0, 0.0]];
    }

    #[DataProvider('memories')]
    public function test_memory_is_in_megabytes(int $kilobytes, float $megabytes): void
    {
        $aggregator = new Aggregator;
        $aggregator->addRequest(['site' => 1, 'loc' => 'en', 'route' => 'r', 'mem' => $kilobytes], $this->at());

        $this->assertSame([[1, $megabytes]], array_values($this->sums($aggregator, 'request|memory|1|*|*|*')));
    }

    public function test_a_missing_memory_is_zero(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addRequest(['site' => 1, 'loc' => 'en', 'route' => 'r'], $this->at());

        $this->assertSame([[1, 0.0]], array_values($this->sums($aggregator, 'request|memory|1|*|*|*')));
    }

    public function test_language_and_route_are_cut_to_the_width_of_their_columns(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addRequest(['site' => 1, 'loc' => 'abcdefghijklm', 'route' => str_repeat('é', 200), 'd' => 1], $this->at());

        $held = $this->held($aggregator);
        $this->assertArrayHasKey('request|ttfb|1|abcdefghijkl|*|*', $held);
        $this->assertArrayHasKey('request|ttfb|1|*|'.str_repeat('é', 191).'|*', $held);

        $exact = new Aggregator;
        $exact->addRequest(['site' => 1, 'loc' => 'abcdefghijkl', 'route' => str_repeat('r', 191), 'd' => 1], $this->at());
        $this->assertArrayHasKey('request|ttfb|1|abcdefghijkl|*|*', $this->held($exact));
        $this->assertArrayHasKey('request|ttfb|1|*|'.str_repeat('r', 191).'|*', $this->held($exact));
    }

    public function test_the_same_measure_of_the_same_day_adds_up_and_another_day_is_another_row(): void
    {
        $aggregator = new Aggregator;
        $record = ['site' => 1, 'loc' => 'en', 'route' => 'r', 'd' => 10];
        $aggregator->addRequest($record, $this->at('2026-11-16 08:00:00'));
        $aggregator->addRequest(['d' => 30] + $record, $this->at('2026-11-16 23:59:59'));
        $aggregator->addRequest(['d' => 5] + $record, $this->at('2026-11-17 00:00:01'));

        $days = [];
        foreach ($aggregator->pending() as $entry) {
            if ($entry['key']['metric'] === 'ttfb' && $entry['key']['locale'] === '*' && $entry['key']['subject'] === '*') {
                $days[$entry['key']['day']] = [$entry['histogram']->count(), $entry['histogram']->sum()];
            }
        }

        $this->assertSame(['2026-11-16' => [2, 40.0], '2026-11-17' => [1, 5.0]], $days);
        $this->assertCount(2 * 21, $aggregator->pending());
    }

    public function test_vitals_go_to_the_site_the_language_the_page_and_the_device(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addVitals(['site' => 3, 'loc' => 'de', 'path' => '/shop', 'dev' => 'mobile', 'lcp' => 2500.5, 'cls' => 0.25, 'inp' => '200', 'fcp' => null, 'ttfb' => 'n/a'], $this->at());

        $expected = [];
        foreach (['lcp' => 2500.5, 'cls' => 0.25, 'inp' => 200.0] as $metric => $value) {
            $expected["vital|$metric|3|*|*|*"] = [1, $value];
            $expected["vital|$metric|3|de|*|*"] = [1, $value];
            $expected["vital|$metric|3|*|/shop|*"] = [1, $value];
            $expected["vital|$metric|3|*|*|mobile"] = [1, $value];
        }
        ksort($expected);

        $this->assertSame($expected, $this->held($aggregator));
    }

    public function test_vitals_without_a_site_belong_to_the_first_one_and_the_text_of_a_site_is_a_number(): void
    {
        $bare = new Aggregator;
        $bare->addVitals(['lcp' => 1.5], $this->at());

        // No language and no device is the whole site, so those lines add up with the total.
        $this->assertSame(['vital|lcp|0|*|*|*' => [3, 4.5], 'vital|lcp|0|*|/|*' => [1, 1.5]], $this->held($bare));

        $text = new Aggregator;
        $text->addVitals(['site' => '3', 'lcp' => 1.5], $this->at());

        $this->assertSame(['vital|lcp|3|*|*|*', 'vital|lcp|3|*|/|*'], array_keys($this->held($text)));
    }

    public function test_vitals_are_cut_to_the_width_of_their_columns_and_not_before(): void
    {
        $cut = new Aggregator;
        $cut->addVitals(['site' => 1, 'loc' => 'abcdefghijklm', 'path' => '/'.str_repeat('é', 191), 'dev' => 'abcdefghi', 'lcp' => 1], $this->at());

        $this->assertSame([
            'vital|lcp|1|*|*|*',
            'vital|lcp|1|*|*|abcdefgh',
            'vital|lcp|1|*|/'.str_repeat('é', 190).'|*',
            'vital|lcp|1|abcdefghijkl|*|*',
        ], array_keys($this->held($cut)));

        $exact = new Aggregator;
        $exact->addVitals(['site' => 1, 'loc' => 'abcdefghijkl', 'path' => '/'.str_repeat('p', 190), 'dev' => 'abcdefgh', 'lcp' => 1], $this->at());

        $this->assertSame([
            'vital|lcp|1|*|*|*',
            'vital|lcp|1|*|*|abcdefgh',
            'vital|lcp|1|*|/'.str_repeat('p', 190).'|*',
            'vital|lcp|1|abcdefghijkl|*|*',
        ], array_keys($this->held($exact)));
    }

    public function test_a_vital_missing_in_front_does_not_stop_the_ones_behind(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addVitals(['site' => 1, 'lcp' => 'n/a', 'inp' => null, 'fcp' => 0.9], $this->at());

        $metrics = array_unique(array_map(static fn (array $entry): string => $entry['key']['metric'], $aggregator->pending()));

        $this->assertSame(['fcp'], array_values($metrics));
    }

    public function test_a_slow_query_goes_to_the_site_and_to_its_own_line(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addQuery(['site' => 2, 'd' => 150.0, 'h' => 'abc123'], $this->at());

        $this->assertSame(['query|time|2|*|*|*' => [1, 150.0], 'query|time|2|*|abc123|*' => [1, 150.0]], $this->held($aggregator));
    }

    public function test_a_query_without_a_site_or_a_time_is_zero_and_text_is_a_number(): void
    {
        $bare = new Aggregator;
        $bare->addQuery([], $this->at());

        $this->assertSame(['query|time|0|*|*|*' => [2, 0.0]], $this->held($bare), 'no query hash is the whole site');

        $text = new Aggregator;
        $text->addQuery(['site' => '2', 'd' => '12.5', 'h' => 'abc'], $this->at());

        $this->assertSame(['query|time|2|*|*|*' => [1, 12.5], 'query|time|2|*|abc|*' => [1, 12.5]], $this->held($text));
    }

    public function test_uptime_counts_up_and_the_response_time_when_there_is_one(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addUptime(4, 9, true, 120.5, $this->at());
        $aggregator->addUptime(4, 9, false, null, $this->at());

        $this->assertSame(['uptime|response|4|*|9|*' => [1, 120.5], 'uptime|up|4|*|9|*' => [2, 1.0]], $this->held($aggregator));
    }

    public function test_the_batch_is_merged_into_the_daily_rows_and_emptied(): void
    {
        $first = new Aggregator;
        $first->addRequest(['site' => 1, 'loc' => 'en', 'route' => 'r', 'd' => 10, 's' => 200], $this->at());
        $first->save();
        $this->assertSame([], $first->pending());

        $row = DB::table('monitoring_daily')->where(['source' => 'request', 'metric' => 'ttfb', 'site_id' => 1, 'locale' => '*', 'subject' => '*'])->first();
        $this->assertSame('2026-11-16', $row->day);
        $this->assertEquals([1, 10.0, 10.0, 10.0], [$row->count, $row->sum, $row->min, $row->max]);
        $this->assertSame(21, DB::table('monitoring_daily')->count());

        $second = new Aggregator;
        $second->addRequest(['site' => 1, 'loc' => 'en', 'route' => 'r', 'd' => 30, 's' => 200], $this->at('2026-11-16 20:00:00'));
        $second->addRequest(['site' => 1, 'loc' => 'en', 'route' => 'r', 'd' => 2, 's' => 200], $this->at('2026-11-17 01:00:00'));
        $second->save();

        $this->assertSame(42, DB::table('monitoring_daily')->count(), 'one more day of 21 rows, the first day merged');
        $merged = DB::table('monitoring_daily')->where(['day' => '2026-11-16', 'metric' => 'ttfb', 'site_id' => 1, 'locale' => '*', 'subject' => '*'])->first();
        $this->assertEquals([2, 40.0, 10.0, 30.0], [$merged->count, $merged->sum, $merged->min, $merged->max]);
        $next = DB::table('monitoring_daily')->where(['day' => '2026-11-17', 'metric' => 'ttfb', 'site_id' => 1, 'locale' => '*', 'subject' => '*'])->first();
        $this->assertEquals([1, 2.0, 2.0, 2.0], [$next->count, $next->sum, $next->min, $next->max]);
    }

    public function test_saving_nothing_touches_nothing(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        (new Aggregator)->save();

        $this->assertSame([], $queries);
    }

    public function test_a_figure_that_is_not_a_number_leaves_an_empty_row_of_zeros(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addUptime(1, 5, true, NAN, $this->at());
        $aggregator->save();

        $row = DB::table('monitoring_daily')->where(['source' => 'uptime', 'metric' => 'response'])->first();
        $this->assertEquals([0, 0.0, 0.0, 0.0], [$row->count, $row->sum, $row->min, $row->max]);
    }

    public function test_the_day_and_the_source_are_read_and_written_in_batches_of_two_hundred_rows(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtok(ltrim($query->sql), ' ');
        });

        foreach ([[200, 1], [201, 2]] as [$rows, $batches]) {
            DB::table('monitoring_daily')->delete();
            $aggregator = new Aggregator;
            for ($check = 1; $check <= $rows; $check++) {
                $aggregator->addUptime(1, $check, true, null, $this->at());
            }
            $this->assertCount($rows, $aggregator->pending());

            $queries = [];
            $aggregator->save();

            $verbs = array_values(array_filter($queries, fn ($verb) => $verb === 'select' || $verb === 'insert'));
            sort($verbs);
            $this->assertSame(array_merge(array_fill(0, $batches, 'insert'), array_fill(0, $batches, 'select')), $verbs, "$rows rows");
            $this->assertSame($rows, DB::table('monitoring_daily')->count());
        }
    }

    public function test_the_subjects_asked_for_are_listed_once(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addRequest(['site' => 7, 'loc' => 'en', 'route' => 'blog.show', 'd' => 1], $this->at());

        $selects = [];
        DB::listen(function ($query) use (&$selects): void {
            if (str_starts_with(ltrim($query->sql), 'select')) {
                $selects[] = $query->bindings;
            }
        });
        $aggregator->save();

        $this->assertSame([['2026-11-16', 'request', '*', 'blog.show']], $selects);
    }

    public function test_sources_of_a_day_are_saved_apart(): void
    {
        $aggregator = new Aggregator;
        $aggregator->addRequest(['site' => 1, 'loc' => 'en', 'route' => 'r', 'd' => 1], $this->at());
        $aggregator->addUptime(1, 5, true, 10.0, $this->at());
        $aggregator->addVitals(['site' => 1, 'lcp' => 1000], $this->at());

        $selects = [];
        DB::listen(function ($query) use (&$selects): void {
            if (str_starts_with(ltrim($query->sql), 'select')) {
                $selects[] = array_slice($query->bindings, 0, 2);
            }
        });
        $aggregator->save();

        $this->assertSame([['2026-11-16', 'request'], ['2026-11-16', 'uptime'], ['2026-11-16', 'vital']], $selects);
        $this->assertSame(21 + 2 + 2, DB::table('monitoring_daily')->count());
    }
}
