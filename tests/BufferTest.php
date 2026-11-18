<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Buffer\Buffer;
use Atrium\Monitoring\Buffer\FlushBuffer;
use Atrium\Monitoring\Buffer\Flusher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class BufferTest extends TestCase
{
    private function record(array $overrides = []): array
    {
        return $overrides + ['k' => 'r', 'at' => time(), 'site' => 0, 'loc' => 'en', 'route' => 'home', 'path' => '/', 'm' => 'GET', 's' => 200, 'd' => 12.5, 'q' => 1, 'qm' => 0.4, 'ch' => 0, 'cm' => 0, 'mem' => 4096];
    }

    public function test_push_appends_lines_and_says_when_the_file_is_full(): void
    {
        $buffer = new Buffer($this->bufferPath, 400);

        $this->assertFalse($buffer->push([]));
        $this->assertSame(0, $buffer->size());
        $this->assertFalse($buffer->push([$this->record()]));
        $this->assertTrue($buffer->push([$this->record(), $this->record(), $this->record()]));
        $this->assertCount(4, file($buffer->path()));
    }

    public function test_push_gives_up_quietly_when_the_folder_cannot_be_made(): void
    {
        $file = $this->bufferPath.'-file';
        file_put_contents($file, 'x');

        try {
            $this->assertFalse((new Buffer($file.'/sub', 10))->push([$this->record()]));
        } finally {
            unlink($file);
        }
    }

    public function test_claim_takes_the_file_and_leftovers_and_reading_skips_broken_lines(): void
    {
        $buffer = new Buffer($this->bufferPath, 1_000_000);
        $buffer->push([$this->record(['route' => 'first'])]);
        // A flush that died earlier left its file behind.
        file_put_contents($this->bufferPath.'/work-0000.ndjson', json_encode($this->record(['route' => 'left over']))."\n{broken\n\"no kind\"\n".json_encode(['x' => 1])."\n");

        $files = $buffer->claim();
        $this->assertCount(2, $files);
        $this->assertSame(0, $buffer->size(), 'writers start a new file');
        $this->assertMatchesRegularExpression('~/work-[0-9a-f]+\.ndjson$~', $files[1], 'no dots in the name of the claimed file');
        // Only the one good line of the leftover file: not the broken one, the bare string or the record without a kind.
        $this->assertSame(['left over'], array_column(iterator_to_array($buffer->read($files[0]), false), 'route'));
        $this->assertCount(1, iterator_to_array($buffer->read($files[0]), false));
        $this->assertSame(['first'], array_column(iterator_to_array($buffer->read($files[1]), false), 'route'));
        $this->assertSame([], iterator_to_array($buffer->read($this->bufferPath.'/missing.ndjson')));
        $this->assertSame([], (new Buffer($this->bufferPath.'/empty', 10))->claim());
    }

    public function test_flush_writes_raw_rows_and_aggregates_and_empties_the_buffer(): void
    {
        $buffer = app(Buffer::class);
        $hash = sha1('x');
        $records = [];

        for ($i = 0; $i < 1100; $i++) {
            $records[] = $this->record(['d' => (float) $i]);
        }

        $records[] = ['k' => 'q', 'at' => time(), 'site' => 0, 'route' => 'home', 'path' => '/', 'h' => $hash, 'sql' => 'select * from "pages" where "id" = ?', 'c' => 'testing', 'd' => 150.0];
        $records[] = ['k' => 'q', 'h' => 'short', 'd' => 1.0];
        $records[] = ['k' => 'v', 'at' => time(), 'site' => 0, 'loc' => 'en', 'path' => '/', 'dev' => 'mobile', 'con' => '4g', 'lcp' => 2100, 'cls' => 0.05];
        $records[] = ['k' => 'unknown'];
        $buffer->push($records);

        $this->assertSame(1102, app(Flusher::class)->flush());
        $this->assertSame(1100, DB::table('monitoring_requests')->count());
        $this->assertSame(1, DB::table('monitoring_queries')->count());
        $this->assertSame('select * from "pages" where "id" = ?', DB::table('monitoring_query_texts')->where('hash', $hash)->value('sql'));
        $vital = DB::table('monitoring_vitals')->first();
        $this->assertEqualsWithDelta(2100, (float) $vital->lcp, 1e-9);
        $this->assertNull($vital->inp);
        $this->assertSame(1100, (int) DB::table('monitoring_daily')->where('metric', 'ttfb')->where('locale', '*')->where('subject', '*')->value('count'));
        $this->assertSame([], glob($this->bufferPath.'/*.ndjson'));

        $this->assertSame(0, app(Flusher::class)->flush(), 'nothing left');
        $this->assertSame(0, app(Flusher::class)->write([]));
    }

    public function test_only_one_flush_at_a_time(): void
    {
        app(Buffer::class)->push([$this->record()]);
        $lock = Cache::lock('atrium-monitoring:flush', 60);
        $this->assertTrue($lock->get());

        try {
            $this->assertSame(0, app(Flusher::class)->flush());
            $this->assertSame(0, DB::table('monitoring_requests')->count());
        } finally {
            $lock->release();
        }

        $this->artisan('atrium:monitoring:flush')->expectsOutput('Written: 1')->assertSuccessful();
        $this->assertSame(1, DB::table('monitoring_requests')->count());
    }

    public function test_the_queued_job_flushes(): void
    {
        app(Buffer::class)->push([$this->record()]);

        (new FlushBuffer)->handle(app(Flusher::class));

        $this->assertSame(1, DB::table('monitoring_requests')->count());
    }

    public function test_push_gives_up_quietly_when_the_file_cannot_be_written(): void
    {
        $buffer = new Buffer($this->bufferPath, 0);
        // A folder where the file should be: appending fails.
        mkdir($buffer->path(), 0775, true);

        try {
            $this->assertFalse($buffer->push([$this->record()]));
            $this->assertTrue(is_dir($buffer->path()));
        } finally {
            rmdir($buffer->path());
        }
    }

    public function test_an_empty_push_touches_nothing(): void
    {
        $buffer = new Buffer($this->bufferPath.'/never', 10);

        $this->assertFalse($buffer->push([]));
        $this->assertDirectoryDoesNotExist($this->bufferPath.'/never');
    }

    public function test_a_line_is_json_with_slashes_and_unicode_left_as_they_are(): void
    {
        $buffer = new Buffer($this->bufferPath, 1_000_000);

        $buffer->push([['k' => 'r', 'path' => '/a/b', 'route' => 'é€'], ['k' => 'r', 'bad' => "x\xB1y"]]);

        $this->assertSame("{\"k\":\"r\",\"path\":\"/a/b\",\"route\":\"é€\"}\n{\"k\":\"r\",\"bad\":\"x\u{FFFD}y\"}\n", file_get_contents($buffer->path()));
    }

    public function test_the_folder_is_made_with_its_parents_and_group_access(): void
    {
        $umask = umask(0);

        try {
            $buffer = new Buffer($this->bufferPath.'/a/b', 1_000_000);
            $this->assertFalse($buffer->push([['k' => 'r']]));
        } finally {
            umask($umask);
        }

        $this->assertSame('0775', substr(sprintf('%o', fileperms($this->bufferPath.'/a/b')), -4));
        $this->assertSame('0775', substr(sprintf('%o', fileperms($this->bufferPath.'/a')), -4));
        $this->assertSame("{\"k\":\"r\"}\n", file_get_contents($buffer->path()));
    }

    public function test_the_file_is_full_only_when_it_is_past_the_limit(): void
    {
        // One line is {"k":"r"} and a newline: 10 bytes.
        $this->assertFalse((new Buffer($this->bufferPath.'/exact', 10))->push([['k' => 'r']]), 'as big as the limit is not past it');
        $this->assertTrue((new Buffer($this->bufferPath.'/over', 9))->push([['k' => 'r']]));
    }

    public function test_the_size_is_read_afresh_every_time(): void
    {
        $buffer = new Buffer($this->bufferPath, 15);

        $this->assertFalse($buffer->push([['k' => 'r']]));
        // A size seen earlier in this process must not hide the growth.
        $this->assertSame(10, filesize($buffer->path()));
        $this->assertTrue($buffer->push([['k' => 'r']]));
        $this->assertSame(20, $buffer->size());

        $this->assertSame(20, filesize($buffer->path()));
        file_put_contents($buffer->path(), "{\"k\":\"r\"}\n", FILE_APPEND);
        $this->assertSame(30, $buffer->size());
    }

    public function test_a_writer_that_had_the_file_open_before_the_flush_finishes_its_line(): void
    {
        $buffer = new Buffer($this->bufferPath, 1_000_000);
        $buffer->push([$this->record(['route' => 'first'])]);
        // Another process opens the file, locks it, and writes its line a moment after the flush has renamed it.
        $script = '$h = fopen('.var_export($buffer->path(), true).', "a"); flock($h, LOCK_EX); fwrite(STDOUT, "locked\n"); fflush(STDOUT); usleep(700000); fwrite($h, json_encode(["k" => "r", "route" => "late"])."\n"); fflush($h); flock($h, LOCK_UN); fclose($h);';
        $process = proc_open([PHP_BINARY, '-n', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        try {
            $this->assertSame("locked\n", fgets($pipes[1]));
            $files = $buffer->claim();
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        $this->assertCount(1, $files);
        $this->assertSame(['first', 'late'], array_column(iterator_to_array($buffer->read($files[0]), false), 'route'));
    }

    public function test_reading_gives_the_file_back_even_when_the_reader_stops_early(): void
    {
        $buffer = new Buffer($this->bufferPath, 1_000_000);
        $buffer->push([$this->record(), $this->record()]);
        $files = $buffer->claim();
        $before = count(get_resources('stream'));

        $records = $buffer->read($files[0]);
        $this->assertSame('home', $records->current()['route']);
        $this->assertCount($before + 1, get_resources('stream'), 'open while reading');

        unset($records);
        $this->assertCount($before, get_resources('stream'), 'closed when the reader is gone');
    }
}
