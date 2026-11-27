{{--
    Registros del catálogo de un lote en el asistente (imports::pages.import-wizard): los que no vienen
    en el archivo, en el paso 3, y los que el lote desactivó, en el paso 4. La clave natural en Mono
    (DESIGN.md, "Anatomía de una pantalla de catálogo") y el nombre.
--}}
<x-table
    :headers="[
        ['key' => 'natural_key', 'label' => 'Clave', 'sortable' => false],
        ['key' => 'name', 'label' => 'Nombre', 'sortable' => false],
    ]"
    :rows="$records"
    with-pagination
    class="mt-2"
>
    @scope('cell_natural_key', $record, $batchKey)
        <span class="font-mono">{{ implode(' · ', array_map(fn (string $field): string => is_scalar($value = $record->getAttribute($field)) ? (string) $value : json_encode($value), $batchKey)) }}</span>
    @endscope

    @scope('cell_name', $record)
        {{ $record->getAttribute('name') ?? '—' }}
    @endscope
</x-table>
