<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Models;

use Atrium\Admin\Columns\DateColumn;
use Atrium\Admin\Columns\TextColumn;
use Atrium\Admin\Contracts\Administrable;
use Atrium\Admin\Traits\InteractsWithAdmin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stretch of time an address did not answer: opened after N failed checks
 * in a row, closed by the first good one. Written by the checks only.
 *
 * @property int $id
 * @property int $check_id
 * @property Carbon $started_at
 * @property Carbon|null $resolved_at
 * @property int $failures
 * @property string|null $last_error
 * @property-read UptimeCheck|null $check
 */
class Incident extends Model implements Administrable
{
    use InteractsWithAdmin;

    protected $table = 'monitoring_incidents';

    protected $fillable = ['check_id', 'started_at', 'resolved_at', 'failures', 'last_error'];

    protected function casts(): array
    {
        return [
            'check_id' => 'integer',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'failures' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<UptimeCheck,$this>
     */
    public function check(): BelongsTo
    {
        return $this->belongsTo(UptimeCheck::class, 'check_id');
    }

    /** How long it lasted, or has lasted so far, in whole minutes. */
    public function minutes(?Carbon $now = null): int
    {
        $end = $this->resolved_at ?? $now ?? now();

        return (int) floor($this->started_at->diffInSeconds($end, true) / 60);
    }

    public function getDurationAttribute(): string
    {
        return trans_choice('atrium-monitoring::monitoring.minutes', $this->minutes());
    }

    public function getCheckNameAttribute(): string
    {
        return (string) $this->check?->name;
    }

    public static function isCreatableInAdmin(): bool
    {
        return false;
    }

    public static function isEditableInAdmin(): bool
    {
        return false;
    }

    public static function getAdminLabel(): string
    {
        return __('atrium-monitoring::monitoring.modules.incidents');
    }

    /** Service records, not content: no custom fields, no link target. */
    public static function isContentModule(): bool
    {
        return false;
    }

    public static function getAdminRoutePrefix(): string
    {
        return 'monitoring_incidents';
    }

    public static function getDefaultSortColumn(): string
    {
        return 'started_at';
    }

    public static function getDefaultSortDirection(): string
    {
        return 'desc';
    }

    /** Read-only: everything worth reading is in the list. */
    public static function getAdministrationFields(): array
    {
        return [];
    }

    public static function getAdministrationListColumns(): array
    {
        return [
            TextColumn::make('id')->label(__('atrium::fields.standard.id'))->sortable(),
            TextColumn::make('check.name')->label(__('atrium-monitoring::monitoring.fields.check')),
            DateColumn::make('started_at')->label(__('atrium-monitoring::monitoring.fields.started_at'))->sortable(),
            DateColumn::make('resolved_at')->label(__('atrium-monitoring::monitoring.fields.resolved_at'))->sortable(),
            TextColumn::make('duration')->label(__('atrium-monitoring::monitoring.fields.duration')),
            TextColumn::make('last_error')->label(__('atrium-monitoring::monitoring.fields.last_error')),
        ];
    }
}
