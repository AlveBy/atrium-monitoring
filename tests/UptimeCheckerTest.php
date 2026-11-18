<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use ArrayObject;
use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\Sites;
use Atrium\Monitoring\Models\Incident;
use Atrium\Monitoring\Models\UptimeCheck;
use Atrium\Monitoring\Uptime\UptimeChecker;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The checker's own arithmetic: addresses, batches, timeouts, timings,
 * limits. The outage story as a whole is in UptimeTest.
 */
final class UptimeCheckerTest extends TestCase
{
    private function check(array $attributes = []): UptimeCheck
    {
        $check = new UptimeCheck;
        $check->forceFill($attributes + ['site_id' => null, 'name' => 'Demo shop', 'url' => 'https://shop.example.test/', 'active' => true])->save();

        return $check;
    }

    /** A setting of the uptime checks; null removes it, as if the config never had it. */
    private function uptimeSetting(string $key, mixed $value): void
    {
        $uptime = (array) config('atrium-monitoring.uptime');

        if ($value === null) {
            unset($uptime[$key]);
        } else {
            $uptime[$key] = $value;
        }

        config(['atrium-monitoring.uptime' => $uptime]);
    }

    /** @return ArrayObject<int,int> how many addresses each pool opened, filled as they run */
    private function watchPools(): ArrayObject
    {
        $sizes = new ArrayObject;
        $real = Http::getFacadeRoot();
        Http::swap(new class($real, $sizes)
        {
            /** @param ArrayObject<int,int> $sizes */
            public function __construct(private readonly object $real, private readonly ArrayObject $sizes) {}

            /** @param array<int,mixed> $arguments */
            public function __call(string $method, array $arguments): mixed
            {
                if ($method !== 'pool') {
                    return $this->real->$method(...$arguments);
                }

                return $this->real->pool(function (Pool $pool) use ($arguments): array {
                    $requests = $arguments[0]($pool);
                    $this->sizes->append(count($requests));

                    return $requests;
                });
            }
        });

        return $sizes;
    }

    /**
     * Laravel's fake reports a transfer without timings right after the stub
     * answers; the stats of a real transfer arrive when the promise is chained
     * on, so the stub's promise reports them then.
     */
    private function answerWithStats(PsrResponse $response, Request $request, callable $onStats, mixed $total): FulfilledPromise
    {
        return new class($response, $request, $onStats, $total) extends FulfilledPromise
        {
            private bool $reported = false;

            public function __construct(private readonly PsrResponse $response, private readonly Request $request, private $onStats, private readonly mixed $total)
            {
                parent::__construct($response);
            }

            public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
            {
                if (! $this->reported && $this->total !== null) {
                    $this->reported = true;
                    ($this->onStats)(new TransferStats($this->request->toPsrRequest(), $this->response, 0.1, null, ['total_time' => $this->total]));
                }

                return parent::then($onFulfilled, $onRejected);
            }
        };
    }

    // ---- home addresses -------------------------------------------------

    /** @return array<string,array{0:string,1:array<int,string>,2:?string,3:?string,4:string}> */
    public static function homes(): array
    {
        return [
            // app.url, domains, folder, scheme setting => address
            'the application itself' => ['http://localhost', [], null, null, 'http://localhost/'],
            'a trailing slash is not doubled' => ['http://localhost/', [], null, null, 'http://localhost/'],
            'a folder, slashes and all' => ['http://localhost/', [], '/blog/', null, 'http://localhost/blog/'],
            'a folder without slashes' => ['http://localhost', [], 'blog', null, 'http://localhost/blog/'],
            'another domain is https' => ['http://localhost', ['shop.example.test'], null, null, 'https://shop.example.test/'],
            'another domain follows the setting' => ['http://localhost', ['shop.example.test'], null, 'http', 'http://shop.example.test/'],
            'the first domain counts' => ['http://localhost', ['shop.example.test', 'www.shop.example.test'], null, null, 'https://shop.example.test/'],
            'the application domain keeps its scheme' => ['http://localhost', ['localhost'], null, 'https', 'http://localhost/'],
            'a domain and a folder' => ['https://app.example.test', ['shop.example.test'], '/de/', null, 'https://shop.example.test/de/'],
        ];
    }

    /** @param array<int,string> $domains */
    #[DataProvider('homes')]
    public function test_the_address_of_a_front_page(string $app, array $domains, ?string $folder, ?string $scheme, string $expected): void
    {
        config(['app.url' => $app]);
        $this->uptimeSetting('scheme', $scheme);
        $site = (new Site)->forceFill(['code' => 'x', 'name' => 'X', 'domains' => $domains, 'folder' => $folder]);

        $this->assertSame($expected, app(UptimeChecker::class)->homeUrl($site));
    }

