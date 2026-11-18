<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'title' => 'Monitoring',
    'menu' => 'Szybkość i dostępność',
    'health' => 'Stan witryny',
    'no_data' => 'Brak danych',
    'minutes' => ':count minuta|:count minuty|:count minut',

    'modules' => [
        'checks' => 'Sprawdzenia dostępności',
        'incidents' => 'Incydenty',
    ],
    'create' => [
        'check' => 'Dodaj sprawdzenie',
    ],
    'fields' => [
        'name' => 'Nazwa',
        'url' => 'Adres',
        'status' => 'Stan',
        'last_error' => 'Ostatni błąd',
        'response_ms' => 'Odpowiedź',
        'checked_at' => 'Sprawdzono',
        'check' => 'Sprawdzenie',
        'started_at' => 'Początek',
        'resolved_at' => 'Koniec',
        'duration' => 'Czas trwania',
        'failures' => 'Nieudane sprawdzenia',
    ],
    'hints' => [
        'url' => 'Otwierany co minutę. Strona główna każdej witryny jest sprawdzana automatycznie.',
    ],
    'status' => [
        'unknown' => 'Jeszcze nie sprawdzano',
        'up' => 'Działa',
        'failing' => 'Zawodzi',
        'down' => 'Nie działa',
    ],

    'settings' => [
        'description' => 'Kogo powiadamiać, gdy witryna przestaje odpowiadać, i po ilu nieudanych sprawdzeniach z rzędu.',
        'failures' => 'Nieudane sprawdzenia do incydentu',
        'failures_hint' => 'Sprawdzenie co minutę: 3 oznacza trzy minuty bez odpowiedzi.',
        'alert_emails' => 'E-maile z alertami',
        'alert_emails_hint' => 'Jeden lub kilka adresów, oddzielonych przecinkami lub nowymi wierszami.',
        'telegram_token' => 'Token bota Telegram',
        'telegram_token_hint' => 'Wydaje go @BotFather. Przechowywany w bazie, nie w kodzie; ATRIUM_MONITORING_TELEGRAM_TOKEN w .env ma pierwszeństwo.',
        'telegram_chat_id' => 'Czat Telegram',
        'telegram_chat_id_hint' => 'Numer czatu, np. -1001234567890, lub kanał, np. @site_alerts. Bot musi być jego członkiem.',
    ],

    'alert' => [
        'opened_subject' => 'Nie działa: :name',
        'opened_body' => ":url nie odpowiada na :failures sprawdzeń z rzędu, od :since.\nOstatni błąd: :error",
        'resolved_subject' => 'Znów działa: :name',
        'resolved_body' => ':url znów odpowiada, przerwa trwała :duration.',
    ],

    'filters' => [
        'period' => 'Okres',
        'site' => 'Witryna',
        'locale' => 'Język',
        'device' => 'Urządzenie',
        'all' => 'Wszystkie',
        'route' => 'Trasa',
        'page' => 'Strona',
        'clear' => 'Pokaż wszystko',
    ],
    'periods' => [
        'day' => 'Dziennie, 30 dni',
        'week' => 'Tygodniowo, 26 tygodni',
        'month' => 'Miesięcznie, 13 miesięcy',
    ],
    'devices' => [
        'mobile' => 'Telefon',
        'tablet' => 'Tablet',
        'desktop' => 'Komputer',
    ],

    'sections' => [
        'server' => 'Serwer',
        'vitals' => 'Odwiedzający (Web Vitals)',
        'uptime' => 'Dostępność',
        'routes' => 'Najwolniejsze trasy',
        'queries' => 'Najwolniejsze zapytania',
        'pages' => 'Najwolniejsze strony (LCP)',
        'incidents' => 'Ostatnie incydenty',
        'routes_of_query' => 'Gdzie jest wykonywane (ostatnie 7 dni)',
    ],
    'summary' => [
        'requests' => 'Zmierzone żądania',
        'ttfb' => 'Czas do pierwszego bajtu',
        'errors' => 'Błędy serwera',
        'cache_ratio' => 'Trafienia w pamięci podręcznej',
        'queries' => 'Zapytania do bazy na żądanie',
        'memory_p95' => 'Pamięć, p95',
        'mb' => 'MB',
    ],
    'charts' => [
        'ttfb' => 'Czas do pierwszego bajtu',
        'db_ms' => 'Czas bazy danych na żądanie',
        'query_time' => 'Czas zapytania',
        'table' => 'Liczby z wykresu',
        'legend' => 'p50: połowa żądań była szybsza (koło, linia ciągła). p75: trzy z czterech (kwadrat, linia przerywana). p95: wszystkie poza jednym na dwadzieścia (trójkąt, linia kropkowana).',
    ],
    'columns' => [
        'period' => 'Okres',
        'count' => 'Zmierzono',
        'route' => 'Trasa',
        'page' => 'Strona',
        'query' => 'Zapytanie',
        'open' => 'Otwórz',
        'check' => 'Adres',
        'status' => 'Stan',
        'uptime' => 'Dostępność',
        'response' => 'Odpowiedź',
        'checked_at' => 'Sprawdzono',
        'times' => 'Razy',
        'average' => 'Średnio',
        'slowest' => 'Najdłużej',
        'connection' => 'Połączenie',
        'last_seen' => 'Ostatnio widziano',
        'rating' => 'Ocena',
    ],
    'vitals' => [
        'lcp' => 'Wyrenderowanie głównego elementu (LCP)',
        'inp' => 'Reakcja na działanie (INP)',
        'cls' => 'Przesunięcie układu (CLS)',
        'ttfb' => 'Czas do pierwszego bajtu (TTFB)',
        'fcp' => 'Pierwsze wyrenderowanie (FCP)',
        'thresholds' => 'Dobrze do :good, źle powyżej :poor',
    ],
    'ratings' => [
        'good' => 'Dobrze',
        'needs_improvement' => 'Do poprawy',
        'poor' => 'Źle',
    ],
    'links' => [
        'errors' => 'Błędy tej strony',
    ],
    'gadget' => [
        'title' => 'Szybkość i dostępność',
        'vitals' => 'Odwiedzający, p75 z 7 dni',
        'uptime' => 'Dostępność z 30 dni',
        'down' => 'Teraz nie działa',
        'slowest' => 'Najwolniejsze trasy, p75',
        'open' => 'Otwórz monitoring',
    ],
    'privacy' => 'Mierzone bez plików cookie i danych osobowych; odwiedzający z Do Not Track są pomijani.',
    'back' => 'Wróć do monitoringu',
    'ongoing' => 'trwa',
    'units' => [
        'decimal' => ',',
        'ms' => ':value ms',
        's' => ':value s',
    ],
    'permissions' => [
        'view' => 'Podgląd raportów szybkości i dostępności',
    ],
];
