<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\TermCreated;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Rules\TermRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un periodo. Recibe datos ya validados en forma (el componente) y hace cumplir las reglas
 * de TermRules: código `AAAAP` único y fechas en orden. Nunca lo marca como actual: eso es
 * MakeTermCurrent (S-A4, supuesto por confirmar).
 *
 * En una transacción revisa el código, escribe y deja bitácora; si otra sesión guarda el mismo código
 * entre la revisión y el insert, la restricción única de la base responde con el mismo error. Después
 * del commit avisa con TermCreated.
 */
final class CreateTerm
{
    public function __construct(private readonly TermRules $rules) {}

    /**
     * @param  array{code: string, name: string, starts_on: mixed, ends_on: mixed}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Term
    {
        $code = $this->rules->normalizeCode($data['code']);
        [$startsOn, $endsOn] = $this->rules->dates($data['starts_on'], $data['ends_on']);

        return DB::transaction(function () use ($code, $data, $startsOn, $endsOn): Term {
            $this->rules->ensureCodeIsFree($code);

            try {
                $term = Term::create([
                    'code' => $code,
                    'name' => $data['name'],
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'is_current' => false,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($code);
            }

            activity()
                ->performedOn($term)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => [
                    'code' => $term->code,
                    'name' => $term->name,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'is_current' => false,
                ]])
                ->log("Dio de alta el periodo {$term->code}");

            TermCreated::dispatch($term);

            return $term;
        });
    }
}
