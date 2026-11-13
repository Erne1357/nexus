{{--
    El departamento de una fila del tablero de avance: clave, nombre (abre el drawer), jefe vigente y su carga en cada
    modalidad de los campus del filtro (la carga es por departamento y modalidad, M1; lo reporta AcademicLoad con
    LoadStatusesRequested). `$row` es de progress.php.
--}}
@php($department = $row['department'])
<div class="min-w-0 space-y-0.5">
    <button type="button" wire:click="open('{{ $department->id }}')" class="group block max-w-full truncate text-start" title="{{ $department->name }}" data-testid="open-{{ $department->code }}" aria-label="Abrir el detalle de {{ $department->name }}">
        <span class="font-mono text-[15px] font-semibold text-neutral">{{ $department->code }}</span>
        <span class="font-semibold leading-snug underline decoration-transparent underline-offset-4 group-hover:decoration-current group-focus-visible:decoration-current">{{ $department->name }}</span>
    </button>
    <p class="truncate text-sm text-neutral" title="{{ $row['heads']->pluck('name')->implode(', ') }}">{{ $row['heads']->isEmpty() ? 'Sin jefe vigente' : $row['heads']->pluck('name')->implode(', ') }}</p>
    @if ($row['loads'] !== [])
        <p class="whitespace-nowrap text-xs text-neutral" data-testid="loads-{{ $department->code }}">
            Carga:
            @foreach ($row['loads'] as $entry)
                <span class="whitespace-nowrap" data-testid="load-{{ $department->code }}-{{ $entry['modality']->code }}">{{ count($row['loads']) > 1 ? $entry['modality']->name.' ' : '' }}<span class="font-medium text-base-content">{{ $entry['load']['label'] }}</span></span>@if (! $loop->last) · @endif
            @endforeach
        </p>
    @endif
</div>
