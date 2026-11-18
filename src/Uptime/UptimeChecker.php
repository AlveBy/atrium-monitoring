<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Uptime;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\Sites;
use Atrium\Monitoring\Models\Incident;
use Atrium\Monitoring\Models\UptimeCheck;
use Atrium\Monitoring\Stats\Aggregator;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Opens every active address once, several at a time, and moves each one
 * along the Transition table: incidents are opened and closed here, and the
 * alerts sent.
 */
class UptimeChecker
{
    public function __construct(private readonly Notifier $notifier, private readonly Sites $sites) {}

    /**
     * A check for the front page of every site, following its domain and
     * folder; a switched-off site's check is switched off too.
     */
    public function syncHomes(): void
    {
        $this->sites->flush();

        foreach ($this->sites->all() as $site) {
            $check = UptimeCheck::query()->withoutGlobalScopes()
                ->where('site_id', $site->getKey())
                ->where('is_home', true)
                ->first() ?? new UptimeCheck;

            $check->forceFill([
                'site_id' => $site->getKey(),
                'is_home' => true,
                'name' => $site->name,
                'url' => $this->homeUrl($site),
                'active' => $site->active,
            ]);

            if ($check->isDirty()) {
                $check->save();
            }
        }
    }

    public function homeUrl(Site $site): string
    {
        $domain = $site->domains[0] ?? null;
        $app = rtrim((string) config('app.url', 'http://localhost'), '/');
        // The application's own host keeps its scheme: http://localhost stays http.
        $scheme = $domain !== null && parse_url($app, PHP_URL_HOST) === $domain
            ? parse_url($app, PHP_URL_SCHEME)
            : config('atrium-monitoring.uptime.scheme', 'https');
        $root = $domain !== null ? $scheme.'://'.$domain : $app;
        $folder = trim((string) $site->folder, '/');

        return $folder === '' ? $root.'/' : $root.'/'.$folder.'/';
    }

    /**
     * @return int the addresses checked
     */
    public function run(): int
    {
        $checks = UptimeCheck::query()->withoutGlobalScopes()->where('active', true)->orderBy('id')->get();
        $concurrency = max(1, (int) config('atrium-monitoring.uptime.concurrency', 10));
        $timeout = max(1, (int) config('atrium-monitoring.uptime.timeout', 10));

        foreach ($checks->chunk($concurrency) as $chunk) {
            $results = Http::pool(fn (Pool $pool): array => $chunk->map(
                fn (UptimeCheck $check) => $pool->as((string) $check->id)
                    ->timeout($timeout)
                    ->connectTimeout($timeout)
                    ->withHeaders(['User-Agent' => 'AtriumMonitoring/1.0 (+uptime)'])
                    ->get($check->url)
            )->all());

            foreach ($chunk as $check) {
                $result = $results[$check->id] ?? null;

                if ($result instanceof Response) {
                    $ms = isset($result->handlerStats()['total_time']) ? round((float) $result->handlerStats()['total_time'] * 1000, 1) : null;
                    $ok = $result->status() < 400;
                    $this->record($check, $ok, $result->status(), $ms, $ok ? null : 'HTTP '.$result->status());
                } else {
                    $this->record($check, false, null, null, self::error($result));
                }
            }
        }

        return $checks->count();
    }

    /**
     * Applies one result: the check's state, the raw result, the daily
     * uptime, the incident and its alert.
     */
    public function record(UptimeCheck $check, bool $ok, ?int $status, ?float $ms, ?string $error, ?Carbon $at = null): Transition
    {
        $at ??= now();
        $incident = $check->openIncident();
        $transition = Transition::next((int) $check->failures, $incident !== null, $ok, $this->threshold());

        DB::transaction(function () use ($check, $ok, $status, $ms, $error, $at, $transition, &$incident): void {
            $check->forceFill([
                'status' => $transition->status,
                'failures' => $transition->failures,
                'last_checked_at' => $at,
                'last_status_code' => $status,
                'last_response_ms' => $ms,
                'last_error' => $error === null ? null : Str::limit($error, 250),
            ])->save();

            DB::table('monitoring_check_results')->insert([
                'check_id' => $check->id,
                'checked_at' => $at,
                'ok' => $ok,
                'status_code' => $status,
                'response_ms' => $ms,
                'error' => $error === null ? null : Str::limit($error, 250),
            ]);

            $aggregator = new Aggregator;
            $aggregator->addUptime((int) $check->site_id, (int) $check->id, $ok, $ms, $at);
            $aggregator->save();

            if ($transition->opensIncident) {
                $incident = Incident::query()->create([
                    'check_id' => $check->id,
                    'started_at' => $at,
                    'failures' => $transition->failures,
                    'last_error' => $check->last_error,
                ]);
            } elseif ($incident !== null && $transition->resolvesIncident) {
                $incident->forceFill(['resolved_at' => $at])->save();
            } elseif ($incident !== null && ! $ok) {
                $incident->forceFill(['failures' => $transition->failures, 'last_error' => $check->last_error])->save();
            }
        });

        if ($incident !== null && ($transition->opensIncident || $transition->resolvesIncident)) {
            $incident->setRelation('check', $check);
            $transition->opensIncident ? $this->notifier->opened($incident) : $this->notifier->resolved($incident);
        }

        return $transition;
    }

    public function threshold(): int
    {
        $setting = Setting::get('monitoring.failures');

        return max(1, (int) (is_numeric($setting) ? $setting : config('atrium-monitoring.uptime.failures', 3)));
    }

    public static function error(mixed $result): string
    {
        if (! $result instanceof Throwable) {
            return 'No response';
        }

        // cURL error 28: Operation timed out after 10001 milliseconds…
        $message = $result->getMessage();

        return preg_match('/cURL error (\d+): ([^(]+)/', $message, $match) === 1
            ? 'cURL '.$match[1].': '.trim($match[2])
            : Str::limit(class_basename($result).': '.$message, 250);
    }
}
