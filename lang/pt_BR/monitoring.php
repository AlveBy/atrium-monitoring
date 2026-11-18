<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'title' => 'Monitoramento',
    'menu' => 'Velocidade e disponibilidade',
    'health' => 'Saúde do site',
    'no_data' => 'Ainda não há dados',
    'minutes' => ':count minuto|:count minutos',

    'modules' => [
        'checks' => 'Verificações de disponibilidade',
        'incidents' => 'Incidentes',
    ],
    'create' => [
        'check' => 'Adicionar verificação',
    ],
    'fields' => [
        'name' => 'Nome',
        'url' => 'Endereço',
        'status' => 'Status',
        'last_error' => 'Último erro',
        'response_ms' => 'Resposta',
        'checked_at' => 'Verificado',
        'check' => 'Verificação',
        'started_at' => 'Início',
        'resolved_at' => 'Fim',
        'duration' => 'Duração',
        'failures' => 'Verificações com falha',
    ],
    'hints' => [
        'url' => 'Aberto a cada minuto. A página inicial de cada site é verificada automaticamente.',
    ],
    'status' => [
        'unknown' => 'Ainda não verificado',
        'up' => 'No ar',
        'failing' => 'Instável',
        'down' => 'Fora do ar',
    ],

    'settings' => [
        'description' => 'Quem é avisado quando um site para de responder e após quantas verificações com falha seguidas.',
        'failures' => 'Verificações com falha antes de um incidente',
        'failures_hint' => 'A verificação roda a cada minuto: 3 significa três minutos sem resposta.',
        'alert_emails' => 'E-mails de alerta',
        'alert_emails_hint' => 'Um ou mais endereços, separados por vírgulas ou quebras de linha.',
        'telegram_token' => 'Token do bot do Telegram',
        'telegram_token_hint' => 'Fornecido pelo @BotFather. Fica guardado no banco de dados, nunca no código; ATRIUM_MONITORING_TELEGRAM_TOKEN no .env tem prioridade.',
        'telegram_chat_id' => 'Chat do Telegram',
        'telegram_chat_id_hint' => 'Um ID de chat como -1001234567890 ou um canal como @site_alerts. O bot precisa ser membro.',
    ],

    'alert' => [
        'opened_subject' => 'Fora do ar: :name',
        'opened_body' => ":url não responde há :failures verificações seguidas, desde :since.\nÚltimo erro: :error",
        'resolved_subject' => 'De volta ao ar: :name',
        'resolved_body' => ':url voltou a responder após :duration.',
    ],

    'filters' => [
        'period' => 'Período',
        'site' => 'Site',
        'locale' => 'Idioma',
        'device' => 'Dispositivo',
        'all' => 'Todos',
        'route' => 'Rota',
        'page' => 'Página',
        'clear' => 'Mostrar tudo',
    ],
    'periods' => [
        'day' => 'Por dia, 30 dias',
        'week' => 'Por semana, 26 semanas',
        'month' => 'Por mês, 13 meses',
    ],
    'devices' => [
        'mobile' => 'Celular',
        'tablet' => 'Tablet',
        'desktop' => 'Computador',
    ],

    'sections' => [
        'server' => 'Servidor',
        'vitals' => 'Visitantes (Web Vitals)',
        'uptime' => 'Disponibilidade',
        'routes' => 'Rotas mais lentas',
        'queries' => 'Consultas mais lentas',
        'pages' => 'Páginas mais lentas (LCP)',
        'incidents' => 'Incidentes recentes',
        'routes_of_query' => 'Onde é executada (últimos 7 dias)',
    ],
    'summary' => [
        'requests' => 'Requisições medidas',
        'ttfb' => 'Tempo até o primeiro byte',
        'errors' => 'Erros do servidor',
        'cache_ratio' => 'Acertos de cache',
        'queries' => 'Consultas por requisição',
        'memory_p95' => 'Memória, p95',
        'mb' => 'MB',
    ],
    'charts' => [
        'ttfb' => 'Tempo até o primeiro byte',
        'db_ms' => 'Tempo de banco de dados por requisição',
        'query_time' => 'Tempo da consulta',
        'table' => 'Os números do gráfico',
        'legend' => 'p50: metade das requisições foi mais rápida (círculo, linha contínua). p75: três em cada quatro (quadrado, tracejada). p95: todas menos uma em vinte (triângulo, pontilhada).',
    ],
    'columns' => [
        'period' => 'Período',
        'count' => 'Medido',
        'route' => 'Rota',
        'page' => 'Página',
        'query' => 'Consulta',
        'open' => 'Abrir',
        'check' => 'Endereço',
        'status' => 'Status',
        'uptime' => 'Disponibilidade',
        'response' => 'Resposta',
        'checked_at' => 'Verificado',
        'times' => 'Vezes',
        'average' => 'Média',
        'slowest' => 'Mais lento',
        'connection' => 'Conexão',
        'last_seen' => 'Visto pela última vez',
        'rating' => 'Avaliação',
    ],
    'vitals' => [
        'lcp' => 'Maior elemento visível (LCP)',
        'inp' => 'Resposta à interação (INP)',
        'cls' => 'Mudança de layout (CLS)',
        'ttfb' => 'Tempo até o primeiro byte (TTFB)',
        'fcp' => 'Primeiro conteúdo visível (FCP)',
        'thresholds' => 'Bom até :good, ruim acima de :poor',
    ],
    'ratings' => [
        'good' => 'Bom',
        'needs_improvement' => 'Precisa melhorar',
        'poor' => 'Ruim',
    ],
    'links' => [
        'errors' => 'Erros desta página',
    ],
    'gadget' => [
        'title' => 'Velocidade e disponibilidade',
        'vitals' => 'Visitantes, p75 em 7 dias',
        'uptime' => 'Disponibilidade em 30 dias',
        'down' => 'Fora do ar agora',
        'slowest' => 'Rotas mais lentas, p75',
        'open' => 'Abrir monitoramento',
    ],
    'privacy' => 'Medido sem cookies nem dados pessoais; visitantes com Do Not Track ficam de fora.',
    'back' => 'Voltar ao monitoramento',
    'ongoing' => 'em andamento',
    'units' => [
        'decimal' => ',',
        'ms' => ':value ms',
        's' => ':value s',
    ],
    'permissions' => [
        'view' => 'Ver relatórios de velocidade e disponibilidade',
    ],
];
