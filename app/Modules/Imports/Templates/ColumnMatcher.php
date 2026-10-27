<?php

namespace App\Modules\Imports\Templates;

use Illuminate\Support\Str;

/**
 * Qué columna del archivo va a qué campo de la plantilla. Con un mapeo guardado (`import_mappings.columns`)
 * usa solo lo que el mapeo dice; sin él reconoce cada columna por nombre de campo o por etiqueta, sin
 * importar mayúsculas, acentos ni espacios. Lo usan ValidateImportBatch y el asistente, que muestra la
 * propuesta antes de validar. No guarda estado.
 */
final class ColumnMatcher
{
    /**
     * @param  list<string>  $header
     * @param  array<string, string>|null  $mapping  columna → campo, de un mapeo guardado
     * @return array<string, string> columna del archivo → campo de la plantilla
     */
    public function match(ImportTemplate $template, array $header, ?array $mapping = null): array
    {
        $fields = $template->fields();
        $columns = [];

        if ($mapping !== null) {
            foreach ($mapping as $source => $field) {
                if (in_array($source, $header, true) && isset($fields[$field])) {
                    $columns[$source] = $field;
                }
            }

            return $columns;
        }

        // Primero las etiquetas y después los nombres de campo, que ganan si un slug coincide con los dos.
        $bySlug = [];

        foreach ($fields as $field => $label) {
            $bySlug[$this->slug($label)] = $field;
        }

        foreach (array_keys($fields) as $field) {
            $bySlug[$this->slug($field)] = $field;
        }

        foreach ($header as $source) {
            if (($field = $bySlug[$this->slug($source)] ?? null) !== null && ! in_array($field, $columns, true)) {
                $columns[$source] = $field;
            }
        }

        return $columns;
    }

    /**
     * Los campos de la clave natural que no tienen columna: sin ellos no se sabe qué registro es cada fila.
     *
     * @param  array<string, string>  $columns  columna → campo
     * @return list<string>
     */
    public function missingKey(ImportTemplate $template, array $columns): array
    {
        return array_values(array_diff($template->naturalKey(), $columns));
    }

    private function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($text))), '_');
    }
}
