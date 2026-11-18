<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Rum;

use Atrium\Monitoring\Stats\Vitals;

/**
 * Checks a beacon from the page script and turns it into a buffer record.
 *
 * The body is untrusted: anyone can post anything here. Only a JSON object
 * of the known shape passes; a page address loses its query string and
 * fragment, unknown keys are an error, numbers must be finite and within
 * the limits of Vitals::LIMITS.
 *
 *   {"p":"/news/hello","d":"mobile","c":"4g","v":{"lcp":1234.5,"cls":0.02}}
 */
final class Beacon
{
    public const DEVICES = ['mobile', 'tablet', 'desktop'];

    public const CONNECTIONS = ['slow-2g', '2g', '3g', '4g', 'unknown'];

    private const KEYS = ['p', 'd', 'c', 'v'];

    /**
     * @return array{path:string,device:string,connection:string,vitals:array<string,float>}|null
     */
    public static function parse(string $body): ?array
    {
        $data = json_decode($body, true);

        if (! is_array($data) || array_is_list($data) || array_diff(array_keys($data), self::KEYS) !== []) {
            return null;
        }

        $path = self::path($data['p'] ?? null);
        $device = $data['d'] ?? null;
        $connection = $data['c'] ?? 'unknown';
        $values = $data['v'] ?? null;

        if ($path === null || ! in_array($device, self::DEVICES, true) || ! in_array($connection, self::CONNECTIONS, true)) {
            return null;
        }

        if (! is_array($values) || $values === []) {
            return null;
        }

        $vitals = [];

        foreach ($values as $metric => $value) {
            if (! is_string($metric) || ! isset(Vitals::LIMITS[$metric]) || ! (is_int($value) || is_float($value))) {
                return null;
            }

            if (! is_finite($value) || $value < 0 || $value > Vitals::LIMITS[$metric]) {
                return null;
            }

            $vitals[$metric] = round($value, $metric === 'cls' ? 4 : 1);
        }

        return ['path' => $path, 'device' => $device, 'connection' => $connection, 'vitals' => $vitals];
    }

    private static function path(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || strlen($path) > 2048) {
            return null;
        }

        $path = substr($path, 0, strcspn($path, '?#'));

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || ! mb_check_encoding($path, 'UTF-8')) {
            return null;
        }

        return mb_substr($path, 0, 255);
    }
}
