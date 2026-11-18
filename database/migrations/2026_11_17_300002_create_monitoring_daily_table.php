<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per day, measure and group: the count, sum, extremes and a
 * log-scale histogram, from which the percentiles of a day, a week or a
 * month are read (histograms add up, percentiles do not). Kept 13 months.
 *
 * "*" in locale, subject or device is the total over that dimension; site 0
 * is a request no site was resolved for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('day');
            $table->string('source', 12);
            $table->string('metric', 12);
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('locale', 12)->default('*');
            $table->string('subject', 191)->default('*');
            $table->string('device', 8)->default('*');
            $table->unsignedBigInteger('count');
            $table->double('sum');
            $table->double('min');
            $table->double('max');
            $table->text('buckets');
            $table->unique(['day', 'source', 'metric', 'site_id', 'locale', 'subject', 'device'], 'monitoring_daily_key');
            $table->index(['source', 'metric', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_daily');
    }
};
