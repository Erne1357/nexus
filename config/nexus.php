<?php

/*
| Ajustes propios de Nexus que no son de un paquete.
*/

return [

    /*
    | Dominio del correo institucional de los docentes (AC-06): solo un correo de este dominio recibe la invitación de
    | acceso. Supuesto por confirmar: solo `cdjuarez.tecnm.mx`; `itcj.edu.mx` no cuenta.
    */
    'institutional_email_domain' => env('NEXUS_INSTITUTIONAL_EMAIL_DOMAIN', 'cdjuarez.tecnm.mx'),

    /*
    | Visor 3D del mapa del campus en el Inicio (1.24.1, ADR-0030). Con false la página no carga los assets de ubicatec
    | (three.js) ni arranca el visor: muestra un marcador estático. phpunit.xml lo apaga para que las pruebas de navegador
    | que solo pasan por el Inicio no paguen WebGL por CPU.
    */
    'campus_map_3d' => (bool) env('CAMPUS_MAP_3D', true),

    /*
    | La dirección pública con la que el QR de la credencial arma su enlace de verificación (AC-20). Vacía, se usa APP_URL
    | (en producción https://nexus.cdjuarez.tecnm.mx). Sirve para una demo detrás de un túnel cuyo APP_URL es local.
    */
    'credential_url' => env('NEXUS_CREDENTIAL_URL'),

];
