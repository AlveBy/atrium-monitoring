<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Collector\SqlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class SqlNormalizerTest extends BaseTestCase
{
    /** @return array<string,array{0:string,1:string}> */
    public static function queries(): array
    {
        return [
            'bindings stay' => ['select * from "users" where "id" = ?', 'select * from "users" where "id" = ?'],
            'string literal' => ["select * from users where email = 'anna@example.com'", 'select * from users where email = ?'],
            'escaped quotes' => ["select 'it''s', 'a\\'b' from t", 'select ?, ... from t'],
            'numbers and lists' => ['select * from t where id in (1, 2, 3, 4) limit 10 offset -5', 'select * from t where id in (?, ...) limit ? offset ?'],
            'decimals' => ['update t set price = 12.50 where id = 3', 'update t set price = ? where id = ?'],
            'identifiers with digits stay' => ['select col2, t1.x3 from table_2024 as t1', 'select col2, t1.x3 from table_2024 as t1'],
            'placeholders of a list' => ['insert into t (a, b) values (?, ?), (?, ?)', 'insert into t (a, b) values (?, ...), (?, ...)'],
            'whitespace' => ["select\n\t*\n  from   t", 'select * from t'],
            'whitespace around' => ["\n  select 1 \t", 'select ?'],
            'postgres positional' => ['select * from t where a = $1', 'select * from t where a = $1'],
        ];
    }

    #[DataProvider('queries')]
    public function test_values_are_taken_out(string $sql, string $expected): void
    {
        $this->assertSame($expected, SqlNormalizer::normalize($sql));
    }

    public function test_long_queries_are_cut_and_hashes_depend_on_the_connection(): void
    {
        $this->assertSame(SqlNormalizer::MAX_LENGTH, mb_strlen(SqlNormalizer::normalize('select '.str_repeat('abc, ', 2000))));
        $this->assertSame(40, strlen(SqlNormalizer::hash('select ?')));
        $this->assertSame(sha1("mysql\nselect ?"), SqlNormalizer::hash('select ?', 'mysql'));
        $this->assertSame(sha1("\nselect ?"), SqlNormalizer::hash('select ?'));
        $this->assertNotSame(SqlNormalizer::hash('a', 'bc'), SqlNormalizer::hash('ab', 'c'), 'the connection and the query do not run together');
        $this->assertNotSame(SqlNormalizer::hash('select ?', 'mysql'), SqlNormalizer::hash('select ?', 'pgsql'));
        $this->assertSame(SqlNormalizer::hash('select ?', 'mysql'), SqlNormalizer::hash('select ?', 'mysql'));
    }

    public function test_the_cut_counts_characters_not_bytes(): void
    {
        $normalized = SqlNormalizer::normalize('select '.str_repeat('é', 4100));

        $this->assertSame('select '.str_repeat('é', SqlNormalizer::MAX_LENGTH - 7), $normalized);
        $this->assertSame(SqlNormalizer::MAX_LENGTH, mb_strlen($normalized));
    }
}
