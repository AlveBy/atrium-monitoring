<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'title' => 'Monitoring',
    'menu' => 'Tezlik va mavjudlik',
    'health' => 'Sayt holati',
    'no_data' => 'Hozircha maʼlumot yoʻq',
    'minutes' => ':count daqiqa',

    'modules' => [
        'checks' => 'Mavjudlik tekshiruvlari',
        'incidents' => 'Hodisalar',
    ],
    'create' => [
        'check' => 'Tekshiruv qoʻshish',
    ],
    'fields' => [
        'name' => 'Nomi',
        'url' => 'Manzil',
        'status' => 'Holati',
        'last_error' => 'Oxirgi xato',
        'response_ms' => 'Javob',
        'checked_at' => 'Tekshirilgan',
        'check' => 'Tekshiruv',
        'started_at' => 'Boshlanishi',
        'resolved_at' => 'Tugashi',
        'duration' => 'Davomiyligi',
        'failures' => 'Muvaffaqiyatsiz tekshiruvlar',
    ],
    'hints' => [
        'url' => 'Daqiqada bir marta ochiladi. Har bir saytning bosh sahifasi oʻzi tekshiriladi.',
    ],
    'status' => [
        'unknown' => 'Hali tekshirilmagan',
        'up' => 'Ishlayapti',
        'failing' => 'Nosoz',
        'down' => 'Ishlamayapti',
    ],

    'settings' => [
        'description' => 'Sayt javob berishni toʻxtatsa kimga va ketma-ket nechta muvaffaqiyatsiz tekshiruvdan keyin xabar berilsin.',
        'failures' => 'Hodisagacha muvaffaqiyatsiz tekshiruvlar',
        'failures_hint' => 'Tekshiruv daqiqada bir marta ishlaydi: 3 — javobsiz uch daqiqa.',
        'alert_emails' => 'Ogohlantirish pochtasi',
        'alert_emails_hint' => 'Bir yoki bir nechta manzil, vergul bilan yoki yangi qatordan.',
        'telegram_token' => 'Telegram bot tokeni',
        'telegram_token_hint' => '@BotFather beradi. Kodda emas, bazada saqlanadi; .env dagi ATRIUM_MONITORING_TELEGRAM_TOKEN ustun turadi.',
        'telegram_chat_id' => 'Telegram chati',
        'telegram_chat_id_hint' => 'Chat raqami, masalan -1001234567890, yoki kanal, masalan @site_alerts. Bot uning aʼzosi boʻlishi kerak.',
    ],

    'alert' => [
        'opened_subject' => 'Ishlamayapti: :name',
        'opened_body' => ":url :since dan beri ketma-ket :failures marta tekshiruvda javob bermadi.\nOxirgi xato: :error",
        'resolved_subject' => 'Yana ishlayapti: :name',
        'resolved_body' => ':url yana javob bermoqda, uzilish :duration davom etdi.',
    ],

    'filters' => [
        'period' => 'Davr',
        'site' => 'Sayt',
        'locale' => 'Til',
        'device' => 'Qurilma',
        'all' => 'Hammasi',
        'route' => 'Marshrut',
        'page' => 'Sahifa',
        'clear' => 'Hammasini koʻrsatish',
    ],
    'periods' => [
        'day' => 'Kunlar boʻyicha, 30 kun',
        'week' => 'Haftalar boʻyicha, 26 hafta',
        'month' => 'Oylar boʻyicha, 13 oy',
    ],
    'devices' => [
        'mobile' => 'Telefon',
        'tablet' => 'Planshet',
        'desktop' => 'Kompyuter',
    ],

    'sections' => [
        'server' => 'Server',
        'vitals' => 'Tashrif buyuruvchilar (Web Vitals)',
        'uptime' => 'Mavjudlik',
        'routes' => 'Eng sekin marshrutlar',
        'queries' => 'Eng sekin soʻrovlar',
        'pages' => 'Eng sekin sahifalar (LCP)',
        'incidents' => 'Oxirgi hodisalar',
        'routes_of_query' => 'Qayerda bajariladi (oxirgi 7 kun)',
    ],
    'summary' => [
        'requests' => 'Oʻlchangan soʻrovlar',
        'ttfb' => 'Birinchi baytgacha vaqt',
        'errors' => 'Server xatolari',
        'cache_ratio' => 'Keshga tushishlar',
        'queries' => 'Sahifaga bazaga soʻrovlar',
        'memory_p95' => 'Xotira, p95',
        'mb' => 'MB',
    ],
    'charts' => [
        'ttfb' => 'Birinchi baytgacha vaqt',
        'db_ms' => 'Soʻrovga baza vaqti',
        'query_time' => 'Soʻrov vaqti',
        'table' => 'Grafik raqamlari',
        'legend' => 'p50: soʻrovlarning yarmi tezroq boʻldi (doira, yaxlit chiziq). p75: toʻrttadan uchtasi (kvadrat, uzuq chiziq). p95: yigirmatadan bittasidan tashqari hammasi (uchburchak, nuqtalar).',
    ],
    'columns' => [
        'period' => 'Davr',
        'count' => 'Oʻlchangan',
        'route' => 'Marshrut',
        'page' => 'Sahifa',
        'query' => 'Soʻrov',
        'open' => 'Ochish',
        'check' => 'Manzil',
        'status' => 'Holati',
        'uptime' => 'Mavjudlik',
        'response' => 'Javob',
        'checked_at' => 'Tekshirilgan',
        'times' => 'Marta',
        'average' => 'Oʻrtacha',
        'slowest' => 'Eng uzoq',
        'connection' => 'Aloqa',
        'last_seen' => 'Oxirgi marta',
        'rating' => 'Baho',
    ],
    'vitals' => [
        'lcp' => 'Asosiy blokning chizilishi (LCP)',
        'inp' => 'Harakatga javob (INP)',
        'cls' => 'Maket siljishi (CLS)',
        'ttfb' => 'Birinchi bayt (TTFB)',
        'fcp' => 'Birinchi chizilish (FCP)',
        'thresholds' => ':good gacha yaxshi, :poor dan yuqori yomon',
    ],
    'ratings' => [
        'good' => 'Yaxshi',
        'needs_improvement' => 'Qoniqarli',
        'poor' => 'Yomon',
    ],
    'links' => [
        'errors' => 'Shu sahifa xatolari',
    ],
    'gadget' => [
        'title' => 'Tezlik va mavjudlik',
        'vitals' => 'Tashrif buyuruvchilar, 7 kunlik p75',
        'uptime' => '30 kunlik mavjudlik',
        'down' => 'Hozir ishlamayapti',
        'slowest' => 'Sekin marshrutlar, p75',
        'open' => 'Monitoringni ochish',
    ],
    'privacy' => 'Cookie va shaxsiy maʼlumotlarsiz oʻlchanadi; Do Not Track yoqilgan tashrif buyuruvchilar hisobga olinmaydi.',
    'back' => 'Monitoringga qaytish',
    'ongoing' => 'davom etmoqda',
    'units' => [
        'decimal' => ',',
        'ms' => ':value ms',
        's' => ':value s',
    ],
    'permissions' => [
        'view' => 'Tezlik va ishlash hisobotlarini koʻrish',
    ],
];
