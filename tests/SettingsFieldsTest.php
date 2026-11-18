<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Fields\Field;
use Atrium\Monitoring\MonitoringSettings;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingsFieldsTest extends TestCase
{
    private function failuresDefault(): mixed
    {
        return $this->fields()['failures']->getDefault();
    }

    /** @return array<string,Field> */
    private function fields(): array
    {
        $fields = [];

        foreach ((new MonitoringSettings)->fields() as $field) {
            $fields[$field->getName()] = $field;
        }

        return $fields;
    }

    public function test_the_four_fields_come_in_order(): void
    {
        $this->assertSame(['failures', 'alert_emails', 'telegram_token', 'telegram_chat_id'], array_keys($this->fields()));
    }

    public function test_failures_start_at_what_the_config_says(): void
    {
        config(['atrium-monitoring.uptime.failures' => 7]);

        $this->assertSame(7, $this->failuresDefault());
    }

    public function test_failures_start_at_three_when_the_config_is_silent(): void
    {
        $uptime = config('atrium-monitoring.uptime');
        unset($uptime['failures']);
        config(['atrium-monitoring.uptime' => $uptime]);

        $this->assertSame(3, $this->failuresDefault());
    }

    public function test_the_shipped_config_asks_for_three_failures(): void
    {
        $this->assertSame(3, $this->failuresDefault());
    }

    public function test_failures_in_the_config_are_read_as_a_number(): void
    {
        config(['atrium-monitoring.uptime.failures' => '5']);

        $this->assertSame(5, $this->failuresDefault());
    }

    /** @return array<string,array{0:string}> */
    public static function optional(): array
    {
        return [
            'failures' => ['failures'],
            'alert_emails' => ['alert_emails'],
            'telegram_token' => ['telegram_token'],
            'telegram_chat_id' => ['telegram_chat_id'],
        ];
    }

    #[DataProvider('optional')]
    public function test_every_field_may_be_left_empty(string $field): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/modules/monitoring/settings', [$field => null])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/modules/monitoring/settings', [$field => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame($field === 'failures' ? 3 : null, Setting::get('monitoring.'.$field));
    }

    /** @return array<string,array{0:string,1:mixed}> */
    public static function notText(): array
    {
        return [
            'emails as a list' => ['alert_emails', ['ops@example.com']],
            'emails as a number' => ['alert_emails', 5],
            'chat as a number' => ['telegram_chat_id', 123456789],
            'token as a list' => ['telegram_token', ['123456:'.str_repeat('a', 20)]],
            'token as a number' => ['telegram_token', 123456],
        ];
    }

    #[DataProvider('notText')]
    public function test_the_text_fields_refuse_what_is_not_text(string $field, mixed $value): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put('/admin/modules/monitoring/settings', [$field => $value])
            ->assertSessionHasErrors($field);

        $this->assertNull(Setting::get('monitoring.'.$field));
    }
}
