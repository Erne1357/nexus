<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Events\ImportMappingCreated;
use App\Modules\Imports\Models\ImportMapping;
use App\Modules\Imports\Templates\ColumnMatcher;
use App\Modules\Imports\Templates\ImportTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Guarda un mapeo de columnas para una plantilla (ADR-0005): cada layout de exportación del SII se
 * mapea una vez y se reutiliza. `columns` es columna del archivo → campo de la plantilla. No sabe de
 * roles: quién guarda lo decide la página con ImportBatchPolicy, porque mapear es parte de importar.
 *
 * Rechaza con una ValidationException por campo, sin guardar: una plantilla que no existe; un nombre
 * vacío o que ya usa otro mapeo de la plantilla, también uno desactivado o borrado (la clave única los
 * incluye); columnas hacia campos que la plantilla no tiene, dos columnas hacia el mismo campo y un
 * mapeo sin la columna de la clave natural, que ValidateImportBatch rechazaría al leer el archivo. El
 * nombre se recorta y junta sus espacios.
 *
 * En una transacción revisa el nombre, escribe y deja bitácora; si otra sesión guarda el mismo nombre
 * entre la revisión y el insert, la restricción única responde con el mismo error. Después del commit
 * avisa con ImportMappingCreated.
 */
final class CreateImportMapping
{
    public function __construct(
        private readonly TemplateRegistry $templates,
        private readonly ColumnMatcher $matcher,
    ) {}

    /**
     * @param  array<string, string>  $columns  columna del archivo → campo de la plantilla
     *
     * @throws ValidationException
     */
    public function handle(User $user, string $template, string $name, array $columns): ImportMapping
    {
        if (! $this->templates->has($template)) {
            throw ValidationException::withMessages(['template' => 'Elige una plantilla de importación.']);
        }

        $definition = $this->templates->get($template);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Escribe un nombre para el mapeo.']);
        }

        $this->ensureColumnsFit($definition, $columns);

        return DB::transaction(function () use ($user, $definition, $template, $name, $columns): ImportMapping {
            if (ImportMapping::withTrashed()->where('template', $template)->where('name', $name)->exists()) {
                throw $this->nameTaken();
            }

            try {
                $mapping = ImportMapping::create([
                    'template' => $template,
                    'name' => $name,
                    'columns' => $columns,
                    'created_by' => $user->id,
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->nameTaken();
            }

            activity()
                ->performedOn($mapping)
                ->causedBy($user)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $mapping->only(['template', 'name', 'columns', 'is_active'])])
                ->log("Guardó el mapeo de columnas «{$mapping->name}» para importar {$definition->label()}");

            ImportMappingCreated::dispatch($mapping);

            return $mapping;
        });
    }

    /**
     * @param  array<string, string>  $columns
     *
     * @throws ValidationException
     */
    private function ensureColumnsFit(ImportTemplate $template, array $columns): void
    {
        $fields = $template->fields();
        $unknown = array_values(array_diff($columns, array_keys($fields)));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['columns' => 'El mapeo apunta a campos que la plantilla no tiene: '.implode(', ', $unknown).'.']);
        }

        foreach (array_keys($fields) as $field) {
            $sources = array_keys($columns, $field, true);

            if (count($sources) > 1) {
                $last = array_pop($sources);

                throw ValidationException::withMessages(['columns' => "El campo {$fields[$field]} tiene dos columnas: ".implode(', ', $sources)." y {$last}."]);
            }
        }

        $missing = $this->matcher->missingKey($template, $columns);

        if ($missing !== []) {
            $labels = implode(', ', array_map(fn (string $field): string => $fields[$field], $missing));

            throw ValidationException::withMessages(['columns' => "Falta la columna de {$labels}, que identifica cada registro."]);
        }
    }

    private function nameTaken(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'Ya hay un mapeo con ese nombre para esta plantilla.']);
    }
}
