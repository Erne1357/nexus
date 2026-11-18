<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Events\SettingsUpdated;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingDefinition;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Vuelve un parámetro a su valor por omisión (CF-S7) borrando la fila global de `settings`: todo lo que lee un parámetro
 * usa el valor de omisión cuando no hay fila, y así el valor por omisión sigue siendo el que declara el código y no una copia
 * guardada. Borrar la fila es a propósito: `settings` no es un catálogo y la bitácora guarda el valor anterior. Deja bitácora con el valor anterior y el de omisión. Si no había fila, no hace nada, no registra ni despacha nada; si la había, despacha SettingsUpdated con su llave, ya confirmada la transacción (`apply()` lo hace sin despachar, para UpdateSettings).
 */
final class ResetSetting
{
    public function handle(SettingDefinition $definition): void
    {
        $changed = DB::transaction(fn (): bool => $this->apply($definition));

        if ($changed) {
            SettingsUpdated::dispatch([$definition->key]);
        }
    }

    /**
     * Borra la fila dentro de la transacción de quien llama, sin despachar el evento.
     *
     * @return bool si había fila (el valor cambió)
     */
    public function apply(SettingDefinition $definition): bool
    {
        $setting = Setting::query()
            ->where('scope', SettingScope::Global)->whereNull('scope_id')->where('key', $definition->key)
            ->lockForUpdate()->first();

        if ($setting === null) {
            return false;
        }

        $setting->delete();

        activity()
            ->performedOn($setting)
            ->event(ActivityEvent::Deleted)
            ->withChanges(['attributes' => ['key' => $definition->key, 'value' => $definition->default], 'old' => ['key' => $definition->key, 'value' => $setting->value]])
            ->log("Volvió el parámetro «{$definition->label}» ({$definition->key}) a su valor por omisión");

        return true;
    }
}
