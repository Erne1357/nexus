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
 * vacío, de más de 255 caracteres o que ya usa otro mapeo de la plantilla sin importar mayúsculas,
 * también uno desactivado o borrado (la clave única los incluye); una columna sin nombre o sin campo,
 * columnas hacia campos que la plantilla no tiene, varias columnas hacia el mismo campo y un mapeo sin
 * la columna de la clave natural, que ValidateImportBatch rechazaría al leer el archivo. El nombre se
 * recorta y junta sus espacios.
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

        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'El nombre del mapeo no puede pasar de 255 caracteres.']);
        }

        $this->ensureColumnsFit($definition, $columns);

        return DB::transaction(function () use ($user, $definition, $template, $name, $columns): ImportMapping {
            // Sin importar mayúsculas: en la lista del asistente "SII 2026" y "sii 2026" serían el mismo.
            if (ImportMapping::withTrashed()->where('template', $template)->whereRaw('lower(name) = lower(?)', [$name])->exists()) {
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
     * @param  array<array-key, mixed>  $columns
     *
     * @throws ValidationException
     */
    private function ensureColumnsFit(ImportTemplate $template, array $columns): void
    {
        foreach ($columns as $source => $field) {
            if (trim((string) $source) === '' || ! is_string($field) || $field === '') {
                throw ValidationException::withMessages(['columns' => 'Cada columna del mapeo necesita su nombre y el campo al que va.']);
            }
        }

        $unknown = $this->matcher->unknownFields($template, $columns);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['columns' => 'El mapeo apunta a campos que la plantilla no tiene: '.implode(', ', $unknown).'.']);
        }

        $fields = $template->fields();
        $repeated = [];

        foreach (array_keys(array_filter(array_count_values($columns), fn (int $count): bool => $count > 1)) as $field) {
            $sources = array_map(strval(...), array_keys($columns, $field, true));
            $last = array_pop($sources);
            $repeated[] = "{$fields[$field]} (".implode(', ', $sources)." y {$last})";
        }

        if ($repeated !== []) {
            throw ValidationException::withMessages(['columns' => 'Cada campo recibe una sola columna: '.implode('; ', $repeated).'.']);
        }

        if (($missing = $this->matcher->describeMissingKey($template, $columns)) !== null) {
            throw ValidationException::withMessages(['columns' => $missing]);
        }
    }

    private function nameTaken(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'Ya hay un mapeo con ese nombre para esta plantilla.']);
    }
}
