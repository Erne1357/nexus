<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Settings\SettingDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guarda de una vez los parámetros de una tarjeta de «Parámetros» (CF-S7). Primero valida todos con su definición y, si alguno
 * es inválido, no guarda ninguno: los errores salen juntos y su llave es la del parámetro con `__` en lugar de punto
 * (`staffing__weights.apto`). Después los escribe en una sola transacción con UpdateSetting, que deja la bitácora de cada
 * parámetro que cambió.
 */
final class UpdateSettings
{
    public function __construct(private readonly UpdateSetting $update) {}

    /**
     * @param  list<SettingDefinition>  $definitions
     * @param  array<string, mixed>  $values  llave de parámetro => valor
     *
     * @throws ValidationException
     */
    public function handle(array $definitions, array $values): void
    {
        $errors = [];

        foreach ($definitions as $definition) {
            try {
                $definition->validated($values[$definition->key] ?? null);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $errors[str_replace('.', '__', $definition->key).substr($field, strlen('value'))] = $messages;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($definitions, $values): void {
            foreach ($definitions as $definition) {
                $this->update->handle($definition, $values[$definition->key] ?? null);
            }
        });
    }
}
