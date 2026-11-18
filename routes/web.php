<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

use Atrium\Monitoring\Rum\BeaconController;
use Illuminate\Support\Facades\Route;

// No `web` group: a beacon brings no session and must get no cookie back.
Route::post('atrium/rum', BeaconController::class)->name('atrium-monitoring.rum');
