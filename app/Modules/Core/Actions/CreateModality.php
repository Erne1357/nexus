<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\ModalityCreated;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Rules\ModalityRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta una modalidad (MS1, supuesto por confirmar). Recibe datos ya validados en forma (el componente) y hace
 * cumplir las reglas de ModalityRules: clave en mayúsculas, sin espacios y única también entre las borradas; perfil de
 * flujo que ModalityProfile acepte, guardado completo y normalizado. Una regla rota es una ValidationException sobre su
 * campo. Nace activa (MS24, supuesto por confirmar): el admin la desactiva después si no quiere que se use todavía.
 *
 * En una transacción revisa la clave, escribe y deja bitácora; si otra sesión guarda la misma clave entre la revisión y
 * el insert, la restricción única de la base responde con el mismo error. Después del commit avisa con ModalityCreated.
 */
final class CreateModality
{
    public function __construct(private readonly ModalityRules $rules) {}

    /**
     * @param  array{code: string, name: string, settings: array<string, mixed>}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Modality
    {
        $code = $this->rules->normalizeCode($data['code']);
        $settings = $this->rules->profile($data['settings']);

        return DB::transaction(function () use ($code, $data, $settings): Modality {
            $this->rules->ensureCodeIsFree($code);

            try {
                $modality = Modality::create([
                    'code' => $code,
                    'name' => $data['name'],
                    'settings' => $settings,
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($code);
            }

            activity()
                ->performedOn($modality)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $modality->only(['code', 'name', 'settings', 'is_active'])])
                ->log("Dio de alta la modalidad {$modality->code}");

            ModalityCreated::dispatch($modality);

            return $modality;
        });
    }
}
