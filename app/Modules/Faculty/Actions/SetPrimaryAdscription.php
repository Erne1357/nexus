<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Hace principal a una adscripción de un docente: la anterior deja de serlo, así queda una sola (índice único
 * parcial). Quién puede lo decide TeacherPolicy (`update` sobre el docente) antes de llamarla.
 *
 * En una transacción bloquea al docente y sus adscripciones; si ya es la principal no escribe, no registra ni avisa.
 * Deja bitácora de las dos adscripciones con el valor anterior, llama a SyncTeacherAccess (idempotente: los
 * campus no cambian) y, después del commit, avisa con AdscriptionChanged. Una adscripción ya quitada es un error en
 * `adscription`.
 */
final class SetPrimaryAdscription
{
    public function __construct(private readonly SyncTeacherAccess $syncTeacherAccess) {}

    /**
     * @throws ValidationException
     * @throws RuntimeException si SyncTeacherAccess tiene que crear el usuario y falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(TeacherDepartment $adscription): void
    {
        DB::transaction(function () use ($adscription): void {
            $teacher = Teacher::query()->lockForUpdate()->findOrFail($adscription->teacher_id);
            $adscriptions = TeacherDepartment::query()->lockForUpdate()->where('teacher_id', $teacher->getKey())->get();
            $new = $adscriptions->firstWhere('id', $adscription->getKey())
                ?? throw ValidationException::withMessages(['adscription' => 'Esa adscripción ya se quitó.']);

            if ($new->is_primary) {
                return;
            }

            $previous = $adscriptions->firstWhere('is_primary', true);

            if ($previous !== null) {
                $this->mark($previous, false, $teacher);
            }

            $this->mark($new, true, $teacher);

            $this->syncTeacherAccess->handle($teacher);

            AdscriptionChanged::dispatch($new);
        });
    }

    private function mark(TeacherDepartment $adscription, bool $primary, Teacher $teacher): void
    {
        $adscription->update(['is_primary' => $primary]);

        activity()
            ->performedOn($adscription)
            ->event(ActivityEvent::Updated)
            ->withChanges(['attributes' => ['is_primary' => $primary], 'old' => ['is_primary' => ! $primary]])
            ->withProperties(['teacher_id' => $teacher->getKey()])
            ->log(($primary ? 'Hizo principal' : 'Dejó de ser principal')." una adscripción del docente {$teacher->employee_number}");
    }
}
