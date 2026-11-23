<?php

namespace App\Modules\Core\Support;

/**
 * El nombre en español del tipo de un correo del registro (`mail_log.kind`, CF-S8): el registro guarda el nombre corto
 * de la clase de la notificación o del mailable, que no se muestra. El mapa lleva las notificaciones de los módulos que
 * salen por correo (las que tienen `toMail()`); MailKindLabelsTest exige que no falte ninguna. Un tipo desconocido sale
 * tal cual. Como ActivityRecordTypes, nombra las clases como texto, sin depender de los módulos que las declaran.
 */
final class MailKinds
{
    /** @var array<string, string> */
    private const array LABELS = [
        'AccessInvitation' => 'Invitación de acceso',
        'PasswordResetLink' => 'Restablecer contraseña',
        'UserEmailChanged' => 'Aviso de cambio de correo',
        'MailAccountTest' => 'Correo de prueba de la cuenta de envío',
        'CatalogReviewsPending' => 'Validación de catálogos pendiente',
        'LoadRepublished' => 'Carga republicada',
        'ChangeAlternativeMovesCareerGroup' => 'Grupo movido por una solicitud de cambio',
        'ChangeRequestMovedTeacherGroups' => 'Cambió el horario de tus grupos',
    ];

    public static function knows(string $kind): bool
    {
        return isset(self::LABELS[$kind]);
    }

    public static function label(string $kind): string
    {
        return self::LABELS[$kind] ?? $kind;
    }
}
