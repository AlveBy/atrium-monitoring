<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Buffer;

use Generator;

/**
 * Measurements waiting for the database, one JSON line each.
 *
 * Appending a line under an exclusive lock costs a few microseconds and
 * needs neither Redis nor a queue, so a request pays almost nothing; the
 * database sees one batch a minute instead of a write per page. Each server
 * keeps its own file and flushes it into the shared database.
 *
 * A flush renames the file first: writers start a new one at once, and the
 * renamed file is locked before it is read, so a writer that got there
 * first finishes its line.
 */
class Buffer
{
    private const FILE = 'buffer.ndjson';

    public function __construct(private readonly string $directory, private readonly int $maxBytes) {}

    /**
     * Appends the records. True when the file has grown past the limit and
     * is worth flushing now rather than at the next minute.
     *
     * @param  array<int,array<string,mixed>>  $records
     */
    public function push(array $records): bool
    {
        if ($records === []) {
            return false;
        }

        $lines = '';

        foreach ($records as $record) {
            $lines .= json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        }

        if (! is_dir($this->directory)) {
            // A parallel request may make it first: writing tells whether it is there.
            @mkdir($this->directory, 0775, true);
        }

        if (@file_put_contents($this->path(), $lines, FILE_APPEND | LOCK_EX) === false) {
            return false;
        }

        return $this->size() > $this->maxBytes;
    }

    /**
     * Takes the current file and any left by a flush that died half way.
     *
     * @return array<int,string> the files to read, oldest first
     */
    public function claim(): array
    {
        $file = $this->path();

        if (is_file($file)) {
            $work = $this->directory.'/work-'.str_replace('.', '', uniqid('', true)).'.ndjson';

            if (@rename($file, $work)) {
                // Wait for a writer that opened the file before the rename.
                $handle = @fopen($work, 'r');

                if ($handle !== false) {
                    flock($handle, LOCK_EX);
                    fclose($handle);
                }
            }
        }

        // glob() sorts by name, and the names start with the time.
        return glob($this->directory.'/work-*.ndjson') ?: [];
    }

    /**
     * The records of a claimed file; broken lines are skipped.
     *
     * @return Generator<int,array<string,mixed>>
     */
    public function read(string $file): Generator
    {
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true);

                if (is_array($record) && isset($record['k'])) {
                    yield $record;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    public function path(): string
    {
        return $this->directory.'/'.self::FILE;
    }

    public function size(): int
    {
        clearstatcache(true, $this->path());

        return (int) @filesize($this->path());
    }
}
