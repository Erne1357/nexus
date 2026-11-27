<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\StaffType;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone al día a una persona del personal por su RFC (R-V2-3, confirmado el 19 de noviembre de 2026): busca su usuario
 * por `username`; si no existe lo crea con CreateStaffUser, sin acceso ni puesto, con el correo solo si ningún otro
 * usuario lo tiene (sin importar mayúsculas); y le pone su tipo de personal. Un usuario que ya existe conserva nombre,
 * correo, acceso y puestos: solo cambia `staff_type`, y un tipo nulo no borra el que ya tiene. Es idempotente: la
 * bitácora solo recibe lo que cambió (el alta, o el cambio de tipo con su valor anterior y nuevo). Quién puede lo
 * deciden las Actions y los comandos que la llaman.
 */
final class SyncStaffMember
{
    public function __construct(private readonly CreateStaffUser $createStaffUser) {}

    /**
     * @throws ValidationException solo si el alta falla por algo que ni sin correo se resuelve
     */
    public function handle(string $rfc, string $name, ?string $email, ?StaffType $type): User
    {
        return DB::transaction(function () use ($rfc, $name, $email, $type): User {
            $user = User::query()->where('username', $rfc)->lockForUpdate()->first() ?? $this->create($rfc, $name, $email);

            if ($type !== null && $user->staff_type !== $type) {
                $old = $user->staff_type?->value;
                $user->forceFill(['staff_type' => $type])->save();

                activity()
                    ->performedOn($user)
                    ->event(ActivityEvent::Updated)
                    ->withChanges(['attributes' => ['staff_type' => $type->value], 'old' => ['staff_type' => $old]])
                    ->log("Puso el tipo de personal {$type->label()} al usuario {$user->username}");
            }

            return $user;
        });
    }

    /**
     * Crea al usuario tolerando a otra sesión (la cola de Faculty) que lo da de alta entre la revisión y el insert:
     * si el username ya existe lo lee y sigue; si falla por otra cosa (el correo), lo crea sin correo.
     */
    private function create(string $rfc, string $name, ?string $email): User
    {
        $email = $this->freeEmail($email);

        try {
            return $this->createStaffUser->handle($rfc, $name, $email);
        } catch (ValidationException $exception) {
            $taken = User::query()->where('username', $rfc)->first();

            if ($taken !== null) {
                return $taken;
            }

            if ($email === null) {
                throw $exception;
            }

            return $this->createStaffUser->handle($rfc, $name, null);
        }
    }

    private function freeEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $email = mb_strtolower(trim($email));

        return User::query()->whereRaw('lower(email) = ?', [$email])->exists() ? null : $email;
    }
}
