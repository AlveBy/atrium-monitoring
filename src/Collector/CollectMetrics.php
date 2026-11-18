<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Collector;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Measures the request around the application and writes the line once the
 * response has gone (terminate()), so the visitor does not wait for it.
 */
final class CollectMetrics
{
    public function __construct(private readonly Recorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Not measured: start() leaves the recorder idle and responded() does nothing.
        $this->recorder->start($request);

        $response = $next($request);
        $this->recorder->responded();

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->recorder->finish($request, $response);
    }
}
