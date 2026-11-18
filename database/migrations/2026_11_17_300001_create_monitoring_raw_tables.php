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
 * Raw measurements, kept a week (atrium:monitoring:prune). No IPs, no
 * cookies, no query strings: nothing here points at a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_requests', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('created_at')->index();
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('locale', 12)->default('');
            $table->string('route', 191);
            $table->string('path', 255);
            $table->string('method', 8);
            $table->unsignedSmallInteger('status');
            $table->double('duration_ms');
            $table->unsignedInteger('db_queries');
            $table->double('db_ms');
            $table->unsignedInteger('cache_hits');
            $table->unsignedInteger('cache_misses');
            $table->unsignedInteger('memory_kb');
            $table->index(['route', 'created_at']);
        });

        Schema::create('monitoring_queries', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('created_at')->index();
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('route', 191);
            $table->string('path', 255);
            $table->char('sql_hash', 40)->index();
            $table->double('duration_ms');
        });

        // The SQL of a slow query by its hash, as long as its daily rows live.
        Schema::create('monitoring_query_texts', function (Blueprint $table): void {
            $table->char('hash', 40)->primary();
            $table->text('sql');
            $table->string('connection', 64)->default('');
            $table->timestamp('last_seen_at')->index();
        });

        Schema::create('monitoring_vitals', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('created_at')->index();
            $table->unsignedBigInteger('site_id')->default(0);
            $table->string('locale', 12)->default('');
            $table->string('path', 255);
            $table->string('device', 8);
            $table->string('connection', 8);
            $table->double('lcp')->nullable();
            $table->double('inp')->nullable();
            $table->double('cls')->nullable();
            $table->double('ttfb')->nullable();
            $table->double('fcp')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_vitals');
        Schema::dropIfExists('monitoring_query_texts');
        Schema::dropIfExists('monitoring_queries');
        Schema::dropIfExists('monitoring_requests');
    }
};
