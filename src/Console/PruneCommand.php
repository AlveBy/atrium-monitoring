<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Raw measurements live a week, daily percentiles 13 months (both set in
 * atrium-monitoring.retention). Deleted in slices so a big table is never
 * locked for long.
 */
final class PruneCommand extends Command
{
    private const SLICE = 5000;

    protected $signature = 'atrium:monitoring:prune';

    protected $description = 'Delete raw measurements older than a week and daily aggregates older than 13 months';

    public function handle(): int
    {
        $raw = now()->subDays(max(1, (int) config('atrium-monitoring.retention.raw_days', 7)));
        $aggregates = now()->startOfDay()->subMonths(max(1, (int) config('atrium-monitoring.retention.aggregate_months', 13)));

        $deleted = 0;

        foreach (['monitoring_requests' => 'created_at', 'monitoring_queries' => 'created_at', 'monitoring_vitals' => 'created_at', 'monitoring_check_results' => 'checked_at'] as $table => $column) {
            $deleted += $this->prune($table, $column, $raw);
        }

        $deleted += $this->prune('monitoring_daily', 'day', $aggregates->toDateString());
        $deleted += $this->prune('monitoring_query_texts', 'last_seen_at', $aggregates);
        $deleted += $this->prune('monitoring_incidents', 'resolved_at', $aggregates);

        $this->info('Deleted: '.$deleted);

        return self::SUCCESS;
    }

    private function prune(string $table, string $column, mixed $before): int
    {
        $total = 0;

        do {
            $deleted = DB::table($table)->where($column, '<', $before)->limit(self::SLICE)->delete();
            $total += $deleted;
        } while ($deleted >= self::SLICE);

        return $total;
    }
}
