<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('name');
            $table->string('url', 2048);
            // The front page of a site: made and kept up to date by itself.
            $table->boolean('is_home')->default(false);
            $table->boolean('active')->default(true);
            $table->string('status', 8)->default('unknown');
            $table->unsignedInteger('failures')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->double('last_response_ms')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('monitoring_check_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('check_id')->index();
            $table->timestamp('checked_at')->index();
            $table->boolean('ok');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->double('response_ms')->nullable();
            $table->string('error')->nullable();
        });

        Schema::create('monitoring_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('check_id')->index();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_incidents');
        Schema::dropIfExists('monitoring_check_results');
        Schema::dropIfExists('monitoring_checks');
    }
};
