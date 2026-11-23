<?php

namespace App\Modules\Core\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\ReadsByAction;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Core: campus, modalidades, periodos, nombramientos, organigrama, usuarios y su acceso, y el panel de
 * configuración (1.22: roles y permisos, bitácora, avisos y atajos del login, parámetros, correos y colas). La clave es
 * `core.<recurso>.<accion>`. Los de usuarios, roles y permisos, nombramientos, organigrama, bitácora, correos y colas, y los
 * del interruptor de la carga académica (MN-05), son candados (CF-03): solo los tiene el admin y no se dan a otro puesto,
 * salvo ver personas y puestos, el organigrama y la bitácora, que el directivo tiene de fábrica (AZ-03). Los de avisos,
 * atajos y parámetros sí se pueden dar.
 */
enum CorePermission: string implements Permission
{
    use ReadsByAction;

    case CampusView = 'core.campus.view';
    case CampusViewInactive = 'core.campus.view_inactive';
    case CampusCreate = 'core.campus.create';
    case CampusUpdate = 'core.campus.update';
    case ModalityView = 'core.modality.view';
    case ModalityViewInactive = 'core.modality.view_inactive';
    case ModalityCreate = 'core.modality.create';
    case ModalityUpdate = 'core.modality.update';
    case ModalityUpdateProfile = 'core.modality.update_profile';
    case TermView = 'core.term.view';
    case TermCreate = 'core.term.create';
    case TermUpdate = 'core.term.update';
    case TermMakeCurrent = 'core.term.make_current';
    case TermManageCalendar = 'core.term.manage_calendar';
    case TermOpen = 'core.term.open';
    case AssignmentView = 'core.assignment.view';
    case AssignmentAppoint = 'core.assignment.appoint';
    case AssignmentEnd = 'core.assignment.end';
    case OrgUnitView = 'core.org_unit.view';
    case OrgUnitCreate = 'core.org_unit.create';
    case OrgUnitUpdate = 'core.org_unit.update';
    case OrgUnitMove = 'core.org_unit.move';
    case OrgUnitDeactivate = 'core.org_unit.deactivate';
    case UserCreate = 'core.user.create';
    case UserUpdate = 'core.user.update';
    case UserDeactivate = 'core.user.deactivate';
    case UserReactivate = 'core.user.reactivate';
    case UserResetPassword = 'core.user.reset_password';
    case UserInvite = 'core.user.invite';
    case AuthzView = 'core.authz.view';
    case AuthzManage = 'core.authz.manage';
    case ActivityView = 'core.activity.view';
    case LoginNoticeView = 'core.login_notice.view';
    case LoginNoticeManage = 'core.login_notice.manage';
    case LoginShortcutView = 'core.login_shortcut.view';
    case LoginShortcutManage = 'core.login_shortcut.manage';
    case SettingView = 'core.setting.view';
    case SettingUpdate = 'core.setting.update';
    case MailView = 'core.mail.view';
    case MailConnect = 'core.mail.connect';
    case QueueView = 'core.queue.view';
    case QueueManage = 'core.queue.manage';
    case NavigationViewUnavailable = 'core.navigation.view_unavailable';
    case AcademicLoadToggle = 'core.academic_load.toggle';
    case AcademicLoadViewHidden = 'core.academic_load.view_hidden';

    public function label(): string
    {
        return match ($this) {
            self::CampusView => 'Ver campus',
            self::CampusViewInactive => 'Ver campus inactivos',
            self::CampusCreate => 'Dar de alta campus',
            self::CampusUpdate => 'Editar datos y estado de un campus',
            self::ModalityView => 'Ver modalidades',
            self::ModalityViewInactive => 'Ver modalidades inactivas',
            self::ModalityCreate => 'Dar de alta modalidades',
            self::ModalityUpdate => 'Editar datos y estado de una modalidad',
            self::ModalityUpdateProfile => 'Editar el perfil de flujo de una modalidad',
            self::TermView => 'Ver periodos',
            self::TermCreate => 'Dar de alta periodos',
            self::TermUpdate => 'Editar periodos',
            self::TermMakeCurrent => 'Marcar el periodo actual',
            self::TermManageCalendar => 'Manejar el calendario del periodo',
            self::TermOpen => 'Abrir el periodo en una modalidad',
            self::AssignmentView => 'Ver personas y sus puestos',
            self::AssignmentAppoint => 'Nombrar puestos',
            self::AssignmentEnd => 'Terminar nombramientos',
            self::OrgUnitView => 'Ver el organigrama',
            self::OrgUnitCreate => 'Dar de alta unidades del organigrama',
            self::OrgUnitUpdate => 'Editar unidades del organigrama',
            self::OrgUnitMove => 'Mover unidades del organigrama',
            self::OrgUnitDeactivate => 'Desactivar y activar unidades del organigrama',
            self::UserCreate => 'Dar de alta usuarios del personal',
            self::UserUpdate => 'Editar el nombre y el correo de un usuario',
            self::UserDeactivate => 'Dar de baja la cuenta de un usuario',
            self::UserReactivate => 'Reactivar la cuenta de un usuario',
            self::UserResetPassword => 'Mandar el enlace para restablecer la contraseña',
            self::UserInvite => 'Invitar a un usuario a activar su acceso',
            self::AuthzView => 'Ver los roles y los permisos de los puestos',
            self::AuthzManage => 'Editar los roles y los permisos de los puestos',
            self::ActivityView => 'Ver la bitácora',
            self::LoginNoticeView => 'Ver los avisos de la pantalla de acceso',
            self::LoginNoticeManage => 'Manejar los avisos de la pantalla de acceso',
            self::LoginShortcutView => 'Ver los atajos de la pantalla de acceso',
            self::LoginShortcutManage => 'Manejar los atajos de la pantalla de acceso',
            self::SettingView => 'Ver los parámetros',
            self::SettingUpdate => 'Cambiar los parámetros',
            self::MailView => 'Ver el registro de correos y las invitaciones pendientes',
            self::MailConnect => 'Conectar, desconectar y probar la cuenta de envío del correo',
            self::QueueView => 'Ver las colas y los trabajos fallidos',
            self::QueueManage => 'Reintentar y descartar trabajos fallidos',
            self::NavigationViewUnavailable => 'Ver en el menú las pantallas no disponibles en el periodo (MN-02)',
            self::AcademicLoadToggle => 'Mostrar u ocultar la carga académica a los demás (MN-05)',
            self::AcademicLoadViewHidden => 'Ver la carga académica cuando está oculta (MN-05)',
        };
    }

    public function dimensions(): array
    {
        return match ($this) {
            self::CampusView => [Dimension::Campus],
            self::ModalityView, self::ModalityUpdateProfile => [Dimension::Modality],
            self::TermOpen => [Dimension::Modality],
            default => [],
        };
    }

    public function isPersonal(): bool
    {
        return false;
    }

    /**
     * A cualquier unidad: ningún permiso del módulo depende de la unidad del puesto (AZ-S16).
     */
    public function appliesTo(): ?UnitApplicability
    {
        return null;
    }
}
