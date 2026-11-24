<?php

/*
| El `/health` profundo de Core (tarea 1.31). `token` abre el detalle (mensajes de error, nombres de migraciones, tamaños
| de colas) con la cabecera `X-Health-Token`; sin él solo salen los estados. `database_connection` y `cache_store` son
| null (los de siempre) salvo en las pruebas de fallos.
*/
return [
    'token' => env('HEALTH_TOKEN'),
    'release' => env('NEXUS_RELEASE', 'dev'),
    'probe_header' => 'X-Health-Probe',
    'drain_file' => env('NEXUS_DRAIN_FILE', '/tmp/nexus-draining'),
    'heartbeat_stale_seconds' => 180,
    'database_connection' => null,
    'cache_store' => null,
    'solver_timeout' => 1.0,
];
