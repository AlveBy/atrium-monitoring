<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Buffer\Flusher;
use Atrium\Monitoring\Models\Incident;
use Atrium\Monitoring\Models\UptimeCheck;
use Atrium\Monitoring\Uptime\UptimeChecker;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class PruneTest extends TestCase
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

    /** One request, one slow query and one visit at each moment. */
    private function measure(string ...$moments): void
    {
        $records = [];

        foreach ($moments as $i => $moment) {
            $at = Carbon::parse($moment)->getTimestamp();
            $records[] = ['k' => 'r', 'at' => $at, 'site' => 1, 'loc' => 'en', 'route' => 'home', 'path' => '/', 'm' => 'GET', 's' => 200, 'd' => 50.0 + $i, 'q' => 1, 'qm' => 1.0, 'ch' => 0, 'cm' => 0, 'mem' => 1024];
            $records[] = ['k' => 'q', 'at' => $at, 'site' => 1, 'route' => 'home', 'path' => '/', 'h' => sha1((string) $i), 'sql' => 'select '.$i, 'c' => 'testing', 'd' => 120.0];
            $records[] = ['k' => 'v', 'at' => $at, 'site' => 1, 'loc' => 'en', 'path' => '/', 'dev' => 'mobile', 'con' => '4g', 'lcp' => 2000, 'inp' => 100, 'cls' => 0.01, 'ttfb' => 300, 'fcp' => 900];
        }

        app(Flusher::class)->write($records);
    }

    public function test_raw_rows_live_a_week(): void
    {
        $this->measure('2026-11-10 11:59:00', '2026-11-10 12:01:00', '2026-11-17 11:00:00');
        $check = new UptimeCheck;
        $check->forceFill(['site_id' => 1, 'name' => 'Demo', 'url' => 'https://demo.example.test/'])->save();
        app(UptimeChecker::class)->record($check, true, 200, 80.0, null, Carbon::parse('2026-11-10 11:00:00'));
        app(UptimeChecker::class)->record($check, true, 200, 90.0, null, Carbon::parse('2026-11-12 08:00:00'));

        $this->artisan('atrium:monitoring:prune')->expectsOutput('Deleted: 4')->assertSuccessful();

        foreach (['monitoring_requests', 'monitoring_queries', 'monitoring_vitals'] as $table) {
            $this->assertSame(2, DB::table($table)->count(), $table);
            $this->assertSame(0, DB::table($table)->where('created_at', '<', '2026-11-10 12:00:00')->count(), $table);
        }

        $this->assertSame([90.0], DB::table('monitoring_check_results')->pluck('response_ms')->map(fn ($v) => (float) $v)->all());
        // The daily percentiles of those days stay.
        $this->assertTrue(DB::table('monitoring_daily')->where('day', '2026-11-10')->exists());
    }

    public function test_daily_rows_query_texts_and_closed_incidents_live_thirteen_months(): void
    {
        $this->measure('2025-10-16 23:59:00', '2025-10-17 00:00:00', '2026-03-01 10:00:00');
        $check = new UptimeCheck;
        $check->forceFill(['site_id' => 1, 'name' => 'Demo', 'url' => 'https://demo.example.test/'])->save();

        foreach ([['2025-09-01', '2025-09-02'], ['2025-10-20', '2025-10-21'], ['2025-08-01', null]] as [$started, $resolved]) {
            Incident::query()->create(['check_id' => $check->id, 'started_at' => $started, 'resolved_at' => $resolved, 'failures' => 3]);
        }

        $this->artisan('atrium:monitoring:prune')->assertSuccessful();

        $days = DB::table('monitoring_daily')->distinct()->orderBy('day')->pluck('day')->map(fn ($day) => substr((string) $day, 0, 10))->all();
        $this->assertSame(['2025-10-17', '2026-03-01'], $days);
        $kept = DB::table('monitoring_query_texts')->pluck('hash')->sort()->values()->all();
        $this->assertSame(collect([sha1('1'), sha1('2')])->sort()->values()->all(), $kept);
        $this->assertSame(['2025-08-01', '2025-10-20'], Incident::query()->orderBy('started_at')->pluck('started_at')->map(fn ($at) => $at->toDateString())->all());
    }

    public function test_the_periods_follow_the_settings_and_never_drop_below_a_day(): void
    {
        $this->measure('2026-11-16 11:00:00', '2026-11-17 11:00:00');

        config(['atrium-monitoring.retention.raw_days' => 0]);
        $this->artisan('atrium:monitoring:prune')->assertSuccessful();
        $this->assertSame(1, DB::table('monitoring_requests')->count());

        config(['atrium-monitoring.retention.aggregate_months' => 1]);
        $this->measure('2026-10-16 10:00:00');
        $this->artisan('atrium:monitoring:prune')->assertSuccessful();
        $this->assertFalse(DB::table('monitoring_daily')->where('day', 'like', '2026-10-16%')->exists());
        $this->assertTrue(DB::table('monitoring_daily')->where('day', 'like', '2026-11-16%')->exists());
    }

    public function test_nothing_to_delete_is_fine(): void
    {
        $this->artisan('atrium:monitoring:prune')->expectsOutput('Deleted: 0')->assertSuccessful();
    }

    public function test_without_settings_the_raw_rows_live_seven_days_and_the_rest_thirteen_months(): void
    {
        config(['atrium-monitoring.retention' => []]);
        $this->measure('2025-10-16 23:59:00', '2025-10-17 00:00:00', '2026-11-10 11:59:00', '2026-11-10 12:01:00');
        $check = new UptimeCheck;
        $check->forceFill(['site_id' => 1, 'name' => 'Demo', 'url' => 'https://demo.example.test/'])->save();

        $this->artisan('atrium:monitoring:prune')->assertSuccessful();

        $this->assertSame(1, DB::table('monitoring_requests')->count());
        $this->assertSame(1, DB::table('monitoring_requests')->where('created_at', '>=', '2026-11-10 12:00:00')->count());
        $days = DB::table('monitoring_daily')->distinct()->orderBy('day')->pluck('day')->map(fn ($day) => substr((string) $day, 0, 10))->all();
        $this->assertSame(['2025-10-17', '2026-11-10'], $days);
    }

    public function test_the_months_of_the_aggregates_never_drop_below_one(): void
    {
        config(['atrium-monitoring.retention.aggregate_months' => 0]);
        $this->measure('2026-11-10 12:00:00');
        $check = new UptimeCheck;
        $check->forceFill(['site_id' => 1, 'name' => 'Demo', 'url' => 'https://demo.example.test/'])->save();
        Incident::query()->create(['check_id' => $check->id, 'started_at' => '2026-11-09', 'resolved_at' => '2026-11-10', 'failures' => 3]);
        Incident::query()->create(['check_id' => $check->id, 'started_at' => '2026-09-09', 'resolved_at' => '2026-09-10', 'failures' => 3]);

        $this->artisan('atrium:monitoring:prune')->assertSuccessful();

        $this->assertTrue(DB::table('monitoring_daily')->where('day', 'like', '2026-11-10%')->exists());
        $this->assertSame(1, DB::table('monitoring_query_texts')->count());
        $this->assertSame(['2026-11-10'], Incident::query()->pluck('resolved_at')->map(fn ($at) => $at->toDateString())->all());
    }

    public function test_it_reports_every_row_it_removed(): void
    {
        $this->measure('2025-10-16 23:59:00', '2025-10-17 00:00:00', '2026-03-01 10:00:00', '2026-11-10 11:00:00');
        $check = new UptimeCheck;
        $check->forceFill(['site_id' => 1, 'name' => 'Demo', 'url' => 'https://demo.example.test/'])->save();
        app(UptimeChecker::class)->record($check, true, 200, 80.0, null, Carbon::parse('2026-11-10 11:00:00'));
        Incident::query()->create(['check_id' => $check->id, 'started_at' => '2025-09-01', 'resolved_at' => '2025-09-02', 'failures' => 3]);
        $tables = ['monitoring_requests', 'monitoring_queries', 'monitoring_vitals', 'monitoring_check_results', 'monitoring_daily', 'monitoring_query_texts', 'monitoring_incidents'];
        $count = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), array_combine($tables, $tables));
        $before = $count();

        Artisan::call('atrium:monitoring:prune');

        $after = $count();
        // Something went from each of the three kinds: raw rows, daily rows and texts, incidents.
        $this->assertLessThan($before['monitoring_requests'], $after['monitoring_requests']);
        $this->assertLessThan($before['monitoring_daily'], $after['monitoring_daily']);
        $this->assertLessThan($before['monitoring_query_texts'], $after['monitoring_query_texts']);
        $this->assertSame(0, $after['monitoring_incidents']);
        $this->assertSame('Deleted: '.(array_sum($before) - array_sum($after))."\n", Artisan::output());
    }

    /** Old rows in the raw table, as many as asked for, and one of today. */
    private function fill(int $old): void
    {
        $row = ['created_at' => '2026-11-01 00:00:00', 'site_id' => 1, 'locale' => 'en', 'route' => 'home', 'path' => '/', 'method' => 'GET', 'status' => 200, 'duration_ms' => 1.0, 'db_queries' => 0, 'db_ms' => 0.0, 'cache_hits' => 0, 'cache_misses' => 0, 'memory_kb' => 0];

        foreach (array_chunk(array_fill(0, $old, $row), 1000) as $rows) {
            DB::table('monitoring_requests')->insert($rows);
        }

        DB::table('monitoring_requests')->insert([['created_at' => '2026-11-17 11:00:00'] + $row]);
    }

    /** @return array<string,array{0:int,1:int}> rows to delete => statements it takes */
    public static function slices(): array
    {
        return [
            'a few' => [3, 1],
            'one short of a slice' => [4999, 1],
            'exactly a slice, the next one finds nothing' => [5000, 2],
            'a slice and one more' => [5001, 2],
            'two slices and a bit' => [10_007, 3],
        ];
    }

    #[DataProvider('slices')]
    public function test_a_big_table_is_deleted_a_slice_at_a_time_until_it_is_clear(int $old, int $statements): void
    {
        $this->fill($old);
        $deletes = 0;
        DB::listen(function (QueryExecuted $query) use (&$deletes): void {
            if (preg_match('/^delete from [`"]?monitoring_requests[`"]?/', $query->sql) === 1) {
                $deletes++;
            }
        });

        $this->artisan('atrium:monitoring:prune')->expectsOutput('Deleted: '.$old)->assertSuccessful();

        $this->assertSame(1, DB::table('monitoring_requests')->count());
        $this->assertSame($statements, $deletes);
    }
}
