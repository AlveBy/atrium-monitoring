<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'title' => 'Monitoring',
    'menu' => 'Speed and uptime',
    'health' => 'Site health',
    'no_data' => 'No data yet',
    'minutes' => ':count minute|:count minutes',

    'modules' => [
        'checks' => 'Uptime checks',
        'incidents' => 'Incidents',
    ],
    'create' => [
        'check' => 'Add a check',
    ],
    'fields' => [
        'name' => 'Name',
        'url' => 'Address',
        'status' => 'Status',
        'last_error' => 'Last error',
        'response_ms' => 'Response',
        'checked_at' => 'Checked',
        'check' => 'Check',
        'started_at' => 'Started',
        'resolved_at' => 'Resolved',
        'duration' => 'Duration',
        'failures' => 'Failed checks',
    ],
    'hints' => [
        'url' => 'Opened every minute. The front page of each site is checked by itself.',
    ],
    'status' => [
        'unknown' => 'Not checked yet',
        'up' => 'Up',
        'failing' => 'Failing',
        'down' => 'Down',
    ],

    'settings' => [
        'description' => 'Who is told when a site stops answering, and after how many failed checks in a row.',
        'failures' => 'Failed checks before an incident',
        'failures_hint' => 'Checks run every minute: 3 means three minutes without an answer.',
        'alert_emails' => 'Alert e-mails',
        'alert_emails_hint' => 'One or more addresses, separated by commas or new lines.',
        'telegram_token' => 'Telegram bot token',
        'telegram_token_hint' => 'From @BotFather. Kept in the database, never in the code; ATRIUM_MONITORING_TELEGRAM_TOKEN in .env wins.',
        'telegram_chat_id' => 'Telegram chat',
        'telegram_chat_id_hint' => 'A chat id such as -1001234567890 or a channel such as @site_alerts. The bot must be a member.',
    ],

    'alert' => [
        'opened_subject' => 'Down: :name',
        'opened_body' => ":url has not answered :failures checks in a row since :since.\nLast error: :error",
        'resolved_subject' => 'Back up: :name',
        'resolved_body' => ':url answers again after :duration.',
    ],

    'filters' => [
        'period' => 'Period',
        'site' => 'Site',
        'locale' => 'Language',
        'device' => 'Device',
        'all' => 'All',
        'route' => 'Route',
        'page' => 'Page',
        'clear' => 'Show everything',
    ],
    'periods' => [
        'day' => 'By day, 30 days',
        'week' => 'By week, 26 weeks',
        'month' => 'By month, 13 months',
    ],
    'devices' => [
        'mobile' => 'Phone',
        'tablet' => 'Tablet',
        'desktop' => 'Computer',
    ],

    'sections' => [
        'server' => 'Server',
        'vitals' => 'Visitors (Web Vitals)',
        'uptime' => 'Uptime',
        'routes' => 'Slowest routes',
        'queries' => 'Slowest queries',
        'pages' => 'Slowest pages (LCP)',
        'incidents' => 'Recent incidents',
        'routes_of_query' => 'Where it runs (last 7 days)',
    ],
    'summary' => [
        'requests' => 'Requests measured',
        'ttfb' => 'Time to first byte',
        'errors' => 'Server errors',
        'cache_ratio' => 'Cache hits',
        'queries' => 'Queries per request',
        'memory_p95' => 'Memory, p95',
        'mb' => 'MB',
    ],
    'charts' => [
        'ttfb' => 'Time to first byte',
        'db_ms' => 'Database time per request',
        'query_time' => 'Query time',
        'table' => 'The numbers of the chart',
        'legend' => 'p50: half of the requests were faster (circle, solid line). p75: three in four (square, dashed). p95: all but one in twenty (triangle, dotted).',
    ],
    'columns' => [
        'period' => 'Period',
        'count' => 'Measured',
        'route' => 'Route',
        'page' => 'Page',
        'query' => 'Query',
        'open' => 'Open',
        'check' => 'Address',
        'status' => 'Status',
        'uptime' => 'Uptime',
        'response' => 'Response',
        'checked_at' => 'Checked',
        'times' => 'Times',
        'average' => 'Average',
        'slowest' => 'Slowest',
        'connection' => 'Connection',
        'last_seen' => 'Last seen',
        'rating' => 'Rating',
    ],
    'vitals' => [
        'lcp' => 'Largest Contentful Paint',
        'inp' => 'Interaction to Next Paint',
        'cls' => 'Cumulative Layout Shift',
        'ttfb' => 'Time to First Byte',
        'fcp' => 'First Contentful Paint',
        'thresholds' => 'Good up to :good, poor above :poor',
    ],
    'ratings' => [
        'good' => 'Good',
        'needs_improvement' => 'Needs improvement',
        'poor' => 'Poor',
    ],
    'links' => [
        'errors' => 'Errors on this page',
    ],
    'gadget' => [
        'title' => 'Speed and uptime',
        'vitals' => 'Visitors, p75 over 7 days',
        'uptime' => 'Uptime over 30 days',
        'down' => 'Down now',
        'slowest' => 'Slowest routes, p75',
        'open' => 'Open monitoring',
    ],
    'privacy' => 'Measured without cookies or personal data; visitors with Do Not Track are left out.',
    'back' => 'Back to monitoring',
    'ongoing' => 'ongoing',
    'units' => [
        'decimal' => '.',
        'ms' => ':value ms',
        's' => ':value s',
    ],
    'permissions' => [
        'view' => 'View speed and uptime reports',
    ],
];
