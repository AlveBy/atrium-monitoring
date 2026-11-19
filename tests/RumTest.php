<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\Sites;
use Atrium\Monitoring\Buffer\Buffer;
use Atrium\Monitoring\Rum\Beacon;
use Atrium\Monitoring\Rum\RumScript;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class RumTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('atrium-monitoring:rum:'.hash_hmac('sha256', '127.0.0.1', (string) config('app.key')));
    }

    /** @param array<string,string> $headers */
    private function beacon(string $body, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_ORIGIN' => 'http://localhost'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/atrium/rum', [], [], [], $server, $body);
    }

    /** @return array<int,array<string,mixed>> */
    private function buffered(): array
    {
        $file = app(Buffer::class)->path();

        return is_file($file) ? array_map(static fn (string $line): array => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES)) : [];
    }

    public function test_a_good_beacon_is_buffered_without_cookies_or_the_address(): void
    {
        $response = $this->beacon('{"p":"/de/kontakt?utm_source=x#form","d":"mobile","c":"4g","v":{"lcp":2345.67,"cls":0.123456,"inp":88,"ttfb":410.2,"fcp":1200}}');

        $response->assertNoContent();
        $this->assertSame([], $response->headers->getCookies());
        $records = $this->buffered();
        $this->assertCount(1, $records);
        $this->assertSame('v', $records[0]['k']);
        $this->assertSame('/de/kontakt', $records[0]['path']);
        $this->assertSame('mobile', $records[0]['dev']);
        $this->assertSame('4g', $records[0]['con']);
        $this->assertSame(2345.7, $records[0]['lcp']);
        $this->assertSame(0.1235, $records[0]['cls']);
        $this->assertSame(88, (int) $records[0]['inp']);
        $this->assertStringNotContainsString('127.0.0.1', (string) file_get_contents(app(Buffer::class)->path()));
        $this->assertStringNotContainsString('utm_source', (string) file_get_contents(app(Buffer::class)->path()));
    }

    /** @return array<string,array{0:string}> */
    public static function garbage(): array
    {
        return [
            'empty' => [''],
            'not json' => ['lcp=1200'],
            'a list' => ['[1,2,3]'],
            'a string' => ['"hello"'],
            'unknown key' => ['{"p":"/","d":"mobile","v":{"lcp":1},"ip":"1.2.3.4"}'],
            'unknown metric' => ['{"p":"/","d":"mobile","v":{"fid":12}}'],
            'no metrics' => ['{"p":"/","d":"mobile","v":{}}'],
            'metrics as a list' => ['{"p":"/","d":"mobile","v":[1200]}'],
            'metric as text' => ['{"p":"/","d":"mobile","v":{"lcp":"1200"}}'],
            'negative' => ['{"p":"/","d":"mobile","v":{"lcp":-1}}'],
            'too large' => ['{"p":"/","d":"mobile","v":{"cls":51}}'],
            'unknown device' => ['{"p":"/","d":"fridge","v":{"lcp":1}}'],
            'unknown connection' => ['{"p":"/","d":"mobile","c":"5g","v":{"lcp":1}}'],
            'absolute address' => ['{"p":"https://evil.test/","d":"mobile","v":{"lcp":1}}'],
            'protocol-relative' => ['{"p":"//evil.test/","d":"mobile","v":{"lcp":1}}'],
            'control characters' => ['{"p":"/a\u0000b","d":"mobile","v":{"lcp":1}}'],
            'too deep' => ['{"p":"/","d":"mobile","v":{"lcp":{"a":{"b":1}}}}'],
            'path not text' => ['{"p":42,"d":"mobile","v":{"lcp":1}}'],
        ];
    }

    #[DataProvider('garbage')]
    public function test_garbage_is_refused(string $body): void
    {
        $this->beacon($body)->assertStatus(422);
        $this->assertSame([], $this->buffered());
    }

    public function test_beacon_parsing_keeps_the_page_address_short(): void
    {
        $beacon = Beacon::parse(json_encode(['p' => '/'.str_repeat('é', 400), 'd' => 'desktop', 'v' => ['ttfb' => 0]]));
        $this->assertSame(255, mb_strlen($beacon['path']));
        $this->assertSame('unknown', $beacon['connection']);
        $this->assertNull(Beacon::parse(json_encode(['p' => '/'.str_repeat('a', 2048), 'd' => 'desktop', 'v' => ['ttfb' => 1]])));
        $this->assertNull(Beacon::parse("{\"p\":\"/\xff\",\"d\":\"desktop\",\"v\":{\"ttfb\":1}}"));
    }

    public function test_an_oversized_body_is_refused(): void
    {
        $body = json_encode(['p' => '/'.str_repeat('a', 2100), 'd' => 'mobile', 'v' => ['lcp' => 1]]);

        $this->beacon($body)->assertStatus(413);
        // A lying Content-Length is caught by the header check first.
        $this->beacon('{"p":"/","d":"mobile","v":{"lcp":1}}', ['Content-Length' => '99999'])->assertStatus(413);
        $this->assertSame([], $this->buffered());
    }

    public function test_beacons_are_rate_limited_per_address(): void
    {
        config(['atrium-monitoring.rum.rate_limit' => 3]);
        $body = '{"p":"/","d":"desktop","v":{"lcp":900}}';

        for ($i = 0; $i < 3; $i++) {
            $this->beacon($body)->assertNoContent();
        }

        $this->beacon($body)->assertStatus(429);
        $this->assertCount(3, $this->buffered());

        // Another visitor is not affected.
        $this->call('POST', '/atrium/rum', [], [], [], ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_ORIGIN' => 'http://localhost'], $body)->assertNoContent();
    }

    public function test_do_not_track_and_global_privacy_control_are_respected(): void
    {
        $body = '{"p":"/","d":"desktop","v":{"lcp":900}}';

        $this->beacon($body, ['DNT' => '1'])->assertNoContent();
        $this->beacon($body, ['Sec-GPC' => '1'])->assertNoContent();
        $this->assertSame([], $this->buffered());
    }

    public function test_a_beacon_from_another_site_is_refused(): void
    {
        $body = '{"p":"/","d":"desktop","v":{"lcp":900}}';

        $this->beacon($body, ['Origin' => 'https://elsewhere.test'])->assertForbidden();
        $this->beacon($body, ['Origin' => 'null'])->assertNoContent();
        $this->call('POST', '/atrium/rum', [], [], [], [], $body)->assertNoContent();
        $this->assertCount(2, $this->buffered());
    }

    public function test_switched_off_means_not_found(): void
    {
        config(['atrium-monitoring.rum.enabled' => false]);

        $this->beacon('{"p":"/","d":"desktop","v":{"lcp":900}}')->assertNotFound();
    }

    public function test_a_failing_buffer_still_answers_no_content(): void
    {
        $this->app->instance(Buffer::class, new class('/nowhere', 1) extends Buffer
        {
            public function push(array $records): bool
            {
                throw new \RuntimeException('disk full');
            }
        });

        $this->beacon('{"p":"/","d":"desktop","v":{"lcp":900}}')->assertNoContent();
    }

    public function test_the_script_is_under_two_kilobytes_and_has_no_comments(): void
    {
        $source = app(RumScript::class)->source();

        $this->assertLessThan(2048, strlen($source));
        $this->assertStringNotContainsString('/*', $source);
        $this->assertStringContainsString('sendBeacon', $source);
        $this->assertStringNotContainsString('cookie', strtolower($source));
        $this->assertStringNotContainsString('localStorage', $source);
        $this->assertSame($source, app(RumScript::class)->source(), 'read once');
    }

    public function test_the_tag_carries_the_endpoint_rate_and_nonce(): void
    {
        config(['atrium-monitoring.rum.sample_rate' => 0.25]);
        Vite::useCspNonce('abc123');

        $tag = app(RumScript::class)->tag(Request::create('/'));

        $this->assertStringStartsWith('<script data-endpoint="/atrium/rum" data-rate="0.25" nonce="abc123">', $tag);
        $this->assertStringEndsWith('</script>', $tag);
        $this->assertLessThan(2048 + 120, strlen($tag));
    }

    public function test_no_tag_for_visitors_who_opted_out_or_when_switched_off(): void
    {
        $dnt = Request::create('/', 'GET', [], [], [], ['HTTP_DNT' => '1']);
        $gpc = Request::create('/', 'GET', [], [], [], ['HTTP_SEC_GPC' => '1']);

        $this->assertSame('', app(RumScript::class)->tag($dnt));
        $this->assertSame('', app(RumScript::class)->tag($gpc));
        $this->assertStringContainsString('data-rate="1"', app(RumScript::class)->tag(Request::create('/')));

        config(['atrium-monitoring.rum.sample_rate' => 0]);
        $this->assertSame('', app(RumScript::class)->tag(Request::create('/')));

        config(['atrium-monitoring.rum.sample_rate' => 1, 'atrium-monitoring.rum.enabled' => false]);
        $this->assertSame('', app(RumScript::class)->tag(Request::create('/')));
    }

    /**
     * The minified script, run in Node against a fake browser: what it sends
     * must be a beacon the endpoint accepts.
     *
     * @param  array<string,mixed>  $browser
     */
    private function runScript(array $browser): ?string
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed.');
        }

        $harness = <<<'JS'
            const b = JSON.parse(process.argv[1]); let sent = null; const obs = {}; const on = {};
            global.window = { PerformanceObserver: function (cb) { this.observe = (o) => { obs[o.type] = cb } }, innerWidth: b.width,
                matchMedia: () => ({ matches: b.coarse }), doNotTrack: null, addEventListener: (t, f) => { on[t] = f } };
            global.PerformanceObserver = window.PerformanceObserver;
            global.document = { currentScript: { dataset: { endpoint: '/atrium/rum', rate: b.rate } }, visibilityState: 'visible',
                addEventListener: (t, f) => { on[t] = f } };
            // Node 21+ has a read-only `navigator` of its own: a plain assignment is silently dropped.
            const fake = (name, value) => Object.defineProperty(global, name, { value, configurable: true, writable: true });
            fake('navigator', { sendBeacon: (u, body) => { sent = body; return true }, doNotTrack: b.dnt, connection: { effectiveType: b.connection } });
            fake('performance', { getEntriesByType: () => b.nav ? [{ responseStart: 380.44, activationStart: 0 }] : [] });
            global.location = { pathname: '/news/hello' };
            eval(process.argv[2]);
            const feed = (t, list) => obs[t] && obs[t]({ getEntries: () => list });
            feed('paint', [{ name: 'first-paint', startTime: 300 }, { name: 'first-contentful-paint', startTime: 812.36 }]);
            feed('largest-contentful-paint', [{ startTime: 1500 }, { startTime: 2210.55 }]);
            feed('layout-shift', [{ startTime: 100, value: 0.05 }, { startTime: 600, value: 0.04 }, { startTime: 900, value: 0.5, hadRecentInput: true }, { startTime: 4000, value: 0.02 }]);
            feed('event', (b.clicks || []).map((d, i) => ({ interactionId: i + 1, duration: d })));
            on.pagehide && on.pagehide(); on.pagehide && on.pagehide();
            process.stdout.write(sent === null ? 'null' : sent);
            JS;

        $process = new Process([$node, '-e', $harness, json_encode($browser), app(RumScript::class)->source()]);
        $process->mustRun();

        return $process->getOutput() === 'null' ? null : $process->getOutput();
    }

    public function test_the_script_sends_a_beacon_the_endpoint_accepts(): void
    {
        $body = $this->runScript(['width' => 390, 'coarse' => true, 'rate' => '1', 'dnt' => null, 'connection' => '3g', 'nav' => true, 'clicks' => [48, 120, 64]]);

        $this->assertNotNull($body);
        $beacon = Beacon::parse($body);
        ksort($beacon['vitals']);
        // CLS: the 0.05 + 0.04 burst; the shift after input and the late one do not add to it.
        $this->assertSame([
            'path' => '/news/hello',
            'device' => 'mobile',
            'connection' => '3g',
            'vitals' => ['cls' => 0.09, 'fcp' => 812.4, 'inp' => 120.0, 'lcp' => 2210.6, 'ttfb' => 380.4],
        ], $beacon);
        $this->beacon($body)->assertNoContent();

        $tablet = Beacon::parse((string) $this->runScript(['width' => 1024, 'coarse' => true, 'rate' => '1', 'dnt' => null, 'connection' => '5g', 'nav' => true]));
        $this->assertSame(['tablet', 'unknown'], [$tablet['device'], $tablet['connection']]);
        $this->assertArrayNotHasKey('inp', $tablet['vitals'], 'no interaction, no INP');
        $this->assertSame('desktop', Beacon::parse((string) $this->runScript(['width' => 1440, 'coarse' => false, 'rate' => '1', 'dnt' => null, 'connection' => '4g', 'nav' => false]))['device']);
    }

    public function test_the_script_sends_nothing_with_do_not_track_or_outside_the_sample(): void
    {
        $this->assertNull($this->runScript(['width' => 390, 'coarse' => true, 'rate' => '1', 'dnt' => '1', 'connection' => '4g', 'nav' => true]));
        $this->assertNull($this->runScript(['width' => 390, 'coarse' => true, 'rate' => '0', 'dnt' => null, 'connection' => '4g', 'nav' => true]));
    }

    public function test_a_folder_site_gets_its_visits_with_the_language_after_the_folder(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch', 'active' => true, 'is_default' => false]);
        $blog = Site::query()->create(['code' => 'blog', 'name' => 'Demo blog', 'domains' => ['localhost'], 'folder' => 'blog', 'active' => true]);
        app(Sites::class)->flush();
        app(ContentLanguages::class)->flush();

        $this->beacon('{"p":"/blog/de/post","d":"desktop","v":{"lcp":900}}')->assertNoContent();
        $this->beacon('{"p":"/blog/post","d":"desktop","v":{"lcp":900}}')->assertNoContent();
        $this->beacon('{"p":"/de/kontakt","d":"desktop","v":{"lcp":900}}')->assertNoContent();
        $this->beacon('{"p":"/blogger/de","d":"desktop","v":{"lcp":900}}')->assertNoContent();

        $records = $this->buffered();
        $this->assertSame([$blog->id, $blog->id, 1, 1], array_column($records, 'site'));
        $this->assertSame(['de', 'en', 'de', 'en'], array_column($records, 'loc'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** The config as if the key had never been published: only the code's own default is left. */
    private function forgetConfig(string ...$keys): void
    {
        $all = config('atrium-monitoring');

        foreach ($keys as $key) {
            Arr::forget($all, $key);
        }

        config(['atrium-monitoring' => $all]);
    }

    /** A valid beacon of exactly this many bytes. */
    private function beaconOf(int $bytes): string
    {
        $body = '{"p":"/'.str_repeat('a', $bytes - 39).'","d":"desktop","v":{"lcp":900}}';
        $this->assertSame($bytes, strlen($body));

        return $body;
    }

    public function test_a_value_on_its_limit_is_accepted_and_one_past_it_is_not(): void
    {
        $this->assertSame(['lcp' => 120000.0], Beacon::parse('{"p":"/","d":"desktop","v":{"lcp":120000}}')['vitals']);
        $this->assertNull(Beacon::parse('{"p":"/","d":"desktop","v":{"lcp":120000.1}}'));
        $this->assertSame(['cls' => 50.0], Beacon::parse('{"p":"/","d":"desktop","v":{"cls":50}}')['vitals']);
        $this->assertNull(Beacon::parse('{"p":"/","d":"desktop","v":{"cls":50.5}}'));
        $this->assertSame(['inp' => 0.0], Beacon::parse('{"p":"/","d":"desktop","v":{"inp":0}}')['vitals']);
    }

    public function test_an_address_of_2048_characters_is_kept_and_one_more_is_refused(): void
    {
        $this->assertSame(255, mb_strlen(Beacon::parse(json_encode(['p' => '/'.str_repeat('a', 2047), 'd' => 'desktop', 'v' => ['ttfb' => 1]]))['path']));
        $this->assertNull(Beacon::parse(json_encode(['p' => '/'.str_repeat('a', 2048), 'd' => 'desktop', 'v' => ['ttfb' => 1]])));
    }

    public function test_the_default_body_limit_is_2048_bytes(): void
    {
        $this->forgetConfig('rum.max_bytes');

        $this->beacon($this->beaconOf(2048))->assertNoContent();
        $this->assertCount(1, $this->buffered());
        $this->beacon($this->beaconOf(2049))->assertStatus(413);
        $this->assertCount(1, $this->buffered());
    }

    public function test_the_body_limit_is_inclusive(): void
    {
        $body = $this->beaconOf(39);

        config(['atrium-monitoring.rum.max_bytes' => 39]);
        $this->beacon($body)->assertNoContent();

        config(['atrium-monitoring.rum.max_bytes' => 38]);
        $this->beacon($body)->assertStatus(413);
    }

    public function test_a_limit_that_is_not_a_number_refuses_everything(): void
    {
        config(['atrium-monitoring.rum.max_bytes' => 'plenty']);

        $this->beacon($this->beaconOf(39))->assertStatus(413);
        $this->assertSame([], $this->buffered());
    }

    public function test_the_declared_length_is_checked_as_a_number_and_inclusively(): void
    {
        $body = $this->beaconOf(39);
        config(['atrium-monitoring.rum.max_bytes' => 100]);

        $this->beacon($body, ['Content-Length' => '100'])->assertNoContent();
        $this->beacon($body, ['Content-Length' => '101'])->assertStatus(413);
        $this->beacon($body, ['Content-Length' => 'lots'])->assertNoContent();
        $this->assertCount(2, $this->buffered());
    }

    public function test_thirty_beacons_a_minute_are_allowed_by_default(): void
    {
        $this->forgetConfig('rum.rate_limit');
        $body = $this->beaconOf(39);

        for ($i = 0; $i < 30; $i++) {
            $this->beacon($body)->assertNoContent();
        }

        $this->beacon($body)->assertStatus(429);
        $this->assertCount(30, $this->buffered());
    }

    public function test_a_rate_limit_below_one_still_lets_one_beacon_through(): void
    {
        $body = $this->beaconOf(39);

        foreach ([0, 1, -4] as $limit) {
            RateLimiter::clear('atrium-monitoring:rum:'.hash_hmac('sha256', '127.0.0.1', (string) config('app.key')));
            config(['atrium-monitoring.rum.rate_limit' => $limit]);

            $this->beacon($body)->assertNoContent();
            $this->beacon($body)->assertStatus(429);
        }
    }

    public function test_the_rate_limit_counts_a_minute_under_a_key_of_its_own(): void
    {
        config(['atrium-monitoring.rum.rate_limit' => 1]);
        Carbon::setTestNow('2026-11-17 12:00:00');
        $body = $this->beaconOf(39);

        $this->beacon($body)->assertNoContent();
        $this->assertSame(1, RateLimiter::attempts('atrium-monitoring:rum:'.hash_hmac('sha256', '127.0.0.1', (string) config('app.key'))));

        Carbon::setTestNow('2026-11-17 12:00:59');
        $this->beacon($body)->assertStatus(429);

        Carbon::setTestNow('2026-11-17 12:01:00');
        $this->beacon($body)->assertNoContent();
    }

    public function test_the_tag_defaults_to_every_visit_and_ignores_a_rate_that_is_not_a_number(): void
    {
        $this->forgetConfig('rum.sample_rate');
        $this->assertStringContainsString('data-rate="1"', app(RumScript::class)->tag(Request::create('/')));

        config(['atrium-monitoring.rum.sample_rate' => 'often']);
        $this->assertSame('', app(RumScript::class)->tag(Request::create('/')));

        config(['atrium-monitoring.rum.sample_rate' => 7]);
        $this->assertStringContainsString('data-rate="1"', app(RumScript::class)->tag(Request::create('/')));
    }

    public function test_the_script_is_read_once_and_minified_outside_its_strings(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rum');
        file_put_contents($file, "\n  /* header\n comment */\n  var url = 'http://example.test/a  b' ; // the address\n  var  sum = a + b ,  c = 'x  =  y' ;\n  if ( a ) { go( 'two   spaces', 1 ) }\n");

        try {
            $script = new RumScript($file);
            $expected = "var url='http://example.test/a  b';var sum=a+b,c='x  =  y';if(a){go('two   spaces',1)}";

            $this->assertSame($expected, $script->source());

            unlink($file);
            $this->assertSame($expected, $script->source(), 'read once');
        } finally {
            @unlink($file);
        }
    }
}
