<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Collector\CollectMetrics;
use Atrium\Monitoring\Collector\Recorder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The middleware around the recorder: the application runs once whether the
 * request is measured or not, and the line is written only after the response.
 */
final class CollectorMiddlewareTest extends TestCase
{
    public function test_a_request_that_is_not_measured_runs_the_application_once(): void
    {
        config(['atrium-monitoring.server.enabled' => false]);
        $calls = 0;
        $response = new Response('ok');

        $returned = (new CollectMetrics(app(Recorder::class)))->handle(Request::create('/hello'), function () use (&$calls, $response): Response {
            $calls++;

            return $response;
        });

        $this->assertSame(1, $calls);
        $this->assertSame($response, $returned);
    }

    public function test_a_measured_request_runs_the_application_once_and_is_written_after_the_response(): void
    {
        $calls = 0;
        $response = new Response('ok', 201);
        $middleware = new CollectMetrics(app(Recorder::class));
        $request = Request::create('/hello');

        $returned = $middleware->handle($request, function () use (&$calls, $response): Response {
            $calls++;
            usleep(2000);

            return $response;
        });

        $this->assertSame(1, $calls);
        $this->assertSame($response, $returned);
        $this->assertFileDoesNotExist($this->bufferPath.'/buffer.ndjson', 'nothing is written before the response has gone');

        $middleware->terminate($request, $returned);

        $lines = file($this->bufferPath.'/buffer.ndjson', FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(1, $lines);
        $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['r', '/hello', 201], [$record['k'], $record['path'], $record['s']]);
        $this->assertGreaterThanOrEqual(2.0, $record['d'], 'the time to the first byte is taken when the response is ready');
    }
}
