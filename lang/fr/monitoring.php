<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'title' => 'Supervision',
    'menu' => 'Vitesse et disponibilité',
    'health' => 'État du site',
    'no_data' => 'Aucune donnée pour l\'instant',
    'minutes' => ':count minute|:count minutes',

    'modules' => [
        'checks' => 'Vérifications de disponibilité',
        'incidents' => 'Incidents',
    ],
    'create' => [
        'check' => 'Ajouter une vérification',
    ],
    'fields' => [
        'name' => 'Nom',
        'url' => 'Adresse',
        'status' => 'État',
        'last_error' => 'Dernière erreur',
        'response_ms' => 'Réponse',
        'checked_at' => 'Vérifié',
        'check' => 'Vérification',
        'started_at' => 'Début',
        'resolved_at' => 'Fin',
        'duration' => 'Durée',
        'failures' => 'Vérifications échouées',
    ],
    'hints' => [
        'url' => 'Ouverte chaque minute. La page d\'accueil de chaque site est vérifiée d\'office.',
    ],
    'status' => [
        'unknown' => 'Pas encore vérifié',
        'up' => 'En ligne',
        'failing' => 'Instable',
        'down' => 'Hors service',
    ],

    'settings' => [
        'description' => 'Qui prévenir lorsqu\'un site ne répond plus, et après combien de vérifications échouées d\'affilée.',
        'failures' => 'Vérifications échouées avant un incident',
        'failures_hint' => 'Une vérification par minute : 3 signifie trois minutes sans réponse.',
        'alert_emails' => 'E-mails d\'alerte',
        'alert_emails_hint' => 'Une ou plusieurs adresses, séparées par des virgules ou des retours à la ligne.',
        'telegram_token' => 'Jeton du bot Telegram',
        'telegram_token_hint' => 'Fourni par @BotFather. Conservé en base de données, jamais dans le code ; ATRIUM_MONITORING_TELEGRAM_TOKEN dans .env a la priorité.',
        'telegram_chat_id' => 'Conversation Telegram',
        'telegram_chat_id_hint' => 'Un identifiant de conversation comme -1001234567890 ou un canal comme @site_alerts. Le bot doit en être membre.',
    ],

    'alert' => [
        'opened_subject' => 'Hors service : :name',
        'opened_body' => ":url ne répond plus depuis :since, après :failures vérifications consécutives.\nDernière erreur : :error",
        'resolved_subject' => 'De nouveau en ligne : :name',
        'resolved_body' => ':url répond de nouveau après :duration.',
    ],

    'filters' => [
        'period' => 'Période',
        'site' => 'Site',
        'locale' => 'Langue',
        'device' => 'Appareil',
        'all' => 'Tous',
        'route' => 'Route',
        'page' => 'Page',
        'clear' => 'Tout afficher',
    ],
    'periods' => [
        'day' => 'Par jour, 30 jours',
        'week' => 'Par semaine, 26 semaines',
        'month' => 'Par mois, 13 mois',
    ],
    'devices' => [
        'mobile' => 'Téléphone',
        'tablet' => 'Tablette',
        'desktop' => 'Ordinateur',
    ],

    'sections' => [
        'server' => 'Serveur',
        'vitals' => 'Visiteurs (Web Vitals)',
        'uptime' => 'Disponibilité',
        'routes' => 'Routes les plus lentes',
        'queries' => 'Requêtes les plus lentes',
        'pages' => 'Pages les plus lentes (LCP)',
        'incidents' => 'Incidents récents',
        'routes_of_query' => 'Où elle s\'exécute (7 derniers jours)',
    ],
    'summary' => [
        'requests' => 'Requêtes mesurées',
        'ttfb' => 'Temps jusqu\'au premier octet',
        'errors' => 'Erreurs serveur',
        'cache_ratio' => 'Succès du cache',
        'queries' => 'Requêtes SQL par requête',
        'memory_p95' => 'Mémoire, p95',
        'mb' => 'Mo',
    ],
    'charts' => [
        'ttfb' => 'Temps jusqu\'au premier octet',
        'db_ms' => 'Temps de base de données par requête',
        'query_time' => 'Durée de la requête',
        'table' => 'Les chiffres du graphique',
        'legend' => 'p50 : la moitié des requêtes était plus rapide (cercle, trait plein). p75 : trois sur quatre (carré, tirets). p95 : toutes sauf une sur vingt (triangle, pointillés).',
    ],
    'columns' => [
        'period' => 'Période',
        'count' => 'Mesures',
        'route' => 'Route',
        'page' => 'Page',
        'query' => 'Requête',
        'open' => 'Ouvrir',
        'check' => 'Adresse',
        'status' => 'État',
        'uptime' => 'Disponibilité',
        'response' => 'Réponse',
        'checked_at' => 'Vérifié',
        'times' => 'Fois',
        'average' => 'Moyenne',
        'slowest' => 'Le plus lent',
        'connection' => 'Connexion',
        'last_seen' => 'Dernière fois',
        'rating' => 'Évaluation',
    ],
    'vitals' => [
        'lcp' => 'Affichage du plus grand élément (LCP)',
        'inp' => 'Réactivité à l\'interaction (INP)',
        'cls' => 'Décalage de mise en page (CLS)',
        'ttfb' => 'Temps jusqu\'au premier octet (TTFB)',
        'fcp' => 'Premier affichage (FCP)',
        'thresholds' => 'Bon jusqu\'à :good, mauvais au-delà de :poor',
    ],
    'ratings' => [
        'good' => 'Bon',
        'needs_improvement' => 'À améliorer',
        'poor' => 'Mauvais',
    ],
    'links' => [
        'errors' => 'Erreurs de cette page',
    ],
    'gadget' => [
        'title' => 'Vitesse et disponibilité',
        'vitals' => 'Visiteurs, p75 sur 7 jours',
        'uptime' => 'Disponibilité sur 30 jours',
        'down' => 'Hors service actuellement',
        'slowest' => 'Routes les plus lentes, p75',
        'open' => 'Ouvrir la supervision',
    ],
    'privacy' => 'Mesuré sans cookies ni données personnelles ; les visiteurs avec Do Not Track sont exclus.',
    'back' => 'Retour à la supervision',
    'ongoing' => 'en cours',
    'units' => [
        'decimal' => ',',
        'ms' => ':value ms',
        's' => ':value s',
    ],
    'permissions' => [
        'view' => 'Voir les rapports de vitesse et de disponibilité',
    ],
];
