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

];
