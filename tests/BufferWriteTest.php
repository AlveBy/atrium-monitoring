<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Buffer\Buffer;
use Atrium\Monitoring\Buffer\Flusher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

final class BufferWriteTest extends TestCase
{
    /** The rows of every insert into the raw tables, in the order of the statements. */
    private array $inserts = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(function (QueryExecuted $query): void {
            if (preg_match('/^insert into [`"]?(monitoring_(?:requests|queries|vitals))[`"]?/', $query->sql, $match) === 1) {
                $this->inserts[$match[1]][] = substr_count($query->sql, '), (') + 1;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function row(string $table): array
    {
        return Arr::except((array) DB::table($table)->first(), ['id', 'created_at']);
    }

    private function wide(int $width): string
    {
        // The first character tells where the cut starts; é takes two bytes.
        return 'Z'.str_repeat('é', $width * 2);
    }

    public function test_rows_go_to_the_database_two_hundred_at_a_time(): void
    {
        $records = [];

        for ($i = 0; $i < 250; $i++) {
            $records[] = ['k' => 'r'];
            $records[] = ['k' => 'q', 'h' => sha1((string) $i)];
            $records[] = ['k' => 'v'];
        }

        $this->assertSame(750, app(Flusher::class)->write($records));
        $this->assertSame(['monitoring_requests' => [200, 50], 'monitoring_queries' => [200, 50], 'monitoring_vitals' => [200, 50]], $this->inserts);
        $this->assertSame(250, DB::table('monitoring_query_texts')->count());
    }

    public function test_a_file_is_written_in_slices_of_five_hundred_records(): void
    {
        app(Buffer::class)->push(array_fill(0, 501, ['k' => 'r']));

        $this->assertSame(501, app(Flusher::class)->flush());
        $this->assertSame(['monitoring_requests' => [200, 200, 100, 1]], $this->inserts);
    }

    public function test_a_record_with_nothing_but_its_kind_gets_the_defaults(): void
    {
        $hash = sha1('bare');

        $this->assertSame(3, app(Flusher::class)->write([['k' => 'r'], ['k' => 'q', 'h' => $hash], ['k' => 'v']]));

        $this->assertEquals(['site_id' => 0, 'locale' => '', 'route' => '', 'path' => '/', 'method' => 'GET', 'status' => 0, 'duration_ms' => 0, 'db_queries' => 0, 'db_ms' => 0, 'cache_hits' => 0, 'cache_misses' => 0, 'memory_kb' => 0], $this->row('monitoring_requests'));
        $this->assertEquals(['site_id' => 0, 'route' => '', 'path' => '/', 'sql_hash' => $hash, 'duration_ms' => 0], $this->row('monitoring_queries'));
        $this->assertEquals(['hash' => $hash, 'sql' => '', 'connection' => '', 'last_seen_at' => DB::table('monitoring_query_texts')->value('last_seen_at')], $this->row('monitoring_query_texts'));
        $this->assertEquals(['site_id' => 0, 'locale' => '', 'path' => '/', 'device' => '', 'connection' => '', 'lcp' => null, 'inp' => null, 'cls' => null, 'ttfb' => null, 'fcp' => null], $this->row('monitoring_vitals'));
    }

    public function test_every_field_is_stored_and_the_texts_are_cut_to_their_columns(): void
    {
        $at = Carbon::parse('2026-11-17 10:30:00', 'UTC')->getTimestamp();
        $hash = sha1('full');

        app(Flusher::class)->write([
            ['k' => 'r', 'at' => $at, 'site' => 7, 'loc' => $this->wide(20), 'route' => $this->wide(300), 'path' => $this->wide(300), 'm' => $this->wide(20), 's' => 503, 'd' => 12.5, 'q' => 3, 'qm' => 4.5, 'ch' => 5, 'cm' => 6, 'mem' => 7000],
            ['k' => 'q', 'at' => $at, 'site' => 8, 'route' => $this->wide(300), 'path' => $this->wide(300), 'h' => $hash, 'd' => 150.5, 'sql' => 'select 1', 'c' => $this->wide(100)],
            ['k' => 'v', 'at' => $at, 'site' => 9, 'loc' => $this->wide(20), 'path' => $this->wide(300), 'dev' => $this->wide(20), 'con' => $this->wide(20), 'lcp' => 2100, 'inp' => 90.5, 'cls' => 0.05, 'ttfb' => 300, 'fcp' => 900],
        ]);

        $this->assertEquals(['site_id' => 7, 'locale' => 'Z'.str_repeat('é', 11), 'route' => 'Z'.str_repeat('é', 190), 'path' => 'Z'.str_repeat('é', 254), 'method' => 'Z'.str_repeat('é', 7), 'status' => 503, 'duration_ms' => 12.5, 'db_queries' => 3, 'db_ms' => 4.5, 'cache_hits' => 5, 'cache_misses' => 6, 'memory_kb' => 7000], $this->row('monitoring_requests'));
        $this->assertEquals(['site_id' => 8, 'route' => 'Z'.str_repeat('é', 190), 'path' => 'Z'.str_repeat('é', 254), 'sql_hash' => $hash, 'duration_ms' => 150.5], $this->row('monitoring_queries'));
        $this->assertSame('Z'.str_repeat('é', 63), DB::table('monitoring_query_texts')->value('connection'));
        $this->assertEquals(['site_id' => 9, 'locale' => 'Z'.str_repeat('é', 11), 'path' => 'Z'.str_repeat('é', 254), 'device' => 'Z'.str_repeat('é', 7), 'connection' => 'Z'.str_repeat('é', 7), 'lcp' => 2100, 'inp' => 90.5, 'cls' => 0.05, 'ttfb' => 300, 'fcp' => 900], $this->row('monitoring_vitals'));
        $this->assertSame('2026-11-17 10:30:00', substr((string) DB::table('monitoring_requests')->value('created_at'), 0, 19));
    }

    public function test_numbers_are_numbers_whatever_the_record_holds(): void
    {
        app(Flusher::class)->write([
            ['k' => 'r', 'site' => 'abc', 's' => 'abc', 'd' => 'abc', 'q' => 'abc', 'qm' => 'abc', 'ch' => 'abc', 'cm' => 'abc', 'mem' => 'abc'],
            ['k' => 'r', 'site' => 3.9, 's' => 200.9, 'd' => '12.5ms', 'q' => 2.5, 'qm' => '0.5s', 'ch' => 7.7, 'cm' => 8.8, 'mem' => 9.9],
            ['k' => 'q', 'h' => sha1('a'), 'site' => 'abc', 'd' => 'abc'],
            ['k' => 'q', 'h' => sha1('b'), 'site' => 4.9, 'd' => '1.5ms'],
            ['k' => 'v', 'site' => 'abc'],
            ['k' => 'v', 'site' => 5.9],
        ]);

        $zeros = ['site_id' => 0, 'status' => 0, 'duration_ms' => 0, 'db_queries' => 0, 'db_ms' => 0, 'cache_hits' => 0, 'cache_misses' => 0, 'memory_kb' => 0];
        $parts = ['site_id' => 3, 'status' => 200, 'duration_ms' => 12.5, 'db_queries' => 2, 'db_ms' => 0.5, 'cache_hits' => 7, 'cache_misses' => 8, 'memory_kb' => 9];
        $requests = DB::table('monitoring_requests')->orderBy('id')->get(array_keys($zeros))->map(fn (object $row): array => (array) $row)->all();
        $this->assertEquals([$zeros, $parts], $requests);
        $this->assertEquals([['site_id' => 0, 'duration_ms' => 0], ['site_id' => 4, 'duration_ms' => 1.5]], DB::table('monitoring_queries')->orderBy('id')->get(['site_id', 'duration_ms'])->map(fn (object $row): array => (array) $row)->all());
        $this->assertEquals([0, 5], DB::table('monitoring_vitals')->orderBy('id')->pluck('site_id')->all());
    }

    public function test_a_vital_is_a_number_or_nothing(): void
    {
        app(Flusher::class)->write([['k' => 'v', 'lcp' => '2100.5', 'inp' => 'abc', 'cls' => 0.0, 'ttfb' => null, 'fcp' => '']]);

        // PostgreSQL on PHP 8.3 hands decimals back as strings: the value is what counts, a missing one stays null.
        $row = array_map(fn (mixed $value): ?float => $value === null ? null : (float) $value, Arr::only($this->row('monitoring_vitals'), ['lcp', 'inp', 'cls', 'ttfb', 'fcp']));

        $this->assertSame(['lcp' => 2100.5, 'inp' => null, 'cls' => 0.0, 'ttfb' => null, 'fcp' => null], $row);
    }

    public function test_a_query_needs_a_real_hash(): void
    {
        $written = app(Flusher::class)->write([
            ['k' => 'q', 'h' => 1234567890],
            ['k' => 'q', 'h' => substr(sha1('x'), 1)],
            ['k' => 'q', 'h' => sha1('x').'0'],
            ['k' => 'q'],
        ]);

        $this->assertSame(0, $written);
        $this->assertSame(0, DB::table('monitoring_queries')->count());
        $this->assertSame(0, DB::table('monitoring_query_texts')->count());
    }

    public function test_the_text_of_a_query_follows_its_latest_sighting(): void
    {
        $hash = sha1('same');
        $flusher = app(Flusher::class);

        $flusher->write([['k' => 'q', 'h' => $hash, 'at' => Carbon::parse('2026-11-16 10:00:00', 'UTC')->getTimestamp(), 'sql' => 'select 1', 'c' => 'first']]);
        $flusher->write([['k' => 'q', 'h' => $hash, 'at' => Carbon::parse('2026-11-17 11:00:00', 'UTC')->getTimestamp(), 'sql' => 'select 2', 'c' => 'second'], ['k' => 'q', 'h' => sha1('other'), 'sql' => 'select 3']]);

        $row = DB::table('monitoring_query_texts')->where('hash', $hash)->first();
        $this->assertSame(['select 2', 'second', '2026-11-17 11:00:00'], [$row->sql, $row->connection, substr((string) $row->last_seen_at, 0, 19)]);
        $this->assertSame(2, DB::table('monitoring_query_texts')->count());
    }

    public function test_queries_and_vitals_are_added_to_the_daily_histograms(): void
    {
        $hash = sha1('slow');
        $at = Carbon::parse('2026-11-17 10:30:00', 'UTC')->getTimestamp();

        app(Flusher::class)->write([
            ['k' => 'q', 'at' => $at, 'site' => 2, 'h' => $hash, 'd' => 150.0],
            ['k' => 'v', 'at' => $at, 'site' => 2, 'loc' => 'en', 'path' => '/a', 'dev' => 'mobile', 'lcp' => 2100],
        ]);

        $query = DB::table('monitoring_daily')->where('source', 'query')->orderBy('subject')->get();
        $this->assertSame(['*', $hash], $query->pluck('subject')->all());
        $this->assertSame([150.0, 150.0], $query->map(fn (object $row): float => (float) $row->sum)->all());
        $this->assertSame([2, 2], $query->map(fn (object $row): int => (int) $row->site_id)->all());

        $vital = DB::table('monitoring_daily')->where('source', 'vital')->where('metric', 'lcp')->get();
        $this->assertCount(4, $vital, 'overall, per locale, per page and per device');
        $this->assertSame([1], $vital->pluck('count')->map(fn ($count): int => (int) $count)->unique()->values()->all());
        $this->assertSame([2100.0], $vital->pluck('sum')->map(fn ($sum): float => (float) $sum)->unique()->values()->all());
    }

    public function test_nothing_to_write_opens_no_transaction(): void
    {
        $began = 0;
        Event::listen(TransactionBeginning::class, function () use (&$began): void {
            $began++;
        });

        $this->assertSame(0, app(Flusher::class)->write([]));
        $this->assertSame(0, $began);

        $this->assertSame(1, app(Flusher::class)->write([['k' => 'r']]));
        $this->assertSame(1, $began);
    }

    public function test_the_flush_lock_lives_in_the_store_of_the_settings(): void
    {
        config(['cache.stores.flush-lock' => ['driver' => 'array'], 'atrium-monitoring.buffer.lock_store' => 'flush-lock']);
        app(Buffer::class)->push([['k' => 'r']]);
        $held = Cache::store('flush-lock')->lock('atrium-monitoring:flush', 60);
        $this->assertTrue($held->get());

        $this->assertSame(0, app(Flusher::class)->flush(), 'held in that store');
        $this->assertSame(0, DB::table('monitoring_requests')->count());

        $held->release();
        $this->assertSame(1, app(Flusher::class)->flush());
    }

    public function test_without_a_store_in_the_settings_the_default_one_holds_the_lock(): void
    {
        foreach ([null, '', 42] as $setting) {
            config(['atrium-monitoring.buffer.lock_store' => $setting]);
            app(Buffer::class)->push([['k' => 'r']]);
            $held = Cache::lock('atrium-monitoring:flush', 60);
            $this->assertTrue($held->get());

            $this->assertSame(0, app(Flusher::class)->flush(), var_export($setting, true));

            $held->release();
            $this->assertSame(1, app(Flusher::class)->flush());
        }
    }

    public function test_the_lock_is_held_for_five_minutes_and_let_go_after_the_flush(): void
    {
        Carbon::setTestNow('2026-11-17 12:00:00');
        $seen = null;
        $buffer = new class($this->bufferPath, 1000, $seen) extends Buffer
        {
            public function __construct(string $directory, int $maxBytes, private mixed &$seen)
            {
                parent::__construct($directory, $maxBytes);
            }

            public function claim(): array
            {
                $this->seen = Cache::getStore()->locks['atrium-monitoring:flush'] ?? null;

                return parent::claim();
            }
        };

        $this->assertSame(0, (new Flusher($buffer))->flush());

        $this->assertNotNull($seen, 'held while the files are read');
        $this->assertSame('2026-11-17 12:05:00', $seen['expiresAt']->toDateTimeString());
        $free = Cache::lock('atrium-monitoring:flush', 60);
        $this->assertTrue($free->get(), 'let go afterwards');
        $free->release();
    }

    public function test_the_lock_is_let_go_when_the_flush_fails(): void
    {
        $buffer = new class($this->bufferPath, 1000) extends Buffer
        {
            public function claim(): array
            {
                throw new RuntimeException('disk gone');
            }
        };

        try {
            (new Flusher($buffer))->flush();
            $this->fail('the failure must not be swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('disk gone', $e->getMessage());
        }

        $free = Cache::lock('atrium-monitoring:flush', 60);
        $this->assertTrue($free->get());
        $free->release();
    }
}
