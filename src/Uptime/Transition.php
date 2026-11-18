<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Uptime;

use Atrium\Monitoring\Models\UptimeCheck;

/**
 * What one check result does to an address.
 *
 *   result  before                      after
 *   good    any                         up, 0 failures; an open incident is resolved
 *   bad     fewer than N-1 failures     failing, failures + 1
 *   bad     the N-th failure in a row   down, an incident is opened and alerted
 *   bad     down already                down, failures + 1, no new alert
 */
final class Transition
{
    public function __construct(
        public readonly string $status,
        public readonly int $failures,
        public readonly bool $opensIncident,
        public readonly bool $resolvesIncident,
    ) {}

    public static function next(int $failures, bool $incidentOpen, bool $ok, int $threshold): self
    {
        if ($ok) {
            return new self(UptimeCheck::UP, 0, false, $incidentOpen);
        }

        $failures = max(0, $failures) + 1;

        if ($failures >= $threshold) {
            return new self(UptimeCheck::DOWN, $failures, ! $incidentOpen, false);
        }

        return new self($incidentOpen ? UptimeCheck::DOWN : UptimeCheck::FAILING, $failures, false, false);
    }
}
