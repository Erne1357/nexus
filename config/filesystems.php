<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Archivos del SII subidos para importar (módulo Imports). Traen datos personales: viven fuera
        // de git (storage/app/.gitignore) y nunca se sirven por HTTP (CLAUDE.md, regla dura 9).
        'imports' => [
            'driver' => 'local',
            'root' => storage_path('app/imports'),
            'throw' => true,
            'report' => false,
        ],

        // Fotos de los alumnos para la credencial (Students, tarea 1.27, AC-20): una carpeta por año de ingreso,
        // `<AAAA>/<número de control>.jpg` (p. ej. `2021/21111182.jpg`). Datos personales: fuera de git
        // (storage/app/.gitignore), fuera de public/ y fuera de la raíz del disco `local` (que sirve archivos);
        // se sirven solo por rutas que autorizan. En el servidor,
        // STUDENT_PHOTOS_PATH apunta al directorio montado (compose.prod.yaml).
        'student_photos' => [
            'driver' => 'local',
            'root' => env('STUDENT_PHOTOS_PATH', storage_path('app/fotos')),
            // Nexus lee y, desde la tarea 1.37, también escribe (CO-06): el directorio ya existe con dueño uid 1000 y no se
            // crea aquí; si no existe, no hay fotos. Un fallo de escritura no lanza aquí: StudentPhotos revisa cada resultado
            // y lanza PhotoNotSaved, para que una lectura de una carpeta ausente siga siendo «sin foto».
            'lazy_root_creation' => true,
            'throw' => false,
            'report' => false,
        ],

        // Fotos del personal (Core, tarea 1.35, CO-03): una carpeta por usuario con la foto normal, su miniatura y el
        // original; las anteriores se conservan. Datos personales: fuera de git (storage/app/.gitignore), de public/ y de
        // la raíz del disco `local`; se sirven solo por la ruta firmada `core.staff-photo`. En el servidor,
        // STAFF_PHOTOS_PATH apunta al directorio montado con escritura (compose.prod.yaml).
        'staff_photos' => [
            'driver' => 'local',
            'root' => env('STAFF_PHOTOS_PATH', storage_path('app/fotos-personal')),
            'throw' => false,
            'report' => false,
        ],

        // Exportes de lo publicado (AcademicLoad, tareas 4.5 y 4.6): PDF, Excel y archivos para el SII que arma la
        // cola. Fuera de git y sin URL pública: se descargan por una ruta que revisa el permiso de cada exporte.
        'exports' => [
            'driver' => 'local',
            'root' => storage_path('app/exports'),
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
