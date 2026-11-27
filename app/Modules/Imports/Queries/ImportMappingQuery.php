<?php

namespace App\Modules\Imports\Queries;

use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Imports\Models\ImportMapping;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lecturas de mapeos de columnas para el asistente de importación. Quién las ve lo decide la página
 * con ImportBatchPolicy.
 */
final class ImportMappingQuery
{
    /**
     * Los mapeos activos de una plantilla, por nombre: los que se pueden elegir al subir un archivo.
     *
     * @return Collection<int, ImportMapping>
     */
    public function forTemplate(string $template): Collection
    {
        return ImportMapping::query()
            ->where('template', $template)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * El mapeo que eligió el admin, solo si es uno activo de la plantilla: el id llega del navegador y
     * el mapeo pudo desactivarse después de abrir la página.
     */
    public function findActiveFor(string $template, string $id): ImportMapping
    {
        return KeyLookup::findOrFail(ImportMapping::query()->where('template', $template)->where('is_active', true), $id);
    }
}
