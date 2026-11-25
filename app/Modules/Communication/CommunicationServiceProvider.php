<?php

namespace App\Modules\Communication;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;

/**
 * Módulo Communication: el espacio de trabajo del Departamento de Comunicación y Difusión (CO-01, tarea 1.34). Tiene sus
 * pantallas y su área del menú; los datos que maneja viven en su dueño (las fotos del personal en Core, las de los
 * alumnos y sus solicitudes de credencial en Students), y escribe con sus Actions. Va a la derecha de Students en el
 * mapa de dependencias (docs/dominio/modelo-de-datos.md).
 *
 * Sus permisos son de Core y aplican a la unidad COMUNICACION (CorePermission::appliesTo()); por eso no declara los suyos.
 */
final class CommunicationServiceProvider extends ModuleServiceProvider
{
    public function navigation(): array
    {
        return [
            new NavigationItem('Personal', 'communication.staff', 'o-user-plus', NavigationArea::Comunicacion,
                visible: static fn (Actor $context): bool => $context->allowsAny(CorePermission::UnitStaffAppoint)),
            // La misma pantalla que «Avisos y atajos» de Configuración, que ve quien administra los puestos y permisos (el
            // admin): aquí, para los demás que la manejan, la jefa y el personal de Comunicación y Difusión.
            new NavigationItem('Avisos y atajos', 'core.login-content', 'o-megaphone', NavigationArea::Comunicacion,
                visible: static fn (Actor $context): bool => ! $context->allowsAny(CorePermission::AuthzView)
                    && ($context->allowsAny(CorePermission::LoginNoticeView) || $context->allowsAny(CorePermission::LoginShortcutView))),
        ];
    }
}
