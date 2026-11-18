<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Facades\Setting;
use Atrium\Monitoring\Models\Incident;
use Atrium\Monitoring\Models\UptimeCheck;
use Atrium\Monitoring\Uptime\Notifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * What an alert says, who gets it and how it is sent, to the letter. E-mail
 * goes to the array mailer or a fake, Telegram to Http::fake; nothing leaves
 * the machine.
 */
final class NotifierTest extends TestCase
{
    private function incident(?string $error = 'HTTP 500', bool $withCheck = true): Incident
    {
        $incident = new Incident([
            'started_at' => Carbon::parse('2026-11-16 10:00:00'),
            'resolved_at' => Carbon::parse('2026-11-16 10:05:00'),
            'failures' => 3,
            'last_error' => $error,
        ]);

        if ($withCheck) {
            $incident->setRelation('check', (new UptimeCheck)->forceFill(['name' => 'Demo shop', 'url' => 'https://shop.example.test/']));
        }

        return $incident;
    }

    public function test_the_alert_says_what_went_down_since_when_and_why(): void
    {
        $notifier = app(Notifier::class);

        $this->assertSame([
            'subject' => 'Down: Demo shop',
            'body' => "https://shop.example.test/ has not answered 3 checks in a row since 2026-11-16 10:00.\nLast error: HTTP 500",
        ], $notifier->message('opened', $this->incident()));
        $this->assertSame([
            'subject' => 'Back up: Demo shop',
            'body' => 'https://shop.example.test/ answers again after 5 minutes.',
        ], $notifier->message('resolved', $this->incident()));
    }

    public function test_a_missing_or_empty_error_is_a_dash(): void
    {
        $notifier = app(Notifier::class);

        $this->assertStringEndsWith('Last error: —', $notifier->message('opened', $this->incident(null))['body']);
        $this->assertStringEndsWith('Last error: —', $notifier->message('opened', $this->incident(''))['body']);
    }

    public function test_an_incident_whose_address_is_gone_is_still_announced(): void
    {
        $message = app(Notifier::class)->message('opened', $this->incident('HTTP 500', false));

        $this->assertSame('Down: ', $message['subject']);
        $this->assertSame(" has not answered 3 checks in a row since 2026-11-16 10:00.\nLast error: HTTP 500", $message['body']);
    }

    public function test_addresses_are_split_cleaned_and_listed_once_without_gaps(): void
    {
        $notifier = app(Notifier::class);
        $this->assertSame([], $notifier->emails());

        Setting::save('monitoring', ['alert_emails' => "a@example.test, a@example.test\nb@example.test;bad@@x c@example.test,b@example.test"]);
        $this->assertSame(['a@example.test', 'b@example.test', 'c@example.test'], $notifier->emails());

        Setting::save('monitoring', ['alert_emails' => 12345]);
        $this->assertSame([], $notifier->emails(), 'a number is not an address');

        Setting::save('monitoring', ['alert_emails' => "  \n ,; "]);
        $this->assertSame([], $notifier->emails());
    }

    public function test_telegram_parts_are_text_whatever_they_were_stored_as(): void
    {
        config(['atrium-monitoring.telegram.token' => 123456, 'atrium-monitoring.telegram.chat_id' => 42]);

        $this->assertSame(['123456', '42'], app(Notifier::class)->telegram());

        config(['atrium-monitoring.telegram.token' => null, 'atrium-monitoring.telegram.chat_id' => null]);
        Setting::save('monitoring', ['telegram_token' => '777:'.str_repeat('S', 30), 'telegram_chat_id' => 99]);
        $this->assertSame(['777:'.str_repeat('S', 30), '99'], app(Notifier::class)->telegram());
    }

    public function test_the_telegram_message_is_one_form_post_to_the_bot(): void
    {
        $sent = [];
        Http::fake(function (Request $request, array $options) use (&$sent) {
            $sent[] = ['url' => $request->url(), 'data' => $request->data(), 'form' => $request->isForm(), 'method' => $request->method(), 'timeout' => $options['timeout'] ?? null];

            return Http::response(['ok' => true]);
        });
        Setting::save('monitoring', ['telegram_token' => '123456:'.str_repeat('A', 30), 'telegram_chat_id' => '-1001234567890']);

        app(Notifier::class)->opened($this->incident());

        $this->assertSame([[
            'url' => 'https://api.telegram.org/bot123456:'.str_repeat('A', 30).'/sendMessage',
            'data' => [
                'chat_id' => '-1001234567890',
                'text' => "Down: Demo shop\n\nhttps://shop.example.test/ has not answered 3 checks in a row since 2026-11-16 10:00.\nLast error: HTTP 500",
                'disable_web_page_preview' => 'true',
            ],
            'form' => true,
            'method' => 'POST',
            'timeout' => 10,
        ]], $sent);
    }

    public function test_nothing_is_sent_where_nobody_is_listening(): void
    {
        Http::fake();
        Mail::fake();

        app(Notifier::class)->opened($this->incident());
        app(Notifier::class)->resolved($this->incident());

        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_a_failed_telegram_alert_is_logged_by_channel_and_kind_of_error_only(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 401)]);
        Setting::save('monitoring', ['telegram_token' => '777:'.str_repeat('S', 30), 'telegram_chat_id' => '42']);
        Log::spy();

        app(Notifier::class)->resolved($this->incident());

        Log::shouldHaveReceived('warning')->once()->with('Atrium monitoring could not send an alert by telegram: RequestException');
    }

    public function test_a_failed_mail_does_not_stop_the_telegram_alert(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::save('monitoring', ['alert_emails' => 'ops@example.test', 'telegram_token' => '777:'.str_repeat('S', 30), 'telegram_chat_id' => '42']);
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('smtp is down'));
        Log::spy();

        app(Notifier::class)->opened($this->incident());

        Log::shouldHaveReceived('warning')->once()->with('Atrium monitoring could not send an alert by mail: RuntimeException');
        Http::assertSentCount(1);
    }
}
