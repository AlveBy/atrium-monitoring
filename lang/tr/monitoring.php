<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'title' => 'İzleme',
    'menu' => 'Hız ve erişilebilirlik',
    'health' => 'Site sağlığı',
    'no_data' => 'Henüz veri yok',
    'minutes' => ':count dakika',

    'modules' => [
        'checks' => 'Erişilebilirlik kontrolleri',
        'incidents' => 'Olaylar',
    ],
    'create' => [
        'check' => 'Kontrol ekle',
    ],
    'fields' => [
        'name' => 'Ad',
        'url' => 'Adres',
        'status' => 'Durum',
        'last_error' => 'Son hata',
        'response_ms' => 'Yanıt',
        'checked_at' => 'Kontrol edildi',
        'check' => 'Kontrol',
        'started_at' => 'Başlangıç',
        'resolved_at' => 'Bitiş',
        'duration' => 'Süre',
        'failures' => 'Başarısız kontroller',
    ],
    'hints' => [
        'url' => 'Dakikada bir açılır. Her sitenin ana sayfası kendiliğinden kontrol edilir.',
    ],
    'status' => [
        'unknown' => 'Henüz kontrol edilmedi',
        'up' => 'Çalışıyor',
        'failing' => 'Aksıyor',
        'down' => 'Çalışmıyor',
    ],

    'settings' => [
        'description' => 'Bir site yanıt vermeyi bıraktığında kime haber verileceği ve art arda kaç başarısız kontrolden sonra.',
        'failures' => 'Olaydan önceki başarısız kontroller',
        'failures_hint' => 'Kontrol dakikada bir yapılır: 3, yanıtsız üç dakika demektir.',
        'alert_emails' => 'Uyarı e-postaları',
        'alert_emails_hint' => 'Bir veya birkaç adres; virgülle veya yeni satırla ayırın.',
        'telegram_token' => 'Telegram bot belirteci',
        'telegram_token_hint' => '@BotFather verir. Kodda değil, veritabanında saklanır; .env içindeki ATRIUM_MONITORING_TELEGRAM_TOKEN önceliklidir.',
        'telegram_chat_id' => 'Telegram sohbeti',
        'telegram_chat_id_hint' => '-1001234567890 gibi bir sohbet numarası veya @site_alerts gibi bir kanal. Bot üyesi olmalıdır.',
    ],

    'alert' => [
        'opened_subject' => 'Çalışmıyor: :name',
        'opened_body' => ":url, :since tarihinden beri art arda :failures kontrolde yanıt vermedi.\nSon hata: :error",
        'resolved_subject' => 'Yeniden çalışıyor: :name',
        'resolved_body' => ':url yeniden yanıt veriyor, kesinti :duration sürdü.',
    ],

    'filters' => [
        'period' => 'Dönem',
        'site' => 'Site',
        'locale' => 'Dil',
        'device' => 'Cihaz',
        'all' => 'Tümü',
        'route' => 'Rota',
        'page' => 'Sayfa',
        'clear' => 'Tümünü göster',
    ],
    'periods' => [
        'day' => 'Günlük, 30 gün',
        'week' => 'Haftalık, 26 hafta',
        'month' => 'Aylık, 13 ay',
    ],
    'devices' => [
        'mobile' => 'Telefon',
        'tablet' => 'Tablet',
        'desktop' => 'Bilgisayar',
    ],

    'sections' => [
        'server' => 'Sunucu',
        'vitals' => 'Ziyaretçiler (Web Vitals)',
        'uptime' => 'Erişilebilirlik',
        'routes' => 'En yavaş rotalar',
        'queries' => 'En yavaş sorgular',
        'pages' => 'En yavaş sayfalar (LCP)',
        'incidents' => 'Son olaylar',
        'routes_of_query' => 'Nerede çalışıyor (son 7 gün)',
    ],
    'summary' => [
        'requests' => 'Ölçülen istekler',
        'ttfb' => 'İlk bayta kadar süre',
        'errors' => 'Sunucu hataları',
        'cache_ratio' => 'Önbellek isabetleri',
        'queries' => 'İstek başına sorgu',
        'memory_p95' => 'Bellek, p95',
        'mb' => 'MB',
    ],
    'charts' => [
        'ttfb' => 'İlk bayta kadar süre',
        'db_ms' => 'İstek başına veritabanı süresi',
        'query_time' => 'Sorgu süresi',
        'table' => 'Grafiğin sayıları',
        'legend' => 'p50: isteklerin yarısı daha hızlıydı (daire, düz çizgi). p75: dörtte üçü (kare, kesikli çizgi). p95: yirmide biri dışındakilerin hepsi (üçgen, noktalı çizgi).',
    ],
    'columns' => [
        'period' => 'Dönem',
        'count' => 'Ölçülen',
        'route' => 'Rota',
        'page' => 'Sayfa',
        'query' => 'Sorgu',
        'open' => 'Aç',
        'check' => 'Adres',
        'status' => 'Durum',
        'uptime' => 'Erişilebilirlik',
        'response' => 'Yanıt',
        'checked_at' => 'Kontrol edildi',
        'times' => 'Kez',
        'average' => 'Ortalama',
        'slowest' => 'En yavaş',
        'connection' => 'Bağlantı',
        'last_seen' => 'Son görülme',
        'rating' => 'Değerlendirme',
    ],
    'vitals' => [
        'lcp' => 'En büyük öğenin görünmesi (LCP)',
        'inp' => 'Etkileşime yanıt (INP)',
        'cls' => 'Düzen kayması (CLS)',
        'ttfb' => 'İlk bayta kadar süre (TTFB)',
        'fcp' => 'İlk içeriğin görünmesi (FCP)',
        'thresholds' => ':good değerine kadar iyi, :poor üzeri kötü',
    ],
    'ratings' => [
        'good' => 'İyi',
        'needs_improvement' => 'Geliştirilmeli',
        'poor' => 'Kötü',
    ],
    'links' => [
        'errors' => 'Bu sayfanın hataları',
    ],
    'gadget' => [
        'title' => 'Hız ve erişilebilirlik',
        'vitals' => 'Ziyaretçiler, 7 günde p75',
        'uptime' => '30 günde erişilebilirlik',
        'down' => 'Şu an çalışmıyor',
        'slowest' => 'En yavaş rotalar, p75',
        'open' => 'İzlemeyi aç',
    ],
    'privacy' => 'Çerez ve kişisel veri olmadan ölçülür; Do Not Track kullanan ziyaretçiler dışarıda bırakılır.',
    'back' => 'İzlemeye dön',
    'ongoing' => 'devam ediyor',
    'units' => [
        'decimal' => ',',
        'ms' => ':value ms',
        's' => ':value sn',
    ],
    'permissions' => [
        'view' => 'Hız ve erişilebilirlik raporlarını görüntüleme',
    ],
];
