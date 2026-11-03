<?php

/*
| Esta configuración existe solo para que la migración histórica
| `2026_10_27_000006_create_permission_tables` corra en una base nueva. Nexus ya no usa
| spatie/laravel-permission (ADR-0018): los puestos son el enum `Role` y los nombramientos viven en
| `role_assignments`. La migración `2026_11_04_000002_drop_permission_tables` borra esas tablas
| justo después. Los valores son los de spatie v8 por omisión; no se agregan claves.
*/

return [

    'teams' => false,

    'testing' => false,

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => 'role_id',
        'permission_pivot_key' => 'permission_id',
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    'cache' => [
        'store' => 'default',
        'key' => 'spatie.permission.cache',
    ],

];
