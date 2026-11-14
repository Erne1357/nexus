<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Rules\PermissionSetRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia el nombre, la descripción o los permisos de un rol: solo las claves que recibe (CF-S2, CF-03). Un permiso
 * que agrega debe poderse dar a cada puesto que ya tiene el rol (CF-04). Al cambiar los permisos de un rol de fábrica
 * que sigue al código, deja de seguirlo (CF-02): guarda su propia lista y, como línea base, lo que el código concede
 * hoy a su puesto (la bitácora lo registra en `follows_factory`). Una clave que el rol ya tenía y que ningún módulo
 * declara ya (la quitó un despliegue) no aporta nada: se descarta al guardar en lugar de bloquear el cambio. Quién
 * puede lo decide PermissionSetPolicy.
 *
 * Relee el rol con bloqueo; si nada cambió, no escribe ni registra. Si algo cambió, deja en la bitácora lo de antes y
 * lo de después de cada campo (las claves, en `permissions`).
 */
final class UpdatePermissionSet
{
    public function __construct(
        private readonly PermissionSetRules $rules,
        private readonly GrantTable $grants,
    ) {}

    /**
     * @param  array{name?: string, description?: string|null, permissions?: list<string>}  $data
     *
     * @throws ValidationException
     */
    public function handle(PermissionSet $set, array $data): PermissionSet
    {
        $data = Arr::only($data, ['name', 'description', 'permissions']);

        if (array_key_exists('name', $data)) {
            $data['name'] = $this->rules->normalizeName($data['name']);
        }

        if (array_key_exists('description', $data)) {
            $data['description'] = $this->rules->normalizeDescription($data['description']);
        }

        if (array_key_exists('permissions', $data)) {
            $stale = array_filter($this->rules->keysOf($set), fn (string $key): bool => ! $this->rules->isDeclared($key));
            $data['permissions'] = $this->rules->permissionKeys(array_values(array_diff($data['permissions'], $stale)));
        }

        $set = DB::transaction(function () use ($set, $data): PermissionSet {
            $set->setRawAttributes(PermissionSet::query()->lockForUpdate()->findOrFail($set->getKey())->getAttributes(), sync: true);
            $old = [...$set->only(['name', 'description']), 'permissions' => $this->rules->keysOf($set)];
            $new = [...$old, ...$data];
            $changed = array_keys(array_filter($new, fn (mixed $value, string $field): bool => $value !== $old[$field], ARRAY_FILTER_USE_BOTH));

            if ($changed === []) {
                return $set;
            }

            if (in_array('name', $changed, true)) {
                $this->rules->ensureNameIsFree($new['name'], $set);
            }

            if (in_array('permissions', $changed, true)) {
                $this->rules->ensureGrantable($this->rules->positionsOf($set), array_values(array_diff($new['permissions'], $old['permissions'])), 'permissions');

                if ($set->follows_factory) {
                    $set->forceFill(['follows_factory' => false, 'factory_baseline' => $old['permissions']]);
                    $old['follows_factory'] = true;
                    $new['follows_factory'] = false;
                    $changed[] = 'follows_factory';
                }

                $this->rules->replaceKeys($set, $new['permissions']);
            }

            $set->fill(Arr::only($new, ['name', 'description']));

            try {
                $set->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->nameTaken($new['name']);
            }

            activity()
                ->performedOn($set)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => Arr::only($new, $changed), 'old' => Arr::only($old, $changed)])
                ->log("Actualizó el rol «{$set->name}»");

            return $set;
        });

        $this->grants->refresh();

        return $set;
    }
}
