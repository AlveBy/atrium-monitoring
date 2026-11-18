<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'title' => 'Monitorización',
    'menu' => 'Velocidad y disponibilidad',
    'health' => 'Estado del sitio',
    'no_data' => 'Aún no hay datos',
    'minutes' => ':count minuto|:count minutos',

    'modules' => [
        'checks' => 'Comprobaciones de disponibilidad',
        'incidents' => 'Incidencias',
    ],
    'create' => [
        'check' => 'Añadir una comprobación',
    ],
    'fields' => [
        'name' => 'Nombre',
        'url' => 'Dirección',
        'status' => 'Estado',
        'last_error' => 'Último error',
        'response_ms' => 'Respuesta',
        'checked_at' => 'Comprobado',
        'check' => 'Comprobación',
        'started_at' => 'Inicio',
        'resolved_at' => 'Fin',
        'duration' => 'Duración',
        'failures' => 'Comprobaciones fallidas',
    ],
    'hints' => [
        'url' => 'Se abre cada minuto. La página principal de cada sitio se comprueba sola.',
    ],
    'status' => [
        'unknown' => 'Aún sin comprobar',
        'up' => 'Funciona',
        'failing' => 'Con fallos',
        'down' => 'No funciona',
    ],

    'settings' => [
        'description' => 'A quién avisar cuando un sitio deja de responder y tras cuántas comprobaciones fallidas seguidas.',
        'failures' => 'Comprobaciones fallidas antes de una incidencia',
        'failures_hint' => 'Se comprueba cada minuto: 3 son tres minutos sin respuesta.',
        'alert_emails' => 'Correos de alerta',
        'alert_emails_hint' => 'Una o varias direcciones, separadas por comas o saltos de línea.',
        'telegram_token' => 'Token del bot de Telegram',
        'telegram_token_hint' => 'Lo entrega @BotFather. Se guarda en la base de datos, no en el código; ATRIUM_MONITORING_TELEGRAM_TOKEN en .env tiene prioridad.',
        'telegram_chat_id' => 'Chat de Telegram',
        'telegram_chat_id_hint' => 'Un ID de chat como -1001234567890 o un canal como @site_alerts. El bot debe ser miembro.',
    ],

    'alert' => [
        'opened_subject' => 'Caído: :name',
        'opened_body' => ":url no responde desde :since, tras :failures comprobaciones seguidas.\nÚltimo error: :error",
        'resolved_subject' => 'Recuperado: :name',
        'resolved_body' => ':url vuelve a responder tras :duration.',
    ],

    'filters' => [
        'period' => 'Periodo',
        'site' => 'Sitio',
        'locale' => 'Idioma',
        'device' => 'Dispositivo',
        'all' => 'Todos',
        'route' => 'Ruta',
        'page' => 'Página',
        'clear' => 'Mostrar todo',
    ],
    'periods' => [
        'day' => 'Por día, 30 días',
        'week' => 'Por semana, 26 semanas',
        'month' => 'Por mes, 13 meses',
    ],
    'devices' => [
        'mobile' => 'Teléfono',
        'tablet' => 'Tableta',
        'desktop' => 'Ordenador',
    ],

    'sections' => [
        'server' => 'Servidor',
        'vitals' => 'Visitantes (Web Vitals)',
        'uptime' => 'Disponibilidad',
        'routes' => 'Rutas más lentas',
        'queries' => 'Consultas más lentas',
        'pages' => 'Páginas más lentas (LCP)',
        'incidents' => 'Incidencias recientes',
        'routes_of_query' => 'Dónde se ejecuta (últimos 7 días)',
    ],
    'summary' => [
        'requests' => 'Peticiones medidas',
        'ttfb' => 'Tiempo hasta el primer byte',
        'errors' => 'Errores del servidor',
        'cache_ratio' => 'Aciertos de caché',
        'queries' => 'Consultas por petición',
        'memory_p95' => 'Memoria, p95',
        'mb' => 'MB',
    ],
    'charts' => [
        'ttfb' => 'Tiempo hasta el primer byte',
        'db_ms' => 'Tiempo de base de datos por petición',
        'query_time' => 'Tiempo de la consulta',
        'table' => 'Los números del gráfico',
        'legend' => 'p50: la mitad de las peticiones fue más rápida (círculo, línea continua). p75: tres de cada cuatro (cuadrado, línea discontinua). p95: todas menos una de cada veinte (triángulo, línea de puntos).',
    ],
    'columns' => [
        'period' => 'Periodo',
        'count' => 'Medido',
        'route' => 'Ruta',
        'page' => 'Página',
        'query' => 'Consulta',
        'open' => 'Abrir',
        'check' => 'Dirección',
        'status' => 'Estado',
        'uptime' => 'Disponibilidad',
        'response' => 'Respuesta',
        'checked_at' => 'Comprobado',
        'times' => 'Veces',
        'average' => 'Media',
        'slowest' => 'Más lento',
        'connection' => 'Conexión',
        'last_seen' => 'Visto por última vez',
        'rating' => 'Valoración',
    ],
    'vitals' => [
        'lcp' => 'Elemento más grande visible (LCP)',
        'inp' => 'Respuesta a la interacción (INP)',
        'cls' => 'Desplazamiento del diseño (CLS)',
        'ttfb' => 'Tiempo hasta el primer byte (TTFB)',
        'fcp' => 'Primer contenido visible (FCP)',
        'thresholds' => 'Bueno hasta :good, malo por encima de :poor',
    ],
    'ratings' => [
        'good' => 'Bueno',
        'needs_improvement' => 'Mejorable',
        'poor' => 'Malo',
    ],
    'links' => [
        'errors' => 'Errores de esta página',
    ],
    'gadget' => [
        'title' => 'Velocidad y disponibilidad',
        'vitals' => 'Visitantes, p75 en 7 días',
        'uptime' => 'Disponibilidad en 30 días',
        'down' => 'Caído ahora',
        'slowest' => 'Rutas más lentas, p75',
        'open' => 'Abrir la monitorización',
    ],
    'privacy' => 'Se mide sin cookies ni datos personales; los visitantes con Do Not Track quedan fuera.',
    'back' => 'Volver a la monitorización',
    'ongoing' => 'en curso',
    'units' => [
        'decimal' => ',',
        'ms' => ':value ms',
        's' => ':value s',
    ],
    'permissions' => [
        'view' => 'Ver los informes de velocidad y disponibilidad',
    ],
];
