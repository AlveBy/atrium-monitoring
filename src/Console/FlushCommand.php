<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Console;

use Atrium\Monitoring\Buffer\Flusher;
use Illuminate\Console\Command;

final class FlushCommand extends Command
{
    protected $signature = 'atrium:monitoring:flush';

    protected $description = 'Write the buffered measurements to the database and add them to the daily percentiles';

    public function handle(Flusher $flusher): int
    {
        $this->info('Written: '.$flusher->flush());

        return self::SUCCESS;
    }
}
