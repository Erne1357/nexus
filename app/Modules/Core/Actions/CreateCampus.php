<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\CampusCreated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Rules\CampusRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un campus. Recibe datos ya validados en forma (el componente) y hace cumplir las reglas
 * del campus de CampusRules: código en mayúsculas, sin espacios y único también entre los borrados. Una regla rota es
 * una ValidationException sobre su campo.
 *
 * En una transacción revisa el código, escribe y deja bitácora; si otra sesión guarda el mismo código
 * entre la revisión y el insert, la restricción única de la base responde con el mismo error de
 * código. Después del commit avisa con CampusCreated.
 *
 * El campus es solo el lugar (MS3, supuesto por confirmar): código y nombre, sin modalidad ni perfil de flujo, que son
 * del catálogo de modalidades. Nace activo.
 */
final class CreateCampus
{
    public function __construct(private readonly CampusRules $rules) {}

    /**
     * @param  array{code: string, name: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Campus
    {
        $code = $this->rules->normalizeCode($data['code']);

        return DB::transaction(function () use ($code, $data): Campus {
            $this->rules->ensureCodeIsFree($code);

            try {
                $campus = Campus::create([
                    'code' => $code,
                    'name' => $data['name'],
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($code);
            }

            activity()
                ->performedOn($campus)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $campus->only(['code', 'name', 'is_active'])])
                ->log("Dio de alta el campus {$campus->code}");

            CampusCreated::dispatch($campus);

            return $campus;
        });
    }
}
