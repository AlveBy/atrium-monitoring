<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Rum;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;

/**
 * The <script> tag with the Web Vitals script inlined: no extra request, and
 * under 2 KB. Not printed at all for a visitor who sent Do Not Track or
 * Global Privacy Control, or when real user monitoring is off.
 */
final class RumScript
{
    private ?string $source = null;

    public function __construct(private readonly string $path = __DIR__.'/../../resources/js/rum.js') {}

    public function tag(Request $request): string
    {
        $rate = (float) config('atrium-monitoring.rum.sample_rate', 1.0);

        if (! config('atrium-monitoring.rum.enabled', true) || $rate <= 0.0 || self::optedOut($request)) {
            return '';
        }

        $nonce = Vite::cspNonce();

        return sprintf(
            '<script data-endpoint="%s" data-rate="%s"%s>%s</script>',
            e(route('atrium-monitoring.rum', [], false)),
            e((string) min(1.0, $rate)),
            $nonce ? ' nonce="'.e($nonce).'"' : '',
            $this->source(),
        );
    }

    /** The script without comments and needless spaces. */
    public function source(): string
    {
        return $this->source ??= self::minify(File::get($this->path));
    }

    public static function optedOut(Request $request): bool
    {
        return $request->header('DNT') === '1' || $request->header('Sec-GPC') === '1';
    }

    private static function minify(string $js): string
    {
        $js = self::replace('~/\*.*?\*/~s', '', $js);
        // Outside the quoted strings, line comments and the spaces around punctuation go too.
        $string = "'(?:[^'\\\\]|\\\\.)*'(*SKIP)(*FAIL)|";
        $js = self::replace('~'.$string.'//.*~', '', $js);
        $js = self::replace('~'.$string.'\s*([{}()\[\]=,;:<>|&+\-?!*/])\s*~', '$1', $js);
        $js = self::replace('~'.$string.'\s+~', ' ', $js);

        return trim($js);
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }
}
