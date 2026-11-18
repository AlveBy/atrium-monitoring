<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring;

use Atrium\Admin\Fields\NumberField;
use Atrium\Admin\Fields\TextareaField;
use Atrium\Admin\Fields\TextField;
use Atrium\Admin\Modules\ModuleSettings;

/**
 * Who hears about an incident and after how many failures. One set for all
 * sites: an incident is the server's, whichever site noticed it.
 */
final class MonitoringSettings extends ModuleSettings
{
    public function description(): string
    {
        return __('atrium-monitoring::monitoring.settings.description');
    }

    public function fields(): array
    {
        return [
            NumberField::make('failures')->label(__('atrium-monitoring::monitoring.settings.failures'))
                ->hint(__('atrium-monitoring::monitoring.settings.failures_hint'))
                ->default((int) config('atrium-monitoring.uptime.failures', 3))
                ->rules(['integer', 'min:1', 'max:60']),
            TextareaField::make('alert_emails')->label(__('atrium-monitoring::monitoring.settings.alert_emails'))
                ->hint(__('atrium-monitoring::monitoring.settings.alert_emails_hint'))
                ->placeholder('ops@example.com')
                ->rules(['max:2000']),
            TextField::make('telegram_token')->label(__('atrium-monitoring::monitoring.settings.telegram_token'))
                ->hint(__('atrium-monitoring::monitoring.settings.telegram_token_hint'))
                ->rules(['regex:/^\d+:[A-Za-z0-9_-]{20,}$/']),
            TextField::make('telegram_chat_id')->label(__('atrium-monitoring::monitoring.settings.telegram_chat_id'))
                ->hint(__('atrium-monitoring::monitoring.settings.telegram_chat_id_hint'))
                ->rules(['regex:/^(-?\d+|@[A-Za-z0-9_]{5,})$/']),
        ];
    }
}
