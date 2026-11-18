<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'title' => 'Monitoring',
    'menu' => 'Geschwindigkeit und Erreichbarkeit',
    'health' => 'Website-Zustand',
    'no_data' => 'Noch keine Daten',
    'minutes' => ':count Minute|:count Minuten',

    'modules' => [
        'checks' => 'Erreichbarkeitsprüfungen',
        'incidents' => 'Vorfälle',
    ],
    'create' => [
        'check' => 'Prüfung hinzufügen',
    ],
    'fields' => [
        'name' => 'Name',
        'url' => 'Adresse',
        'status' => 'Status',
        'last_error' => 'Letzter Fehler',
        'response_ms' => 'Antwort',
        'checked_at' => 'Geprüft',
        'check' => 'Prüfung',
        'started_at' => 'Beginn',
        'resolved_at' => 'Ende',
        'duration' => 'Dauer',
        'failures' => 'Fehlgeschlagene Prüfungen',
    ],
    'hints' => [
        'url' => 'Wird jede Minute aufgerufen. Die Startseite jeder Website wird automatisch geprüft.',
    ],
    'status' => [
        'unknown' => 'Noch nicht geprüft',
        'up' => 'Erreichbar',
        'failing' => 'Gestört',
        'down' => 'Nicht erreichbar',
    ],

    'settings' => [
        'description' => 'Wer benachrichtigt wird, wenn eine Website nicht mehr antwortet, und nach wie vielen fehlgeschlagenen Prüfungen in Folge.',
        'failures' => 'Fehlgeschlagene Prüfungen bis zum Vorfall',
        'failures_hint' => 'Geprüft wird jede Minute: 3 bedeutet drei Minuten ohne Antwort.',
        'alert_emails' => 'E-Mail-Adressen für Warnungen',
        'alert_emails_hint' => 'Eine oder mehrere Adressen, getrennt durch Kommas oder Zeilenumbrüche.',
        'telegram_token' => 'Telegram-Bot-Token',
        'telegram_token_hint' => 'Von @BotFather. Wird in der Datenbank gespeichert, nicht im Code; ATRIUM_MONITORING_TELEGRAM_TOKEN in der .env hat Vorrang.',
        'telegram_chat_id' => 'Telegram-Chat',
        'telegram_chat_id_hint' => 'Eine Chat-ID wie -1001234567890 oder ein Kanal wie @site_alerts. Der Bot muss Mitglied sein.',
    ],

    'alert' => [
        'opened_subject' => 'Nicht erreichbar: :name',
        'opened_body' => ":url antwortet seit :since auf :failures Prüfungen in Folge nicht.\nLetzter Fehler: :error",
        'resolved_subject' => 'Wieder erreichbar: :name',
        'resolved_body' => ':url antwortet nach :duration wieder.',
    ],

    'filters' => [
        'period' => 'Zeitraum',
        'site' => 'Website',
        'locale' => 'Sprache',
        'device' => 'Gerät',
        'all' => 'Alle',
        'route' => 'Route',
        'page' => 'Seite',
        'clear' => 'Alles anzeigen',
    ],
    'periods' => [
        'day' => 'Pro Tag, 30 Tage',
        'week' => 'Pro Woche, 26 Wochen',
        'month' => 'Pro Monat, 13 Monate',
    ],
    'devices' => [
        'mobile' => 'Smartphone',
        'tablet' => 'Tablet',
        'desktop' => 'Computer',
    ],

    'sections' => [
        'server' => 'Server',
        'vitals' => 'Besucher (Web Vitals)',
        'uptime' => 'Erreichbarkeit',
        'routes' => 'Langsamste Routen',
        'queries' => 'Langsamste Abfragen',
        'pages' => 'Langsamste Seiten (LCP)',
        'incidents' => 'Letzte Vorfälle',
        'routes_of_query' => 'Wo sie läuft (letzte 7 Tage)',
    ],
    'summary' => [
        'requests' => 'Gemessene Anfragen',
        'ttfb' => 'Zeit bis zum ersten Byte',
        'errors' => 'Serverfehler',
        'cache_ratio' => 'Cache-Treffer',
        'queries' => 'Abfragen pro Anfrage',
        'memory_p95' => 'Arbeitsspeicher, p95',
        'mb' => 'MB',
    ],
    'charts' => [
        'ttfb' => 'Zeit bis zum ersten Byte',
        'db_ms' => 'Datenbankzeit pro Anfrage',
        'query_time' => 'Abfragezeit',
        'table' => 'Die Zahlen zum Diagramm',
        'legend' => 'p50: Die Hälfte der Anfragen war schneller (Kreis, durchgezogene Linie). p75: drei von vier (Quadrat, gestrichelt). p95: alle bis auf eine von zwanzig (Dreieck, gepunktet).',
    ],
    'columns' => [
        'period' => 'Zeitraum',
        'count' => 'Gemessen',
        'route' => 'Route',
        'page' => 'Seite',
        'query' => 'Abfrage',
        'open' => 'Öffnen',
        'check' => 'Adresse',
        'status' => 'Status',
        'uptime' => 'Erreichbarkeit',
        'response' => 'Antwort',
        'checked_at' => 'Geprüft',
        'times' => 'Anzahl',
        'average' => 'Durchschnitt',
        'slowest' => 'Am langsamsten',
        'connection' => 'Verbindung',
        'last_seen' => 'Zuletzt gesehen',
        'rating' => 'Bewertung',
    ],
    'vitals' => [
        'lcp' => 'Größtes Element sichtbar (LCP)',
        'inp' => 'Reaktion auf Eingabe (INP)',
        'cls' => 'Layoutverschiebung (CLS)',
        'ttfb' => 'Zeit bis zum ersten Byte (TTFB)',
        'fcp' => 'Erster sichtbarer Inhalt (FCP)',
        'thresholds' => 'Gut bis :good, schlecht über :poor',
    ],
    'ratings' => [
        'good' => 'Gut',
        'needs_improvement' => 'Verbesserungswürdig',
        'poor' => 'Schlecht',
    ],
    'links' => [
        'errors' => 'Fehler dieser Seite',
    ],
    'gadget' => [
        'title' => 'Geschwindigkeit und Erreichbarkeit',
        'vitals' => 'Besucher, p75 über 7 Tage',
        'uptime' => 'Erreichbarkeit über 30 Tage',
        'down' => 'Aktuell nicht erreichbar',
        'slowest' => 'Langsamste Routen, p75',
        'open' => 'Monitoring öffnen',
    ],
    'privacy' => 'Gemessen ohne Cookies und personenbezogene Daten; Besucher mit Do Not Track werden nicht erfasst.',
    'back' => 'Zurück zum Monitoring',
    'ongoing' => 'laufend',
    'units' => [
        'decimal' => ',',
        'ms' => ':value ms',
        's' => ':value s',
    ],
    'permissions' => [
        'view' => 'Berichte zu Geschwindigkeit und Erreichbarkeit ansehen',
    ],
];
