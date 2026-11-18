<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'title' => 'モニタリング',
    'menu' => '速度と稼働率',
    'health' => 'サイトの状態',
    'no_data' => 'まだデータがありません',
    'minutes' => ':count 分',

    'modules' => [
        'checks' => '稼働チェック',
        'incidents' => 'インシデント',
    ],
    'create' => [
        'check' => 'チェックを追加',
    ],
    'fields' => [
        'name' => '名前',
        'url' => 'アドレス',
        'status' => '状態',
        'last_error' => '最後のエラー',
        'response_ms' => '応答',
        'checked_at' => 'チェック日時',
        'check' => 'チェック',
        'started_at' => '開始',
        'resolved_at' => '終了',
        'duration' => '継続時間',
        'failures' => '失敗したチェック',
    ],
    'hints' => [
        'url' => '1 分ごとに開きます。各サイトのトップページは自動でチェックされます。',
    ],
    'status' => [
        'unknown' => 'まだチェックされていません',
        'up' => '稼働中',
        'failing' => '不安定',
        'down' => '停止中',
    ],

    'settings' => [
        'description' => 'サイトが応答しなくなったときの通知先と、連続何回チェックに失敗したら通知するかを設定します。',
        'failures' => 'インシデントとみなす失敗回数',
        'failures_hint' => 'チェックは 1 分ごとです。3 なら 3 分間応答がないことを意味します。',
        'alert_emails' => 'アラートのメールアドレス',
        'alert_emails_hint' => '1 件以上のアドレスをカンマまたは改行で区切って入力します。',
        'telegram_token' => 'Telegram ボットのトークン',
        'telegram_token_hint' => '@BotFather が発行します。コードではなくデータベースに保存されます。.env の ATRIUM_MONITORING_TELEGRAM_TOKEN が優先されます。',
        'telegram_chat_id' => 'Telegram チャット',
        'telegram_chat_id_hint' => '-1001234567890 のようなチャット ID、または @site_alerts のようなチャンネル。ボットがメンバーである必要があります。',
    ],

    'alert' => [
        'opened_subject' => '停止中: :name',
        'opened_body' => ":url は :since から :failures 回連続で応答していません。\n最後のエラー: :error",
        'resolved_subject' => '復旧しました: :name',
        'resolved_body' => ':url は :duration 後に再び応答しています。',
    ],

    'filters' => [
        'period' => '期間',
        'site' => 'サイト',
        'locale' => '言語',
        'device' => 'デバイス',
        'all' => 'すべて',
        'route' => 'ルート',
        'page' => 'ページ',
        'clear' => 'すべて表示',
    ],
    'periods' => [
        'day' => '日別、30 日',
        'week' => '週別、26 週',
        'month' => '月別、13 か月',
    ],
    'devices' => [
        'mobile' => 'スマートフォン',
        'tablet' => 'タブレット',
        'desktop' => 'パソコン',
    ],

    'sections' => [
        'server' => 'サーバー',
        'vitals' => '訪問者 (Web Vitals)',
        'uptime' => '稼働率',
        'routes' => '遅いルート',
        'queries' => '遅いクエリ',
        'pages' => '遅いページ (LCP)',
        'incidents' => '最近のインシデント',
        'routes_of_query' => '実行されている場所（過去 7 日）',
    ],
    'summary' => [
        'requests' => '計測したリクエスト',
        'ttfb' => '最初のバイトまでの時間',
        'errors' => 'サーバーエラー',
        'cache_ratio' => 'キャッシュヒット',
        'queries' => 'リクエストあたりのクエリ数',
        'memory_p95' => 'メモリ、p95',
        'mb' => 'MB',
    ],
    'charts' => [
        'ttfb' => '最初のバイトまでの時間',
        'db_ms' => 'リクエストあたりのデータベース時間',
        'query_time' => 'クエリ時間',
        'table' => 'グラフの数値',
        'legend' => 'p50: リクエストの半分はこれより速かった（丸、実線）。p75: 4 分の 3（四角、破線）。p95: 20 分の 1 を除くすべて（三角、点線）。',
    ],
    'columns' => [
        'period' => '期間',
        'count' => '計測数',
        'route' => 'ルート',
        'page' => 'ページ',
        'query' => 'クエリ',
        'open' => '開く',
        'check' => 'アドレス',
        'status' => '状態',
        'uptime' => '稼働率',
        'response' => '応答',
        'checked_at' => 'チェック日時',
        'times' => '回数',
        'average' => '平均',
        'slowest' => '最も遅い',
        'connection' => '接続',
        'last_seen' => '最終確認',
        'rating' => '評価',
    ],
    'vitals' => [
        'lcp' => '最大要素の表示 (LCP)',
        'inp' => '操作への反応 (INP)',
        'cls' => 'レイアウトのずれ (CLS)',
        'ttfb' => '最初のバイトまでの時間 (TTFB)',
        'fcp' => '最初の表示 (FCP)',
        'thresholds' => ':good まで良好、:poor を超えると不良',
    ],
    'ratings' => [
        'good' => '良好',
        'needs_improvement' => '要改善',
        'poor' => '不良',
    ],
    'links' => [
        'errors' => 'このページのエラー',
    ],
    'gadget' => [
        'title' => '速度と稼働率',
        'vitals' => '訪問者、7 日間の p75',
        'uptime' => '30 日間の稼働率',
        'down' => '現在停止中',
        'slowest' => '遅いルート、p75',
        'open' => 'モニタリングを開く',
    ],
    'privacy' => 'Cookie や個人データを使わずに計測します。Do Not Track を有効にしている訪問者は除外されます。',
    'back' => 'モニタリングに戻る',
    'ongoing' => '継続中',
    'units' => [
        'decimal' => '.',
        'ms' => ':valueミリ秒',
        's' => ':value秒',
    ],
    'permissions' => [
        'view' => '速度と稼働状況のレポートを表示',
    ],
];
