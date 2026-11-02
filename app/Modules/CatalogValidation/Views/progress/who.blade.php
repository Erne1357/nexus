{{--
    El departamento de una fila del tablero de avance: clave, nombre (abre el drawer), jefe vigente y la carga en
    cada campus del filtro (lo reporta AcademicLoad con LoadStatusesRequested). `$row` y `$campuses` son
    de progress.php.
--}}
@php($department = $row['department'])
<div class="min-w-0 space-y-0.5">
    <button type="button" wire:click="open('{{ $department->id }}')" class="group block max-w-full truncate text-start" title="{{ $department->name }}" data-testid="open-{{ $department->code }}" aria-label="Abrir el detalle de {{ $department->name }}">
        <span class="font-mono text-[15px] font-semibold text-neutral">{{ $department->code }}</span>
        <span class="font-semibold leading-snug underline decoration-transparent underline-offset-4 group-hover:decoration-current group-focus-visible:decoration-current">{{ $department->name }}</span>
    </button>
    <p class="truncate text-sm text-neutral" title="{{ $row['heads']->pluck('name')->implode(', ') }}">{{ $row['heads']->isEmpty() ? 'Sin jefe vigente' : $row['heads']->pluck('name')->implode(', ') }}</p>
    <p class="whitespace-nowrap text-xs text-neutral">
        Carga:
        @foreach ($campuses as $campus)
            @if ($load = $row['loads'][$campus->id] ?? null)
                <span class="whitespace-nowrap" data-testid="load-{{ $department->code }}-{{ $campus->code }}">{{ $campuses->count() > 1 ? $campus->code.' ' : '' }}<span class="font-medium text-base-content">{{ $load['label'] }}</span></span>@if (! $loop->last) · @endif
            @endif
        @endforeach
    </p>
</div>
