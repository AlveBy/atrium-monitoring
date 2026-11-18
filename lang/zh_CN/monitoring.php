<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'title' => '监控',
    'menu' => '速度与可用性',
    'health' => '站点健康',
    'no_data' => '暂无数据',
    'minutes' => ':count 分钟',

    'modules' => [
        'checks' => '可用性检查',
        'incidents' => '事件',
    ],
    'create' => [
        'check' => '添加检查',
    ],
    'fields' => [
        'name' => '名称',
        'url' => '地址',
        'status' => '状态',
        'last_error' => '最近错误',
        'response_ms' => '响应',
        'checked_at' => '检查时间',
        'check' => '检查',
        'started_at' => '开始',
        'resolved_at' => '结束',
        'duration' => '持续时间',
        'failures' => '失败的检查',
    ],
    'hints' => [
        'url' => '每分钟访问一次。每个站点的首页会自动检查。',
    ],
    'status' => [
        'unknown' => '尚未检查',
        'up' => '正常运行',
        'failing' => '不稳定',
        'down' => '无法访问',
    ],

    'settings' => [
        'description' => '站点停止响应时通知谁，以及连续失败多少次检查后通知。',
        'failures' => '触发事件前的失败检查次数',
        'failures_hint' => '每分钟检查一次：3 表示连续三分钟无响应。',
        'alert_emails' => '告警邮箱',
        'alert_emails_hint' => '可填一个或多个地址，用逗号或换行分隔。',
        'telegram_token' => 'Telegram 机器人令牌',
        'telegram_token_hint' => '由 @BotFather 提供。保存在数据库中，不写入代码；.env 中的 ATRIUM_MONITORING_TELEGRAM_TOKEN 优先。',
        'telegram_chat_id' => 'Telegram 聊天',
        'telegram_chat_id_hint' => '聊天 ID（如 -1001234567890）或频道（如 @site_alerts）。机器人必须是其成员。',
    ],

    'alert' => [
        'opened_subject' => '无法访问：:name',
        'opened_body' => ":url 自 :since 起连续 :failures 次检查无响应。\n最近错误：:error",
        'resolved_subject' => '已恢复：:name',
        'resolved_body' => ':url 已恢复响应，故障持续了 :duration。',
    ],

    'filters' => [
        'period' => '时间段',
        'site' => '站点',
        'locale' => '语言',
        'device' => '设备',
        'all' => '全部',
        'route' => '路由',
        'page' => '页面',
        'clear' => '显示全部',
    ],
    'periods' => [
        'day' => '按天，30 天',
        'week' => '按周，26 周',
        'month' => '按月，13 个月',
    ],
    'devices' => [
        'mobile' => '手机',
        'tablet' => '平板',
        'desktop' => '电脑',
    ],

    'sections' => [
        'server' => '服务器',
        'vitals' => '访客 (Web Vitals)',
        'uptime' => '可用性',
        'routes' => '最慢的路由',
        'queries' => '最慢的查询',
        'pages' => '最慢的页面 (LCP)',
        'incidents' => '最近的事件',
        'routes_of_query' => '执行位置（最近 7 天）',
    ],
    'summary' => [
        'requests' => '已测量的请求',
        'ttfb' => '首字节时间',
        'errors' => '服务器错误',
        'cache_ratio' => '缓存命中',
        'queries' => '每次请求的查询数',
        'memory_p95' => '内存，p95',
        'mb' => 'MB',
    ],
    'charts' => [
        'ttfb' => '首字节时间',
        'db_ms' => '每次请求的数据库耗时',
        'query_time' => '查询耗时',
        'table' => '图表数据',
        'legend' => 'p50：一半的请求更快（圆形，实线）。p75：四分之三（方形，虚线）。p95：除二十分之一外的全部（三角形，点线）。',
    ],
    'columns' => [
        'period' => '时间段',
        'count' => '已测量',
        'route' => '路由',
        'page' => '页面',
        'query' => '查询',
        'open' => '打开',
        'check' => '地址',
        'status' => '状态',
        'uptime' => '可用性',
        'response' => '响应',
        'checked_at' => '检查时间',
        'times' => '次数',
        'average' => '平均',
        'slowest' => '最慢',
        'connection' => '连接',
        'last_seen' => '最近出现',
        'rating' => '评级',
    ],
    'vitals' => [
        'lcp' => '最大元素显示 (LCP)',
        'inp' => '交互响应 (INP)',
        'cls' => '布局偏移 (CLS)',
        'ttfb' => '首字节时间 (TTFB)',
        'fcp' => '首次内容显示 (FCP)',
        'thresholds' => '不超过 :good 为良好，超过 :poor 为较差',
    ],
    'ratings' => [
        'good' => '良好',
        'needs_improvement' => '需改进',
        'poor' => '较差',
    ],
    'links' => [
        'errors' => '此页面的错误',
    ],
    'gadget' => [
        'title' => '速度与可用性',
        'vitals' => '访客，7 天 p75',
        'uptime' => '30 天可用性',
        'down' => '当前无法访问',
        'slowest' => '最慢的路由，p75',
        'open' => '打开监控',
    ],
    'privacy' => '统计不使用 cookie，也不收集个人数据；开启 Do Not Track 的访客不计入。',
    'back' => '返回监控',
    'ongoing' => '进行中',
    'units' => [
        'decimal' => '.',
        'ms' => ':value毫秒',
        's' => ':value秒',
    ],
    'permissions' => [
        'view' => '查看速度和可用性报告',
    ],
];