    public function test_a_site_added_since_the_last_look_gets_its_check(): void
    {
        $checker = app(UptimeChecker::class);
        $checker->syncHomes();
        $this->assertSame(['http://localhost/'], UptimeCheck::query()->withoutGlobalScopes()->pluck('url')->all());

        // Written without the model's events, which would flush the list of sites themselves.
        Site::query()->insert(['code' => 'shop', 'name' => 'Demo shop', 'domains' => json_encode(['shop.example.test']), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertCount(1, app(Sites::class)->all(), 'the list of sites is stale until the checker flushes it');
        // No flush here: the checker takes a fresh list of sites itself.
        $checker->syncHomes();

        $this->assertSame(['http://localhost/', 'https://shop.example.test/'], UptimeCheck::query()->withoutGlobalScopes()->orderBy('id')->pluck('url')->all());
    }

    // ---- batches and timeouts -------------------------------------------

    /** @return array<string,array{0:mixed,1:int,2:array<int,int>}> */
    public static function batches(): array
    {
        return [
            // concurrency setting, addresses => pools
            'ten by default' => [null, 11, [10, 1]],
            'as set' => [3, 7, [3, 3, 1]],
            'one' => [1, 2, [1, 1]],
            'two' => [2, 3, [2, 1]],
            'zero is one' => [0, 2, [1, 1]],
            'negative is one' => [-5, 2, [1, 1]],
            'text' => ['4', 5, [4, 1]],
            'fewer than a batch' => [10, 3, [3]],
        ];
    }

    #[DataProvider('batches')]
    public function test_addresses_are_opened_in_batches(mixed $concurrency, int $addresses, array $expected): void
    {
        $this->uptimeSetting('concurrency', $concurrency);
        for ($i = 1; $i <= $addresses; $i++) {
            $this->check(['name' => 'Site '.$i, 'url' => 'https://s'.$i.'.example.test/']);
        }
        Http::fake(['*' => Http::response('ok')]);
        $sizes = $this->watchPools();

        $this->assertSame($addresses, app(UptimeChecker::class)->run());

        $this->assertSame($expected, $sizes->getArrayCopy());
    }

    public function test_nothing_to_check_opens_nothing(): void
    {
        Http::fake();
        $sizes = $this->watchPools();

        $this->assertSame(0, app(UptimeChecker::class)->run());
        $this->assertSame([], $sizes->getArrayCopy());
    }

    /** @return array<string,array{0:mixed,1:int}> */
    public static function timeouts(): array
    {
        return [
            'ten by default' => [null, 10],
            'as set' => [25, 25],
            'one second' => [1, 1],
            'zero is one' => [0, 1],
            'negative is one' => [-3, 1],
            'text' => ['7', 7],
        ];
    }

    #[DataProvider('timeouts')]
    public function test_every_address_gets_the_same_timeout_for_connecting_and_waiting(mixed $setting, int $seconds): void
    {
        $this->uptimeSetting('timeout', $setting);
        $this->check();
        $options = [];
        Http::fake(function (Request $request, array $given) use (&$options) {
            $options[] = [$given['timeout'] ?? null, $given['connect_timeout'] ?? null];

            return Http::response('ok');
        });

        app(UptimeChecker::class)->run();

        $this->assertSame([[$seconds, $seconds]], $options);
    }

    public function test_the_address_and_the_user_agent_are_sent(): void
    {
        $this->check(['url' => 'https://shop.example.test/health']);
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $sent[] = [$request->method(), $request->url(), $request->header('User-Agent')];

            return Http::response('ok');
        });

        app(UptimeChecker::class)->run();

        $this->assertSame([['GET', 'https://shop.example.test/health', ['AtriumMonitoring/1.0 (+uptime)']]], $sent);
    }

    // ---- what the answer says ---------------------------------------------

    /** @return array<string,array{0:mixed,1:?float}> */
    public static function timings(): array
    {
        return [
            'a tenth of a second, to a tenth of a millisecond' => [0.12345, 123.5],
            'a whole number' => [2, 2000.0],
            'text' => ['0.25', 250.0],
            'garbage' => ['n/a', 0.0],
            'nothing measured' => [null, null],
        ];
    }

    #[DataProvider('timings')]
    public function test_the_response_time_is_what_the_transfer_took(mixed $total, ?float $ms): void
    {
        $check = $this->check();
        Http::fake(function (Request $request, array $options) use ($total) {
            return $this->answerWithStats(new PsrResponse(200, [], 'ok'), $request, $options['on_stats'], $total);
        });

        app(UptimeChecker::class)->run();

        $stored = DB::table('monitoring_check_results')->where('check_id', $check->id)->value('response_ms');
        $this->assertSame($ms, $check->fresh()->last_response_ms);
        $this->assertSame($ms, $stored === null ? null : (float) $stored);
    }

    /** @return array<string,array{0:int,1:bool}> */
    public static function codes(): array
    {
        return [
            '200' => [200, true],
            '301' => [301, true],
            '399' => [399, true],
            '400' => [400, false],
            '404' => [404, false],
            '503' => [503, false],
        ];
    }

