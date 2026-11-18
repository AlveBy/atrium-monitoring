<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Models\UptimeCheck;
use Illuminate\Support\Facades\Http;

final class ConsoleTest extends TestCase
{
    public function test_the_uptime_command_says_how_many_addresses_it_checked(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        foreach (['One' => true, 'Two' => true, 'Off' => false] as $name => $active) {
            (new UptimeCheck)->forceFill(['site_id' => 1, 'name' => $name, 'url' => 'https://'.strtolower($name).'.example.test/', 'active' => $active])->save();
        }

        // Two addresses of its own and the front page of the site.
        $this->artisan('atrium:monitoring:uptime')->expectsOutput('Checked: 3')->assertSuccessful();
    }

    public function test_the_uptime_command_does_nothing_when_the_checks_are_off(): void
    {
        config(['atrium-monitoring.uptime.enabled' => false]);

        $this->artisan('atrium:monitoring:uptime')->expectsOutput('Uptime checks are off.')->assertSuccessful();
        $this->assertSame(0, UptimeCheck::query()->withoutGlobalScopes()->count(), 'not even the front pages');
        Http::assertNothingSent();
    }

    public function test_the_flush_command_says_how_many_records_it_wrote(): void
    {
        $this->artisan('atrium:monitoring:flush')->expectsOutput('Written: 0')->assertSuccessful();
    }
}
