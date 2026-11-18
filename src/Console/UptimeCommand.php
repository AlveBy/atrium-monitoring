<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Console;

use Atrium\Monitoring\Uptime\UptimeChecker;
use Illuminate\Console\Command;

final class UptimeCommand extends Command
{
    protected $signature = 'atrium:monitoring:uptime';

    protected $description = 'Open the front page of every site and the chosen addresses; open and close incidents';

    public function handle(UptimeChecker $checker): int
    {
        if (! config('atrium-monitoring.uptime.enabled', true)) {
            $this->line('Uptime checks are off.');

            return self::SUCCESS;
        }

        $checker->syncHomes();
        $this->info('Checked: '.$checker->run());

        return self::SUCCESS;
    }
}
