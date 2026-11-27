<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | La app de Azure (Microsoft Entra) con que Nexus manda el correo por Microsoft Graph con permisos delegados (tarea
    | 1.29, ADR-0032; `MAIL_MAILER=microsoft-graph`). Salen de App registrations → la app: «Directory (tenant) ID»,
    | «Application (client) ID» y un client secret de «Certificates & secrets». La cuenta de envío no va aquí: el admin
    | la conecta en Configuración → Correos y colas. Guía: docs/operacion/despliegue.md, «Correo saliente (Microsoft 365)».
    */
    'microsoft_graph' => [
        'tenant_id' => env('MICROSOFT_GRAPH_TENANT_ID'),
        'client_id' => env('MICROSOFT_GRAPH_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_GRAPH_CLIENT_SECRET'),
    ],

    /*
    | El servicio del motor de horarios en Python (fase 7; services/solver/README.md), que llama CpSatEngine. Sin URL o
    | sin token, el motor `cpsat` no se registra. Los tiempos son por petición, en segundos: `timeout` para el envío,
    | que lleva la entrada grande, y `poll_timeout` para consultar o cancelar una corrida; `poll_interval_ms`, cada
    | cuánto se consulta una corrida en curso. Los tres caben en el margen del job (CpSatEngine::DEADLINE_MARGIN_SECONDS).
    */
    'solver' => [
        'url' => env('SOLVER_URL'),
        'token' => env('SOLVER_TOKEN'),
        'connect_timeout' => (float) env('SOLVER_CONNECT_TIMEOUT', 3),
        'timeout' => (float) env('SOLVER_TIMEOUT', 30),
        'poll_timeout' => (float) env('SOLVER_POLL_TIMEOUT', 5),
        'poll_interval_ms' => (int) env('SOLVER_POLL_INTERVAL_MS', 1000),
    ],

];
