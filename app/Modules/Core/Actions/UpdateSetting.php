<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Events\SettingsUpdated;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia el valor global de un parámetro (CF-S7). El valor se valida con la definición que declaró el módulo
 * (SettingDefinition::validated(): tipo, rango y, en las tablas, cada campo); uno inválido no se guarda. Un valor vacío
 * en un parámetro que admite no tenerlo equivale a volver al valor por omisión (ResetSetting).
 *
 * En una transacción relee la fila con `lockForUpdate`, la escribe y deja la bitácora con el valor anterior y el nuevo
 * (el anterior es el de la fila, o el de omisión si no había). Si el valor no cambia no escribe, no registra ni despacha
 * nada; si cambia, despacha SettingsUpdated con su llave, ya confirmada la transacción. `apply()` hace lo mismo sin
 * despachar, para que UpdateSettings avise una sola vez por tarjeta.
 */
final class UpdateSetting
{
    public function __construct(private readonly ResetSetting $reset) {}

    /**
     * @throws ValidationException
     */
    public function handle(SettingDefinition $definition, mixed $value): void
    {
        $changed = DB::transaction(fn (): bool => $this->apply($definition, $value));

        if ($changed) {
            SettingsUpdated::dispatch([$definition->key]);
        }
    }

    /**
     * Valida y escribe dentro de la transacción de quien llama, sin despachar el evento.
     *
     * @return bool si el valor cambió
     *
     * @throws ValidationException
     */
    public function apply(SettingDefinition $definition, mixed $value): bool
    {
        $value = $definition->validated($value);

        if ($value === null) {
            return $this->reset->apply($definition);
        }

        $setting = Setting::query()
            ->where('scope', SettingScope::Global)->whereNull('scope_id')->where('key', $definition->key)
            ->lockForUpdate()->first();

        $previous = $setting !== null && $definition->accepts($setting->value) ? $setting->value : $definition->default;

        if ($setting !== null && $setting->value == $value) {
            return false;
        }

        if ($setting === null && $previous !== null && $previous == $value) {
            return false;
        }

        $setting ??= new Setting(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => $definition->key]);
        $setting->value = $value;
        $setting->save();

        activity()
            ->performedOn($setting)
            ->event($setting->wasRecentlyCreated ? ActivityEvent::Created : ActivityEvent::Updated)
            ->withChanges(['attributes' => ['key' => $definition->key, 'value' => $value], 'old' => ['key' => $definition->key, 'value' => $previous]])
            ->log("Cambió el parámetro «{$definition->label}» ({$definition->key})");

        return true;
    }
}
