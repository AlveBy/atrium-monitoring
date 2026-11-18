<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

use Atrium\Monitoring\Admin\MonitoringController;
use Illuminate\Support\Facades\Route;

Route::get('monitoring', [MonitoringController::class, 'index'])->name('admin.monitoring.index');
Route::get('monitoring/queries/{hash}', [MonitoringController::class, 'query'])->name('admin.monitoring.query');
