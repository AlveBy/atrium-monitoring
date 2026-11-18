<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Rum;

use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\Sites;
use Atrium\Monitoring\Buffer\Buffer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * POST /atrium/rum — the page script's beacon.
 *
 * Outside the `web` group: no session, no cookie, no CSRF token (a beacon
 * cannot carry one). Answers 204 and nothing else when all is well; 413 for
 * a body over the limit, 422 for anything that is not a beacon, 429 past the
 * rate limit, 403 for a post from another site's page. The address the post
 * came from is only hashed into the rate limiter's key and never stored.
 */
final class BeaconController
{
    public function __invoke(Request $request, Buffer $buffer, Sites $sites, ContentLanguages $languages): Response
    {
        if (! config('atrium-monitoring.rum.enabled', true)) {
            abort(404);
        }

        // Asked not to be tracked: say yes and forget it.
        if (RumScript::optedOut($request)) {
            return response()->noContent();
        }

        $max = (int) config('atrium-monitoring.rum.max_bytes', 2048);

        if ((int) $request->header('Content-Length', '0') > $max) {
            return response()->noContent(413);
        }

        $key = 'atrium-monitoring:rum:'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

        if (RateLimiter::tooManyAttempts($key, (int) config('atrium-monitoring.rum.rate_limit', 30))) {
            return response()->noContent(429);
        }

        RateLimiter::hit($key, 60);

        $body = $request->getContent();

        if (strlen($body) > $max) {
            return response()->noContent(413);
        }

        $origin = $request->header('Origin');

        if (is_string($origin) && $origin !== '' && $origin !== 'null' && parse_url($origin, PHP_URL_HOST) !== $request->getHost()) {
            return response()->noContent(403);
        }

        $beacon = Beacon::parse($body);

        if ($beacon === null) {
            return response()->noContent(422);
        }

        try {
            $site = $sites->forRequest($request->getHost(), $beacon['path']);
            $buffer->push([[
                'k' => 'v',
                'at' => time(),
                'site' => $site->getKey(),
                'loc' => self::locale($beacon['path'], (string) $site->folder, $languages),
                'path' => $beacon['path'],
                'dev' => $beacon['device'],
                'con' => $beacon['connection'],
            ] + $beacon['vitals']]);
        } catch (Throwable) {
            // No sites table yet, a full disk: the visitor's page does not care.
        }

        return response()->noContent();
    }

    /** The content language from the page address: /de/… or the default one. */
    private static function locale(string $path, string $folder, ContentLanguages $languages): string
    {
        // A site in a folder only ever gets the addresses inside it.
        $path = ltrim(substr(trim($path, '/'), strlen($folder)), '/');
        $first = explode('/', $path)[0];

        return $first !== '' && $languages->has($first) ? $first : $languages->default();
    }
}
