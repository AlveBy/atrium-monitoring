<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Uptime;

use Atrium\Admin\Facades\Hook;
use Atrium\Admin\Facades\Setting;
use Atrium\Monitoring\Models\Incident;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells people that an address went down or came back: by e-mail (through
 * Laravel's mailer, which the mail module takes over with its accounts and
 * history when it is installed) and by a Telegram bot. Fires
 * `atrium.monitoring.incident.opened` / `.resolved` for anything else.
 *
 * A failed alert is logged and does not stop the checks.
 */
class Notifier
{
    public function opened(Incident $incident): void
    {
        Hook::fire('atrium.monitoring.incident.opened', $incident);
        $this->send('opened', $incident);
    }

    public function resolved(Incident $incident): void
    {
        Hook::fire('atrium.monitoring.incident.resolved', $incident);
        $this->send('resolved', $incident);
    }

    /**
     * @return array{subject:string,body:string}
     */
    public function message(string $kind, Incident $incident): array
    {
        $check = $incident->check;
        $replace = [
            'name' => $check?->name,
            'url' => $check?->url,
            'error' => $incident->last_error ?: '—',
            'since' => $incident->started_at->format('Y-m-d H:i'),
            'duration' => $incident->duration,
            'failures' => $incident->failures,
        ];

        return [
            'subject' => __('atrium-monitoring::monitoring.alert.'.$kind.'_subject', $replace),
            'body' => __('atrium-monitoring::monitoring.alert.'.$kind.'_body', $replace),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function emails(): array
    {
        $raw = (string) Setting::get('monitoring.alert_emails', '');
        $emails = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($emails, static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    }

    /**
     * @return array{0:string,1:string}|null token and chat
     */
    public function telegram(): ?array
    {
        $token = (string) (config('atrium-monitoring.telegram.token') ?: Setting::get('monitoring.telegram_token', ''));
        $chat = (string) (config('atrium-monitoring.telegram.chat_id') ?: Setting::get('monitoring.telegram_chat_id', ''));

        return $token !== '' && $chat !== '' ? [$token, $chat] : null;
    }

    private function send(string $kind, Incident $incident): void
    {
        $message = $this->message($kind, $incident);

        if (($emails = $this->emails()) !== []) {
            $this->attempt('mail', fn () => $this->mail($emails, $message));
        }

        if (($telegram = $this->telegram()) !== null) {
            $this->attempt('telegram', fn () => Http::timeout(10)->asForm()
                ->post('https://api.telegram.org/bot'.$telegram[0].'/sendMessage', [
                    'chat_id' => $telegram[1],
                    'text' => $message['subject']."\n\n".$message['body'],
                    'disable_web_page_preview' => 'true',
                ])->throw());
        }
    }

    /**
     * @param  array<int,string>  $emails
     * @param  array{subject:string,body:string}  $message
     */
    private function mail(array $emails, array $message): void
    {
        Mail::raw($message['body'], function ($mail) use ($emails, $message): void {
            $mail->to($emails)->subject($message['subject']);
        });
    }

    private function attempt(string $channel, callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            // The token is part of the address: never log the exception text as is.
            Log::warning('Atrium monitoring could not send an alert by '.$channel.': '.class_basename($e));
        }
    }
}