    #[DataProvider('codes')]
    public function test_an_answer_below_400_is_good(int $code, bool $good): void
    {
        $check = $this->check();
        Http::fake(['*' => Http::response('', $code)]);

        app(UptimeChecker::class)->run();

        $fresh = $check->fresh();
        $this->assertSame($good ? UptimeCheck::UP : UptimeCheck::FAILING, $fresh->status);
        $this->assertSame($code, $fresh->last_status_code);
        $this->assertSame($good ? null : 'HTTP '.$code, $fresh->last_error);
        $this->assertSame($good, (bool) DB::table('monitoring_check_results')->where('check_id', $check->id)->value('ok'));
    }

    // ---- recording --------------------------------------------------------

    /** @return array<string,array{0:int}> */
    public static function lengths(): array
    {
        return ['short' => [10], 'just under' => [249], 'exactly the limit' => [250], 'one over' => [251], 'far over' => [600]];
    }

    #[DataProvider('lengths')]
    public function test_errors_are_kept_to_250_characters_in_the_check_and_in_the_result(int $length): void
    {
        $error = str_repeat('é', $length);
        $check = $this->check();

        app(UptimeChecker::class)->record($check, false, 500, 5.0, $error);

        $expected = Str::limit($error, 250);
        $this->assertSame($expected, $check->fresh()->last_error);
        $this->assertSame($expected, DB::table('monitoring_check_results')->where('check_id', $check->id)->value('error'));
        $this->assertSame($length > 250 ? 253 : $length, mb_strlen($expected));
    }

    public function test_a_good_result_keeps_no_error(): void
    {
        $check = $this->check();
        $checker = app(UptimeChecker::class);
        $checker->record($check, false, 500, 5.0, 'HTTP 500');

        $checker->record($check, true, 200, 5.0, null);

        $this->assertNull($check->fresh()->last_error);
        $this->assertSame([null, 'HTTP 500'], DB::table('monitoring_check_results')->where('check_id', $check->id)->orderByDesc('id')->pluck('error')->all());
    }

    public function test_an_open_incident_follows_the_failures_and_the_last_error(): void
    {
        Setting::save('monitoring', ['failures' => 1]);
        $check = $this->check();
        $checker = app(UptimeChecker::class);

        $checker->record($check, false, 500, 5.0, 'HTTP 500', Carbon::parse('2026-11-16 10:00:00'));
        $incident = Incident::query()->sole();
        $this->assertSame([1, 'HTTP 500'], [$incident->failures, $incident->last_error]);

        $checker->record($check, false, 502, 5.0, 'HTTP 502', Carbon::parse('2026-11-16 10:01:00'));
        $checker->record($check, false, null, null, 'cURL 28: Operation timed out', Carbon::parse('2026-11-16 10:02:00'));

        $incident = Incident::query()->sole();
        $this->assertSame([3, 'cURL 28: Operation timed out'], [$incident->failures, $incident->last_error]);
        $this->assertNull($incident->resolved_at);
    }

    public function test_the_alert_knows_the_address_even_when_the_current_site_is_another(): void
    {
        config(['mail.default' => 'array']);
        Setting::save('monitoring', ['failures' => 1, 'alert_emails' => 'ops@example.test']);
        $shop = Site::query()->create(['code' => 'shop', 'name' => 'Demo shop', 'domains' => ['shop.example.test'], 'active' => true]);
        $blog = Site::query()->create(['code' => 'blog', 'name' => 'Demo blog', 'domains' => ['blog.example.test'], 'active' => true]);
        $check = $this->check(['site_id' => $shop->id, 'name' => 'Shop home']);
        app(Sites::class)->setCurrent($blog);

        app(UptimeChecker::class)->record($check, false, 500, 5.0, 'HTTP 500');

        $mails = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $mails);
        $this->assertSame('Down: Shop home', $mails[0]->getOriginalMessage()->getSubject());
    }

    // ---- the number of failures ------------------------------------------

    /** @return array<string,array{0:mixed,1:mixed,2:int}> */
    public static function thresholds(): array
    {
        return [
            // setting, config => failures
            'the setting' => [5, 9, 5],
            'text setting' => ['4', 9, 4],
            'the config without a setting' => [null, 6, 6],
            'a text that is no number' => ['often', 6, 6],
            'three when nothing is said' => [null, null, 3],
            'three when the setting is no number and the config is silent' => ['often', null, 3],
            'at least one: setting' => [0, 6, 1],
            'at least one: negative' => [-2, 6, 1],
            'at least one: config' => [null, 0, 1],
            'one' => [1, 6, 1],
        ];
    }

    #[DataProvider('thresholds')]
    public function test_failures_in_a_row_before_an_incident(mixed $setting, mixed $config, int $expected): void
    {
        $this->uptimeSetting('failures', $config);
        if ($setting !== null) {
            Setting::save('monitoring', ['failures' => $setting]);
        }

        $this->assertSame($expected, app(UptimeChecker::class)->threshold());
    }
}
