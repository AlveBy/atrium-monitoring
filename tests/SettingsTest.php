<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Facades\Setting;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingsTest extends TestCase
{
    public function test_the_settings_screen_explains_itself(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/modules/monitoring/settings')
            ->assertOk()
            ->assertSee('Telegram')
            ->assertSee(json_encode(__('atrium-monitoring::monitoring.settings.description'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
    }

    /** @return array<string,array{0:string,1:mixed,2:bool}> */
    public static function values(): array
    {
        return [
            'failures: one' => ['failures', 1, true],
            'failures: an hour of minutes' => ['failures', 60, true],
            'failures: empty means the default' => ['failures', '', true],
            'failures: zero' => ['failures', 0, false],
            'failures: too many' => ['failures', 61, false],
            'failures: not a number' => ['failures', 'three', false],
            'failures: a fraction' => ['failures', 2.5, false],
            'emails: two' => ['alert_emails', "ops@example.com\nlead@example.org", true],
            'emails: too long' => ['alert_emails', str_repeat('a', 2001), false],
            'token: a bot token' => ['telegram_token', '123456:'.str_repeat('Ab_-', 6), true],
            'token: no number' => ['telegram_token', 'bot:'.str_repeat('a', 24), false],
            'token: too short' => ['telegram_token', '123:abc', false],
            'token: with spaces' => ['telegram_token', '123456:'.str_repeat('a', 20).' x', false],
            'chat: a person' => ['telegram_chat_id', '123456789', true],
            'chat: a group' => ['telegram_chat_id', '-1001234567890', true],
            'chat: a channel' => ['telegram_chat_id', '@demo_alerts', true],
            'chat: a short name' => ['telegram_chat_id', '@abc', false],
            'chat: a link' => ['telegram_chat_id', 'https://t.me/demo', false],
        ];
    }

    #[DataProvider('values')]
    public function test_each_field_keeps_what_is_right_and_refuses_the_rest(string $field, mixed $value, bool $valid): void
    {
        // An unset number reads back as the configured default.
        $default = $field === 'failures' ? 3 : null;
        $response = $this->actingAs($this->admin(), 'admin')->put('/admin/modules/monitoring/settings', [$field => $value]);

        if ($valid) {
            $response->assertSessionHasNoErrors();
            $this->assertEquals($value === '' ? $default : $value, Setting::get('monitoring.'.$field));
        } else {
            $response->assertSessionHasErrors($field);
            $this->assertSame($default, Setting::get('monitoring.'.$field));
        }
    }

    public function test_only_a_superadmin_changes_them(): void
    {
        $this->actingAs($this->admin(false), 'admin')->get('/admin/modules/monitoring/settings')->assertForbidden();
        $this->actingAs($this->admin(false), 'admin')->put('/admin/modules/monitoring/settings', ['failures' => 5])->assertForbidden();
        $this->assertSame(3, Setting::get('monitoring.failures'));
    }
}
