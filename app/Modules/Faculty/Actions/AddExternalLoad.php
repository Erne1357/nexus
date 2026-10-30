<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Rules\ExternalLoadRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Agrega horas que un docente da fuera del ITCJ en un periodo (S16, supuesto por confirmar): de 1 a 40 y un origen
 * por docente y periodo, sin distinguir mayúsculas. Poner de nuevo un origen que se había quitado lo restaura. Quién
 * puede lo decide TeacherPolicy (`manageExternalLoads`) antes de llamarla.
 *
 * En una transacción bloquea al docente, escribe, deja bitácora y, después del commit, avisa con ExternalLoadChanged.
 */
final class AddExternalLoad
{
    public function __construct(private readonly ExternalLoadRules $rules) {}

    /**
     * @param  array{source?: string, hours?: int|string|null, notes?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(Teacher $teacher, Term $term, array $data): TeacherExternalLoad
    {
        $data = $this->rules->normalize($data);

        return DB::transaction(function () use ($teacher, $term, $data): TeacherExternalLoad {
            $locked = Teacher::query()->lockForUpdate()->findOrFail($teacher->getKey());

            $existing = TeacherExternalLoad::withTrashed()
                ->where('teacher_id', $locked->getKey())
                ->where('term_id', $term->getKey())
                ->whereRaw('lower(source) = ?', [mb_strtolower($data['source'])])
                ->first();

            if ($existing !== null && ! $existing->trashed()) {
                throw ValidationException::withMessages(['source' => 'Ese origen ya tiene horas en el periodo: cámbialas.']);
            }

            if ($existing === null) {
                $load = TeacherExternalLoad::create(['teacher_id' => $locked->getKey(), 'term_id' => $term->getKey(), ...$data]);
            } else {
                $load = $existing;
                $load->forceFill($data);
                $load->deleted_at = null;
                $load->save();
            }

            activity()
                ->performedOn($load)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $this->rules->logged($load)])
                ->withProperties(['teacher_id' => $locked->getKey(), 'term_id' => $term->getKey()])
                ->log("Agregó {$data['hours']} h fuera del ITCJ ({$data['source']}) al docente {$locked->employee_number}");

            ExternalLoadChanged::dispatch($load);

            return $load;
        });
    }
}
