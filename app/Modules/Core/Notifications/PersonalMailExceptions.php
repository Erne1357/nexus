<?php

namespace App\Modules\Core\Notifications;

use Illuminate\Notifications\Notification;

/**
 * La lista explícita de las excepciones a CE-01 (confirmado el 26 de noviembre de 2026): los únicos avisos que pueden ir
 * a un correo personal, cada uno con la regla que lo permite. Hoy, la invitación de acceso de la cola de una orden de
 * selección y su reenvío (SEL-13; AccessInvitation, cuando su llamada lo dice) y los avisos de la orden de selección al
 * docente, incluido «Confirma tus horas» (SEL-15 y SEL-16; los que heredan de SelectionTeacherNotice). Nombra las clases
 * por su nombre corto, como MailKinds, sin depender de los módulos que las declaran; la prueba de arquitectura
 * (PersonalMailExceptionsTest) revisa que sean esas clases y que ninguna otra implemente AllowsPersonalMail. Una
 * excepción nueva es una regla nueva: se declara aquí, en reglas-de-negocio.md y en esa prueba.
 */
final class PersonalMailExceptions
{
    /** @var array<string, string> nombre corto de la clase => id de la regla que la permite */
    public const array DECLARED = [
        'AccessInvitation' => 'SEL-13',
        'SelectionTeacherNotice' => 'SEL-15',
    ];

    /**
     * La regla por la que `$notification` puede ir a un correo personal, o null: tiene que implementar AllowsPersonalMail,
     * su clase o una de sus padres tiene que estar en la lista y la regla que da su instancia tiene que ser la declarada.
     */
    public static function ruleFor(Notification $notification): ?string
    {
        if (! $notification instanceof AllowsPersonalMail || ($rule = $notification->personalMailRule()) === null) {
            return null;
        }

        foreach ([$notification::class, ...array_values(class_parents($notification))] as $class) {
            if ((self::DECLARED[class_basename($class)] ?? null) === $rule) {
                return $rule;
            }
        }

        return null;
    }
}
