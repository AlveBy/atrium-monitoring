<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Collector;

/**
 * The shape of a query without its values.
 *
 * Bindings never reach the log (Laravel keeps them apart), but a raw
 * where('email', '=', DB::raw("'x@y.z'")) or a number written into the SQL
 * would; literals become "?" and lists of them one "?, ...", so the same
 * query from different pages and visitors is one line of the report.
 */
final class SqlNormalizer
{
    public const MAX_LENGTH = 4000;

    public static function normalize(string $sql): string
    {
        $sql = self::replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql);
        $sql = self::replace('/(?<![\w$.])-?\d+(?:\.\d+)?(?![\w$])/', '?', $sql);
        $sql = self::replace('/\?(?:\s*,\s*\?)+/', '?, ...', $sql);
        $sql = trim(self::replace('/\s+/', ' ', $sql));

        return mb_substr($sql, 0, self::MAX_LENGTH);
    }

    public static function hash(string $normalized, string $connection = ''): string
    {
        return sha1($connection."\n".$normalized);
    }

    /** preg_replace, with nothing instead of null when the engine gives up (a backtracking limit). */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? '';
    }
}
