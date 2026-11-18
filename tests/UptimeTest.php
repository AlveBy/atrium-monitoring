<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Facades\Hook;
use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\Sites;
use Atrium\Monitoring\Models\Incident;
use Atrium\Monitoring\Models\UptimeCheck;
use Atrium\Monitoring\Stats\Report;
use Atrium\Monitoring\Uptime\Notifier;
use Atrium\Monitoring\Uptime\Transition;
use Atrium\Monitoring\Uptime\UptimeChecker;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

final class UptimeTest extends TestCase
{
    /** @return array<string,array{0:int,1:bool,2:bool,3:int,4:string,5:int,6:bool,7:bool}> */
    public static function transitions(): array
    {
        // failures before, incident open, result ok, threshold => status, failures, opens, resolves
        return [
            'first good result' => [0, false, true, 3, UptimeCheck::UP, 0, false, false],
            'good after failing' => [2, false, true, 3, UptimeCheck::UP, 0, false, false],
            'good while down resolves' => [7, true, true, 3, UptimeCheck::UP, 0, false, true],
            'first failure' => [0, false, false, 3, UptimeCheck::FAILING, 1, false, false],
            'second failure' => [1, false, false, 3, UptimeCheck::FAILING, 2, false, false],
            'third failure opens' => [2, false, false, 3, UptimeCheck::DOWN, 3, true, false],
            'still down, no second alert' => [3, true, false, 3, UptimeCheck::DOWN, 4, false, false],
            'threshold one opens at once' => [0, false, false, 1, UptimeCheck::DOWN, 1, true, false],
            'threshold zero acts as one' => [0, false, false, 0, UptimeCheck::DOWN, 1, true, false],
            'threshold raised while down' => [1, true, false, 5, UptimeCheck::DOWN, 2, false, false],
            'negative failures counted from zero' => [-4, false, false, 3, UptimeCheck::FAILING, 1, false, false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_transition_table(int $failures, bool $open, bool $ok, int $threshold, string $status, int $after, bool $opens, bool $resolves): void
    {
        $transition = Transition::next($failures, $open, $ok, $threshold);

        $this->assertSame([$status, $after, $opens, $resolves], [$transition->status, $transition->failures, $transition->opensIncident, $transition->resolvesIncident]);
    }

    private function check(array $attributes = []): UptimeCheck
    {
        $check = new UptimeCheck;
        $check->forceFill($attributes + ['site_id' => null, 'name' => 'Demo shop', 'url' => 'https://shop.example.test/', 'active' => true])->save();

        return $check;
    }

    public function test_an_outage_opens_one_incident_alerts_once_and_resolves(): void
    {
        config(['mail.default' => 'array']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::save('monitoring', [
            'failures' => 2,
            'alert_emails' => "ops@example.test, not-an-email\nlead@example.test ops@example.test",
            'telegram_token' => '123456:'.str_repeat('A', 30),
            'telegram_chat_id' => '-1001234567890',
        ]);
        $fired = [];
        Hook::on('atrium.monitoring.incident.opened', function (Incident $incident) use (&$fired): void {
            $fired[] = 'opened:'.$incident->check_id;
        });
        Hook::on('atrium.monitoring.incident.resolved', function (Incident $incident) use (&$fired): void {
            $fired[] = 'resolved:'.$incident->check_id;
        });

        $check = $this->check();
        $checker = app(UptimeChecker::class);
        $start = Carbon::parse('2026-11-16 10:00:00');

        $checker->record($check, false, 502, 80.0, 'HTTP 502', $start);
        $this->assertSame(UptimeCheck::FAILING, $check->fresh()->status);
        $this->assertSame(0, Incident::query()->count());

        $checker->record($check, false, null, null, 'cURL 28: Operation timed out', $start->copy()->addMinute());
        $checker->record($check, false, null, null, 'cURL 28: Operation timed out', $start->copy()->addMinutes(2));
        $incident = Incident::query()->sole();
        $this->assertSame(UptimeCheck::DOWN, $check->fresh()->status);
        $this->assertSame(3, $incident->failures);
        $this->assertNull($incident->resolved_at);
        $this->assertSame('cURL 28: Operation timed out', $incident->last_error);

        $checker->record($check, true, 200, 95.0, null, $start->copy()->addMinutes(12));
        $incident->refresh();
        $this->assertSame('2026-11-16 10:12', $incident->resolved_at->format('Y-m-d H:i'));
        $this->assertSame(11, $incident->minutes());
        $this->assertSame(UptimeCheck::UP, $check->fresh()->status);
        $this->assertSame(0, $check->fresh()->failures);
        $this->assertNull($check->fresh()->last_error);

        $this->assertSame(['opened:'.$check->id, 'resolved:'.$check->id], $fired);
        $this->assertSame(4, DB::table('monitoring_check_results')->where('check_id', $check->id)->count());

        $mails = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(2, $mails);
        $first = $mails[0]->getOriginalMessage();
        $this->assertSame(['ops@example.test', 'lead@example.test'], array_map(fn ($address) => $address->getAddress(), $first->getTo()));
        $this->assertSame('Down: Demo shop', $first->getSubject());
        $this->assertSame('Back up: Demo shop', $mails[1]->getOriginalMessage()->getSubject());
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($check): bool {
            return str_contains($request->url(), '/bot123456:')
                && $request['chat_id'] === '-1001234567890'
                && str_contains($request['text'], 'Down: Demo shop')
                && str_contains($request['text'], $check->url);
        });
        Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'Back up: Demo shop') && str_contains($request['text'], '11 minutes'));

        $uptime = (new Report(Carbon::parse('2026-11-17')->toImmutable()))->uptime('2026-11-16', '2026-11-16');
        $this->assertSame(['checks' => 4, 'up' => 1, 'ratio' => 0.25], $uptime[$check->id]);
    }

    public function test_the_alert_text_is_translated_and_the_addresses_cleaned(): void
    {
        app()->setLocale('ru');
        $incident = new Incident(['started_at' => Carbon::parse('2026-11-16 10:00'), 'resolved_at' => Carbon::parse('2026-11-16 10:05'), 'failures' => 3, 'last_error' => null]);
        $incident->setRelation('check', $this->check(['name' => 'Магазин']));

        $message = app(Notifier::class)->message('opened', $incident);
        $this->assertSame('Не работает: Магазин', $message['subject']);
        $this->assertStringContainsString('Последняя ошибка: —', $message['body']);
        $this->assertStringContainsString('5 минут', app(Notifier::class)->message('resolved', $incident)['body']);

        Setting::save('monitoring', ['alert_emails' => 'a@example.test;b@example.test, a@example.test bad@@x']);
        $this->assertSame(['a@example.test', 'b@example.test'], app(Notifier::class)->emails());
    }

    public function test_telegram_settings_come_from_env_first_and_need_both_parts(): void
    {
        $notifier = app(Notifier::class);
        $this->assertNull($notifier->telegram());

        Setting::save('monitoring', ['telegram_token' => '1:'.str_repeat('b', 25)]);
        $this->assertNull($notifier->telegram(), 'no chat');

        Setting::save('monitoring', ['telegram_token' => '1:'.str_repeat('b', 25), 'telegram_chat_id' => '@site_alerts']);
        $this->assertSame(['1:'.str_repeat('b', 25), '@site_alerts'], $notifier->telegram());

        config(['atrium-monitoring.telegram.token' => '9:from-env-token-xxxxxxxxxxxx']);
        $this->assertSame(['9:from-env-token-xxxxxxxxxxxx', '@site_alerts'], $notifier->telegram());
    }

    public function test_a_failing_alert_is_logged_without_the_token(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 401)]);
        Setting::save('monitoring', ['failures' => 1, 'telegram_token' => '777:'.str_repeat('S', 30), 'telegram_chat_id' => '42']);
        Log::spy();

        app(UptimeChecker::class)->record($this->check(), false, 500, 10.0, 'HTTP 500');

        $this->assertSame(1, Incident::query()->count(), 'the incident is opened anyway');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'telegram') && ! str_contains($message, 'SSSS'));
    }

    public function test_run_checks_every_active_address_and_records_errors(): void
    {
        Setting::save('monitoring', ['failures' => 1]);
        $up = $this->check(['name' => 'Up', 'url' => 'https://up.example.test/']);
        $missing = $this->check(['name' => 'Missing', 'url' => 'https://up.example.test/gone']);
        $timeout = $this->check(['name' => 'Timeout', 'url' => 'https://slow.example.test/']);
        $off = $this->check(['name' => 'Off', 'url' => 'https://off.example.test/', 'active' => false]);

        Http::fake([
            'up.example.test/gone' => Http::response('', 404),
            'up.example.test/*' => Http::response('ok', 200),
            'localhost/*' => Http::response('home', 200),
            'slow.example.test/*' => fn (Request $request) => throw new ConnectException('cURL error 28: Operation timed out after 10001 milliseconds (see https://curl.haxx.se)', new PsrRequest('GET', $request->url())),
        ]);

        $this->artisan('atrium:monitoring:uptime')->assertSuccessful();

        $this->assertSame(UptimeCheck::UP, $up->fresh()->status);
        $this->assertSame(200, $up->fresh()->last_status_code);
        $this->assertSame(UptimeCheck::DOWN, $missing->fresh()->status);
        $this->assertSame('HTTP 404', $missing->fresh()->last_error);
        $this->assertSame('cURL 28: Operation timed out after 10001 milliseconds', $timeout->fresh()->last_error);
        $this->assertNull($timeout->fresh()->last_status_code);
        $this->assertSame(UptimeCheck::UNKNOWN, $off->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'off.example.test'));
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', 'AtriumMonitoring/1.0 (+uptime)'));
        $this->assertSame(2, Incident::query()->count());
        // The command added the front page of the site and checked it too.
        $this->assertSame(UptimeCheck::UP, UptimeCheck::query()->withoutGlobalScopes()->where('is_home', true)->value('status'));
    }

    public function test_front_pages_of_every_site_are_checked_by_themselves(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $checker = app(UptimeChecker::class);

        // The site the installation starts with, on the application's address.
        $checker->syncHomes();
        $checker->syncHomes();
        $this->assertSame(['http://localhost/'], UptimeCheck::query()->withoutGlobalScopes()->pluck('url')->all());

        $shop = Site::query()->create(['code' => 'shop', 'name' => 'Demo shop', 'domains' => ['shop.example.test', 'www.shop.example.test'], 'active' => true]);
        $blog = Site::query()->create(['code' => 'blog', 'name' => 'Demo blog', 'domains' => [], 'folder' => 'blog', 'active' => false]);
        app(Sites::class)->flush();

        $checker->syncHomes();
        $homes = UptimeCheck::query()->withoutGlobalScopes()->where('is_home', true)->orderBy('id')->get();
        $this->assertSame(['http://localhost/', 'https://shop.example.test/', 'http://localhost/blog/'], $homes->pluck('url')->all());
        $this->assertSame([true, true, false], $homes->pluck('active')->all(), 'a switched-off site is not checked');
        $this->assertFalse($homes[1]->canDelete());
        $this->assertTrue($this->check()->canDelete());

        $shop->update(['name' => 'Demo store']);
        $checker->syncHomes();
        $this->assertSame('Demo store', UptimeCheck::query()->withoutGlobalScopes()->where('site_id', $shop->id)->value('name'));
        $this->assertSame(3, UptimeCheck::query()->withoutGlobalScopes()->where('is_home', true)->count());
        $this->assertSame((int) $blog->id, (int) $homes[2]->site_id);
    }

    public function test_status_labels_are_translated(): void
    {
        $check = $this->check();
        $this->assertSame('Not checked yet', $check->status_label);
        app()->setLocale('de');
        $check->status = UptimeCheck::DOWN;
        $this->assertNotSame('Down', $check->status_label);
    }

    public function test_the_command_does_nothing_when_checks_are_off(): void
    {
        config(['atrium-monitoring.uptime.enabled' => false]);

        $this->artisan('atrium:monitoring:uptime')->expectsOutput('Uptime checks are off.')->assertSuccessful();
        $this->assertSame(0, UptimeCheck::query()->withoutGlobalScopes()->count(), 'not even the front pages');
        Http::assertNothingSent();
    }

    /** @return array<string,array{0:mixed,1:string}> */
    public static function errors(): array
    {
        return [
            'no result at all' => [null, 'No response'],
            'something odd' => ['oops', 'No response'],
            'a cURL timeout' => [new \RuntimeException('cURL error 28: Operation timed out after 10001 milliseconds (see https://curl.haxx.se)'), 'cURL 28: Operation timed out after 10001 milliseconds'],
            'another exception' => [new \LogicException('Broken pipe'), 'LogicException: Broken pipe'],
            'a long message' => [new \RuntimeException(str_repeat('x', 400)), 'RuntimeException: '.str_repeat('x', 232).'...'],
        ];
    }

    #[DataProvider('errors')]
    public function test_the_error_is_short_and_readable(mixed $result, string $expected): void
    {
        $this->assertSame($expected, UptimeChecker::error($result));
    }
}
