<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Rules\TermRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guarda de una vez las ventanas de una etapa del periodo, las que edita su panel: la general y las de varios
 * campus. `$general` es `['starts_on' => ..., 'ends_on' => ...]`, o null para quitar la ventana general; `$own`
 * va de id de campus a su ventana, o a null para quitar la de ese campus. Los campus que no vienen en `$own` no se
 * tocan.
 *
 * Primero revisa las fechas de todas las ventanas (TermRules) y junta los errores; luego escribe todo en UNA
 * transacción, con SetTermStage y RemoveTermStage dentro (sus transacciones son puntos de guardado y su aviso
 * TermCalendarChanged sale hasta el commit de esta). Si una falla, no queda guardado nada ni sale ningún aviso.
 *
 * Los errores llegan con el nombre del campo del panel: `general.starts_on`, `own.{campus}.ends_on`; el error del
 * campus cae en su fecha de inicio.
 */
final class SaveStageWindows
{
    public function __construct(
        private readonly TermRules $rules,
        private readonly SetTermStage $setStage,
        private readonly RemoveTermStage $removeStage,
    ) {}

    /**
     * @param  array{starts_on: mixed, ends_on: mixed}|null  $general
     * @param  array<string, array{starts_on: mixed, ends_on: mixed}|null>  $own
     *
     * @throws ValidationException
     */
    public function handle(Term $term, Stage $stage, ?array $general, array $own): void
    {
        $windows = ['general' => $general];

        foreach ($own as $campusId => $window) {
            $windows["own.{$campusId}"] = $window;
        }

        $dates = [];
        $errors = [];

        foreach ($windows as $path => $window) {
            if ($window === null) {
                continue;
            }

            try {
                $dates[$path] = $this->rules->dates($window['starts_on'] ?? null, $window['ends_on'] ?? null);
            } catch (ValidationException $e) {
                $errors = [...$errors, ...$this->under($path, $e)];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($term, $stage, $windows, $dates): void {
            $stored = TermStage::query()->where('term_id', $term->getKey())->where('stage', $stage->value)->get();

            foreach ($windows as $path => $window) {
                $campusId = $path === 'general' ? null : substr($path, 4);
                $existing = $stored->first(fn (TermStage $candidate): bool => $candidate->campus_id === $campusId);

                if ($window === null) {
                    if ($existing !== null) {
                        $this->removeStage->handle($existing);
                    }

                    continue;
                }

                $campus = $campusId === null ? null : Campus::query()->find($campusId);

                if ($campusId !== null && $campus === null) {
                    throw ValidationException::withMessages(["{$path}.starts_on" => 'El campus debe estar activo.']);
                }

                try {
                    $this->setStage->handle($term, $stage, $campus, ...$dates[$path]);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages($this->under($path, $e));
                }
            }
        });
    }

    /**
     * @return array<string, list<string>>
     */
    private function under(string $path, ValidationException $e): array
    {
        $errors = [];

        foreach ($e->errors() as $field => $messages) {
            $field = $field === 'campus_id' ? 'starts_on' : $field;
            $errors["{$path}.{$field}"] = $messages;
        }

        return $errors;
    }
}
