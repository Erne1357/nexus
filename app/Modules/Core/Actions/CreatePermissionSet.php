<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Rules\PermissionSetRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un rol propio (CF-01): nombre, descripción y sus permisos (CF-S2), ninguno candado (CF-03). No lo da a
 * ningún puesto: lo de alcance (CF-04) se revisa al dárselo (AssignPermissionSet). Quién puede lo decide
 * PermissionSetPolicy. Deja en la bitácora lo que creó, con sus claves.
 */
final class CreatePermissionSet
{
    public function __construct(
        private readonly PermissionSetRules $rules,
        private readonly GrantTable $grants,
    ) {}

    /**
     * @param  list<string>  $permissions  claves `modulo.recurso.accion`
     *
     * @throws ValidationException
     */
    public function handle(string $name, ?string $description, array $permissions): PermissionSet
    {
        $name = $this->rules->normalizeName($name);
        $description = $this->rules->normalizeDescription($description);
        $keys = $this->rules->permissionKeys($permissions);

        $set = DB::transaction(function () use ($name, $description, $keys): PermissionSet {
            $this->rules->ensureNameIsFree($name);

            try {
                $set = PermissionSet::query()->create(['name' => $name, 'description' => $description, 'follows_factory' => false, 'is_active' => true]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->nameTaken($name);
            }

            $this->rules->replaceKeys($set, $keys);

            activity()
                ->performedOn($set)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => ['name' => $name, 'description' => $description, 'permissions' => $keys]])
                ->log("Dio de alta el rol «{$name}»");

            return $set;
        });

        $this->grants->refresh();

        return $set;
    }
}
