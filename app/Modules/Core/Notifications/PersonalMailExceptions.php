<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\ModuleServiceProvider;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * Las excepciones declaradas a CE-01 (confirmado el 26 de noviembre de 2026): los únicos avisos que pueden ir a un correo
 * personal, por el nombre completo de su clase, cada uno con la regla que lo permite. Cada módulo declara las suyas en
 * personalMailExceptions() de su provider (Core no conoce los de la derecha) y Core las junta aquí al arrancar. Hoy son
 * la invitación de acceso de la cola de una orden de selección y su reenvío (Core, `AccessInvitation` => SEL-13,
 * cuando su llamada lo dice) y los avisos de la orden de selección al docente, incluido «Confirma tus horas» (Staffing,
 * `SelectionTeacherNotice` => SEL-15 y SEL-16, también sus hijas). La prueba de arquitectura PersonalMailExceptionsTest
 * fija la lista completa y que ninguna otra clase implemente AllowsPersonalMail. Inmutable: se arma una vez y no guarda
 * nada de la petición (Octane).
 */
final readonly class PersonalMailExceptions
{
    /**
     * @param  array<class-string, string>  $declared
     */
    public function __construct(private array $declared) {}

    /**
     * @param  iterable<ModuleServiceProvider>  $providers
     *
     * @throws LogicException si una clase no implementa AllowsPersonalMail, no da regla o la declaran dos módulos
     */
    public static function fromModules(iterable $providers): self
    {
        $declared = [];

        foreach ($providers as $provider) {
            foreach ($provider->personalMailExceptions() as $class => $rule) {
                if (! is_subclass_of($class, AllowsPersonalMail::class) || trim($rule) === '' || isset($declared[$class])) {
                    throw new LogicException("La excepción de correo personal de {$class} no es válida (CE-01).");
                }

                $declared[$class] = $rule;
            }
        }

        return new self($declared);
    }

    /**
     * @return array<class-string, string>
     */
    public function declared(): array
    {
        return $this->declared;
    }

    /**
     * La regla por la que `$notification` puede ir a un correo personal, o null: tiene que implementar AllowsPersonalMail,
     * su clase o una de sus padres tiene que estar declarada y la regla que da su instancia tiene que ser la declarada.
     */
    public function ruleFor(Notification $notification): ?string
    {
        if (! $notification instanceof AllowsPersonalMail || ($rule = $notification->personalMailRule()) === null) {
            return null;
        }

        foreach ([$notification::class, ...array_values(class_parents($notification))] as $class) {
            if (($this->declared[$class] ?? null) === $rule) {
                return $rule;
            }
        }

        return null;
    }
}
