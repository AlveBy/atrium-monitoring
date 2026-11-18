<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Console;

use Atrium\Monitoring\Collector\Benchmark;
use Illuminate\Console\Command;

final class BenchmarkCommand extends Command
{
    protected $signature = 'atrium:monitoring:benchmark {--requests=2000 : Requests to simulate} {--queries=20 : Queries per request} {--rounds=5 : Rounds, the median one is reported}';

    protected $description = 'Measure what the monitoring middleware adds to a request';

    public function handle(Benchmark $benchmark): int
    {
        $result = $benchmark->run(max(1, (int) $this->option('requests')), max(0, (int) $this->option('queries')), max(1, (int) $this->option('rounds')));

        $this->table(['Requests', 'Queries each', 'Rounds', 'Without, ms', 'With, ms', 'Added per request, ms'], [[
            $result['requests'],
            $result['queries'],
            $result['rounds'],
            number_format($result['baseline_ms'], 4),
            number_format($result['measured_ms'], 4),
            number_format($result['overhead_ms'], 4),
        ]]);

        return $result['overhead_ms'] < 1.0 ? self::SUCCESS : self::FAILURE;
    }
}
