<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Models;

use Atrium\Admin\Columns\BooleanColumn;
use Atrium\Admin\Columns\DateColumn;
use Atrium\Admin\Columns\TextColumn;
use Atrium\Admin\Contracts\Administrable;
use Atrium\Admin\Fields\CheckboxField;
use Atrium\Admin\Fields\TextField;
use Atrium\Admin\Traits\BelongsToSite;
use Atrium\Admin\Traits\InteractsWithAdmin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An address opened every minute. The front page of each site is one by
 * itself (is_home), kept in step with the site's domain; others are added
 * in the admin.
 *
 * @property int $id
 * @property int|null $site_id
 * @property string $name
 * @property string $url
 * @property bool $is_home
 * @property bool $active
 * @property string $status unknown, up, failing (below the threshold) or down
 * @property int $failures failed checks in a row
 * @property Carbon|null $last_checked_at
 * @property int|null $last_status_code
 * @property float|null $last_response_ms
 * @property string|null $last_error
 */
class UptimeCheck extends Model implements Administrable
{
    use BelongsToSite, InteractsWithAdmin;

    public const UNKNOWN = 'unknown';

    public const UP = 'up';

    public const FAILING = 'failing';

    public const DOWN = 'down';

    protected $table = 'monitoring_checks';

    protected $fillable = ['site_id', 'name', 'url', 'active'];

    protected $attributes = ['status' => self::UNKNOWN, 'failures' => 0, 'active' => true, 'is_home' => false];

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'active' => 'boolean',
            'failures' => 'integer',
            'last_checked_at' => 'datetime',
            'last_status_code' => 'integer',
            'last_response_ms' => 'float',
        ];
    }

    /**
     * @return HasMany<Incident,$this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'check_id');
    }

    public function openIncident(): ?Incident
    {
        return $this->incidents()->whereNull('resolved_at')->latest('started_at')->first();
    }

    public function getStatusLabelAttribute(): string
    {
        return __('atrium-monitoring::monitoring.status.'.$this->status);
    }

    public static function getAdminLabel(): string
    {
        return __('atrium-monitoring::monitoring.modules.checks');
    }

    public static function getAdminCreateLabel(): string
    {
        return __('atrium-monitoring::monitoring.create.check');
    }

    public static function getAdminRoutePrefix(): string
    {
        return 'uptime_checks';
    }

    public static function getDefaultSortColumn(): string
    {
        return 'id';
    }

    public static function getDefaultSortDirection(): string
    {
        return 'asc';
    }

    /** A front page comes back at the next check; switch it off instead. */
    public function canDelete(): bool
    {
        return ! $this->is_home;
    }

    public static function getAdministrationFields(): array
    {
        return [
            CheckboxField::make('active')->label(__('atrium::fields.standard.active'))->default(true),
            TextField::make('name')->label(__('atrium-monitoring::monitoring.fields.name'))->required(),
            TextField::make('url')->label(__('atrium-monitoring::monitoring.fields.url'))
                ->required()->rules(['url:http,https', 'max:2048'])
                ->placeholder('https://example.com/contacts')
                ->hint(__('atrium-monitoring::monitoring.hints.url')),
            TextField::make('status_label')->label(__('atrium-monitoring::monitoring.fields.status'))->readonly()->hideOnCreate(),
            TextField::make('last_error')->label(__('atrium-monitoring::monitoring.fields.last_error'))->readonly()->hideOnCreate(),
        ];
    }

    public static function getAdministrationListColumns(): array
    {
        return [
            TextColumn::make('id')->label(__('atrium::fields.standard.id'))->sortable(),
            TextColumn::make('name')->label(__('atrium-monitoring::monitoring.fields.name'))->sortable()->searchable(),
            TextColumn::make('url')->label(__('atrium-monitoring::monitoring.fields.url'))->searchable(),
            TextColumn::make('status_label')->label(__('atrium-monitoring::monitoring.fields.status')),
            TextColumn::make('last_response_ms')->label(__('atrium-monitoring::monitoring.fields.response_ms'))->alignRight(),
            DateColumn::make('last_checked_at')->label(__('atrium-monitoring::monitoring.fields.checked_at'))->sortable(),
            BooleanColumn::make('active')->label(__('atrium::fields.standard.active')),
        ];
    }
}
