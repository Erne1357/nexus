<?php

namespace App\Modules\Core\Support;

/**
 * El nombre en español del evento de una entrada de la bitácora (`event`, CF-S5): la clave con que se guarda
 * (`access-invited`, `teacher_assigned`) no se muestra. El mapa lleva las claves que escriben los módulos (sus
 * `->event('…')`, sus constantes `EVENT` y las de spatie); ActivityEventLabelsTest exige que no falte ninguna. Una
 * clave desconocida sale tal cual, sin guiones. Como ActivityRecordTypes, vive en Core y nombra las claves como texto,
 * sin depender de los módulos que las escriben.
 */
final class ActivityEvents
{
    /** @var array<string, string> */
    private const array LABELS = [
        'created' => 'Alta',
        'selection_created' => 'Selección creada',
        'prior_choice_recorded' => 'Elección previa registrada',
        'prior_choice_removed' => 'Elección previa quitada',
        'selection_csv_imported' => 'CSV de la selección importado',
        'selection_entry_resolved' => 'Renglón resuelto',
        'selection_entry_discarded' => 'Renglón descartado',
        'selection_scheduled' => 'Turnos programados',
        'selection_published' => 'Selección publicada',
        'selection_cancelled' => 'Selección cancelada',
        'selection_loads_set' => 'Cargas de la selección ajustadas',
        'selection_teachers_invited' => 'Docentes de la selección invitados a Nexus',
        'updated' => 'Cambio',
        'deleted' => 'Borrado',
        'restored' => 'Restaurado',
        'accepted' => 'Aceptado',
        'applied' => 'Aplicado',
        'approved' => 'Aprobado',
        'cancelled' => 'Cancelado',
        'failed' => 'Falló',
        'justified' => 'Justificado',
        'merged' => 'Unificado',
        'opened' => 'Abierto',
        'proposed' => 'Propuesto',
        'published' => 'Publicado',
        'rejected' => 'Rechazado',
        'reminded' => 'Recordatorio',
        'reopened' => 'Reabierto',
        'requested' => 'Solicitado',
        'resolved' => 'Resuelto',
        'returned' => 'Regresado',
        'reviewed' => 'Revisado',
        'unreviewed' => 'Revisado quitado',
        'submitted' => 'Enviado',
        'transitioned' => 'Cambio de estado',
        'validated' => 'Validado',
        'validation_opened' => 'Validación abierta',
        'claimed' => 'Salones reclamados',
        'released' => 'Salón soltado',
        'discharged' => 'Salida del departamento',
        'provisioned' => 'Usuario de docente',
        'deactivated' => 'Baja de la cuenta',
        'reactivated' => 'Reactivación de la cuenta',
        'access-invited' => 'Invitación de acceso',
        'position-mail-retried' => 'Correo de puesto reintentado',
        'position-mail-discarded' => 'Correo de puesto descartado',
        'access-invitation-cancelled' => 'Invitación sin efecto',
        'access-accepted' => 'Acceso activado',
        'access-revoked' => 'Acceso retirado',
        'teachers-invited' => 'Docentes invitados',
        'student-accounts-synced' => 'Cuentas de alumnos',
        'email-change-notified' => 'Aviso de cambio de correo',
        'password-changed' => 'Contraseña cambiada',
        'privacy-notice-accepted' => 'Aviso de privacidad aceptado',
        'password-change-required' => 'Cambio de contraseña pedido',
        'password-reset-link-issued' => 'Enlace de contraseña',
        'password-reset-link-failed' => 'Enlace de contraseña sin enviar',
        'password-reset-with-link' => 'Contraseña restablecida',
        'failed-job-retried' => 'Trabajo reintentado',
        'retried' => 'Reintentado',
        'failed-job-forgotten' => 'Trabajo descartado',
        'mail-account-connected' => 'Cuenta de envío conectada',
        'mail-account-disconnected' => 'Cuenta de envío desconectada',
        'mail-account-expired' => 'Cuenta de envío vencida',
        'groups_confirmed' => 'Grupos confirmados',
        'quotas_updated' => 'Cupos',
        'parallels_arranged' => 'Paralelos armados',
        'seats_changed' => 'Lugares cambiados',
        'capacity_updated' => 'Capacidad del grupo',
        'session_placed' => 'Sesión acomodada',
        'session_updated' => 'Sesión cambiada',
        'session_removed' => 'Sesión quitada',
        'session_room_assigned' => 'Salón de la sesión',
        'sessions_replaced' => 'Horario escrito',
        'room_removed_by_cancelled_loan' => 'Salón quitado: préstamo cancelado',
        'room_removed_by_rejected_loan' => 'Salón quitado: préstamo rechazado',
        'teacher_assigned' => 'Docente asignado',
        'credential_requested' => 'Credencial solicitada',
        'credential_request_cancelled' => 'Solicitud de credencial cancelada',
        'credential_request_scheduled' => 'Solicitud de credencial citada',
        'credential_request_rescheduled' => 'Cita de credencial reprogramada',
        'credential_request_ready' => 'Credencial lista para recoger',
        'credential_request_delivered' => 'Credencial entregada',
        'credential_request_rejected' => 'Solicitud de credencial rechazada',
        'teacher_unassigned' => 'Docente quitado',
        'selection_load_finished' => 'Carga terminada en la orden de selección',
        'selection_load_confirmed' => 'Carga confirmada después de la orden de selección',
        'prior_choice_hours_confirmed' => 'Horas de la elección previa confirmadas',
        'hour_allowance_granted' => 'Horas autorizadas',
        'group_history_imported' => 'Historial de grupos importado',
        'load_projection_imported' => 'Proyección de carga importada',
        'launched' => 'Lanzado',
        'cancel_requested' => 'Cancelación pedida',
        'discarded' => 'Descartado',
        'impact_assessed' => 'Impacto calculado',
        'alternatives_proposed' => 'Alternativas pedidas al motor',
        'alternative_chosen' => 'Alternativa elegida',
    ];

    public static function knows(string $event): bool
    {
        return isset(self::LABELS[$event]);
    }

    /**
     * El nombre del evento; sin mapa, la clave sin guiones, y sin evento, «—».
     */
    public static function label(?string $event): string
    {
        if ($event === null || $event === '') {
            return '—';
        }

        return self::LABELS[$event] ?? str_replace(['-', '_'], ' ', $event);
    }
}
