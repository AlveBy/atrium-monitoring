<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Buffer;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The early flush, on the queue when atrium-monitoring.buffer.queue names one.
 */
final class FlushBuffer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(Flusher $flusher): void
    {
        $flusher->flush();
    }
}
