{{--
    El nombre de un registro de la lista con lo que hace falta saber para revisarlo: su observación abierta y si está
    inactivo, como insignias; en docentes, su correo institucional o «Sin correo institucional» con «Agregar» (abre el
    recuadro del correo, AC-06; quien no lo captura ve su motivo en «Acciones») y sus otros departamentos («También en»
    si este es su principal, «Principal en» si no); en salones, su tipo y su capacidad cuando sus columnas se ocultan.
    `$record`, `$inTable` (la tabla lleva los data-testid; los renglones apilados no); `$flagMap`, `$teacherRows` y
    `$current` son de review.php.
--}}
@php($row = $teacherRows[$record->id] ?? null)
<div class="flex flex-wrap items-center gap-x-2 gap-y-1">
    <span @class(['font-semibold' => $record->is_active])>{{ $record->name ?? '—' }}</span>
    @if (! empty($flagMap[$record->id]))
        @include('catalog-validation::review.flag-badge', ['flags' => $flagMap[$record->id]])
    @endif
    @if (! $record->is_active)
        <x-core::status-badge tone="neutral" label="Inactivo" />
    @endif
</div>
@if ($row !== null)
    <div class="mt-0.5 flex flex-col text-[12.5px] leading-snug text-neutral">
        @if ($record->institutional_email)
            <span class="break-all font-mono">{{ $record->institutional_email }}</span>
        @else
            <span class="flex flex-wrap items-center gap-x-2">
                <span class="inline-flex items-center gap-1 text-warning"><x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5" aria-hidden="true" />Sin correo institucional</span>
                @if ($row['emailReason'] === null)
                    <button type="button" class="link font-medium text-base-content underline-offset-4" data-email-open="{{ $record->id }}" data-email-name="{{ $record->name }}" data-email-current="" aria-label="Agregar el correo institucional de {{ $record->name }}" @if ($inTable) data-testid="add-email-{{ $record->rfc }}" @endif>Agregar</button>
                @endif
            </span>
        @endif
        @if ($row['departments']['others'] !== [])
            <span>{{ $row['departments']['here_primary'] ? 'También en' : 'Principal en' }} {{ $row['departments']['here_primary'] ? collect($row['departments']['others'])->pluck('name')->join(', ') : $row['departments']['others'][0]['name'] }}</span>
        @endif
    </div>
@elseif ($catalog === \App\Modules\CatalogValidation\Enums\Catalog::Rooms)
    <p class="mt-0.5 text-[12.5px] text-neutral min-[1536px]:hidden">{{ $record->type->label() }}<span class="min-[1024px]:hidden"> · <span class="font-mono tabular-nums">{{ $record->capacity }}</span> lugares</span></p>
@endif
