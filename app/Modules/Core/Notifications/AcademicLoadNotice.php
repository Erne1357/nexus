<?php

namespace App\Modules\Core\Notifications;

/**
 * Marca de un aviso de la carga académica (MN-05): lo implementan las notificaciones de los módulos de la carga que
 * salen por correo. Mientras el admin tiene oculta la carga, Core no las manda por correo (HoldAcademicLoadMail),
 * porque su enlace llevaría a una pantalla que no abre; la campana las guarda igual y las muestra al volver a mostrarla.
 * Core no conoce los módulos de la derecha: ellos se marcan.
 */
interface AcademicLoadNotice {}
