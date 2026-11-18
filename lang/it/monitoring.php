<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'title' => 'Monitoraggio',
    'menu' => 'Velocità e disponibilità',
    'health' => 'Stato del sito',
    'no_data' => 'Ancora nessun dato',
    'minutes' => ':count minuto|:count minuti',

    'modules' => [
        'checks' => 'Controlli di disponibilità',
        'incidents' => 'Incidenti',
    ],
    'create' => [
        'check' => 'Aggiungi un controllo',
    ],
    'fields' => [
        'name' => 'Nome',
        'url' => 'Indirizzo',
        'status' => 'Stato',
        'last_error' => 'Ultimo errore',
        'response_ms' => 'Risposta',
        'checked_at' => 'Controllato',
        'check' => 'Controllo',
        'started_at' => 'Inizio',
        'resolved_at' => 'Fine',
        'duration' => 'Durata',
        'failures' => 'Controlli falliti',
    ],
    'hints' => [
        'url' => 'Aperto ogni minuto. La home page di ogni sito viene controllata automaticamente.',
    ],
    'status' => [
        'unknown' => 'Non ancora controllato',
        'up' => 'Attivo',
        'failing' => 'Instabile',
        'down' => 'Non raggiungibile',
    ],

    'settings' => [
        'description' => 'Chi avvisare quando un sito smette di rispondere e dopo quanti controlli falliti di fila.',
        'failures' => 'Controlli falliti prima di un incidente',
        'failures_hint' => 'Il controllo avviene ogni minuto: 3 significa tre minuti senza risposta.',
        'alert_emails' => 'E-mail di avviso',
        'alert_emails_hint' => 'Uno o più indirizzi, separati da virgole o da a capo.',
        'telegram_token' => 'Token del bot Telegram',
        'telegram_token_hint' => 'Fornito da @BotFather. Salvato nel database, mai nel codice; ATRIUM_MONITORING_TELEGRAM_TOKEN in .env ha la precedenza.',
        'telegram_chat_id' => 'Chat Telegram',
        'telegram_chat_id_hint' => 'Un ID chat come -1001234567890 o un canale come @site_alerts. Il bot deve esserne membro.',
    ],

    'alert' => [
        'opened_subject' => 'Non raggiungibile: :name',
        'opened_body' => ":url non risponde da :failures controlli di fila, dal :since.\nUltimo errore: :error",
        'resolved_subject' => 'Di nuovo attivo: :name',
        'resolved_body' => ':url risponde di nuovo dopo :duration.',
    ],

    'filters' => [
        'period' => 'Periodo',
        'site' => 'Sito',
        'locale' => 'Lingua',
        'device' => 'Dispositivo',
        'all' => 'Tutti',
        'route' => 'Percorso',
        'page' => 'Pagina',
        'clear' => 'Mostra tutto',
    ],
    'periods' => [
        'day' => 'Per giorno, 30 giorni',
        'week' => 'Per settimana, 26 settimane',
        'month' => 'Per mese, 13 mesi',
    ],
    'devices' => [
        'mobile' => 'Telefono',
        'tablet' => 'Tablet',
        'desktop' => 'Computer',
    ],

    'sections' => [
        'server' => 'Server',
        'vitals' => 'Visitatori (Web Vitals)',
        'uptime' => 'Disponibilità',
        'routes' => 'Percorsi più lenti',
        'queries' => 'Query più lente',
        'pages' => 'Pagine più lente (LCP)',
        'incidents' => 'Incidenti recenti',
        'routes_of_query' => 'Dove viene eseguita (ultimi 7 giorni)',
    ],
    'summary' => [
        'requests' => 'Richieste misurate',
        'ttfb' => 'Tempo al primo byte',
        'errors' => 'Errori del server',
        'cache_ratio' => 'Hit della cache',
        'queries' => 'Query per richiesta',
        'memory_p95' => 'Memoria, p95',
        'mb' => 'MB',
    ],
    'charts' => [
        'ttfb' => 'Tempo al primo byte',
        'db_ms' => 'Tempo del database per richiesta',
        'query_time' => 'Tempo della query',
        'table' => 'I numeri del grafico',
        'legend' => 'p50: metà delle richieste è stata più veloce (cerchio, linea continua). p75: tre su quattro (quadrato, tratteggiata). p95: tutte tranne una su venti (triangolo, punteggiata).',
    ],
    'columns' => [
        'period' => 'Periodo',
        'count' => 'Misurato',
        'route' => 'Percorso',
        'page' => 'Pagina',
        'query' => 'Query',
        'open' => 'Apri',
        'check' => 'Indirizzo',
        'status' => 'Stato',
        'uptime' => 'Disponibilità',
        'response' => 'Risposta',
        'checked_at' => 'Controllato',
        'times' => 'Volte',
        'average' => 'Media',
        'slowest' => 'Più lento',
        'connection' => 'Connessione',
        'last_seen' => 'Visto l\'ultima volta',
        'rating' => 'Valutazione',
    ],
    'vitals' => [
        'lcp' => 'Elemento più grande visibile (LCP)',
        'inp' => 'Reattività all\'interazione (INP)',
        'cls' => 'Spostamento del layout (CLS)',
        'ttfb' => 'Tempo al primo byte (TTFB)',
        'fcp' => 'Primo contenuto visibile (FCP)',
        'thresholds' => 'Buono fino a :good, scarso oltre :poor',
    ],
    'ratings' => [
        'good' => 'Buono',
        'needs_improvement' => 'Da migliorare',
        'poor' => 'Scarso',
    ],
    'links' => [
        'errors' => 'Errori di questa pagina',
    ],
    'gadget' => [
        'title' => 'Velocità e disponibilità',
        'vitals' => 'Visitatori, p75 su 7 giorni',
        'uptime' => 'Disponibilità su 30 giorni',
        'down' => 'Non raggiungibile ora',
        'slowest' => 'Percorsi più lenti, p75',
        'open' => 'Apri il monitoraggio',
    ],
    'privacy' => 'Misurato senza cookie né dati personali; i visitatori con Do Not Track sono esclusi.',
    'back' => 'Torna al monitoraggio',
    'ongoing' => 'in corso',
    'units' => [
        'decimal' => ',',
        'ms' => ':value ms',
        's' => ':value s',
    ],
    'permissions' => [
        'view' => 'Vedere i rapporti su velocità e disponibilità',
    ],
];
