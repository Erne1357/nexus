{{--
    El departamento de una fila del tablero de avance: clave, nombre (abre el drawer), jefe vigente y el estado de su
    carga en cada campus del filtro (lo reporta AcademicLoad con LoadStatusesRequested). `$row` y `$campuses` son
    de progress.php.
--}}
@php($department = $row['department'])
<div class="min-w-0 space-y-1">
    <button type="button" wire:click="open('{{ $department->id }}')" class="group block max-w-full text-start" data-testid="open-{{ $department->code }}" aria-label="Abrir el detalle de {{ $department->name }}">
        <span class="font-mono text-[13px] text-neutral">{{ $department->code }}</span>
        <span class="font-semibold leading-snug underline decoration-transparent underline-offset-4 group-hover:decoration-current group-focus-visible:decoration-current">{{ $department->name }}</span>
    </button>
    <p class="text-sm text-neutral">{{ $row['head']?->name ?? 'Sin jefe vigente' }}</p>
    <div class="flex flex-wrap gap-1">
        @foreach ($campuses as $campus)
            @if ($load = $row['loads'][$campus->id] ?? null)
                <x-core::status-badge :tone="$load['tone']" :label="($campuses->count() > 1 ? $campus->code.' · ' : '').$load['label']" data-testid="load-{{ $department->code }}-{{ $campus->code }}" />
            @endif
        @endforeach
    </div>
</div>
