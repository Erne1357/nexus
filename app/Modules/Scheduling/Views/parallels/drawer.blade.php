{{--
    El drawer de una materia en un campus (maqueta B, `x-drawer` a la derecha de hasta 72rem): la clave, el campus y su
    estado; el nombre; horas, tamaño objetivo y qué carreras cuentan y cuáles no. Luego lo pedido contra lo que resulta y
    lo que se ahorra; si ya hay grupos sueltos, que armar los reorganiza; el error de la Action, si lo hubo. Desde 1024 px
    la matriz para mover esperados (scheduling::parallels.matrix); debajo, lo que piden en renglones, de consulta. Siguen
    los grupos que resultan (scheduling::parallels.results) y lo que pasa al armar. Al pie fijo, el motivo de lo que no se
    puede (o el aviso de los esperados sin lugar), «Cancelar», «Usar lo de Nexus» si el armado cambió y «Armar grupos»,
    que confirma con el diálogo de Core. `close()` es del x-drawer.
--}}
@php
    $row = $draft->row;
    $signed = fn (int $n): string => $n > 0 ? '−'.$n : ($n < 0 ? '+'.abs($n) : '0');
    $reorganize = $row->groups !== [];
    $withSchedule = count(array_filter($row->groups, fn ($group): bool => $group->minutes > 0));
    $withTeacher = count(array_filter($row->groups, fn ($group): bool => $group->hasTeacher()));
    $groupErrors = collect($errors->getMessages())->filter(fn ($messages, $field): bool => $field === 'groups' || str_starts_with($field, 'groups.'))->flatten()->unique()->values();
    $readOnlyRole = ! $canArrange;
@endphp
<div class="flex h-dvh flex-col" data-testid="parallels-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-[15px] font-semibold">{{ $row->subject->code }}</span>
                <x-core::campus-tag :campus="$row->campus" size="lg" />
                @if ($row->arranged())
                    <x-core::status-badge tone="success" label="Armada" />
                @else
                    <x-core::status-badge tone="warning" icon="o-clock" label="Por armar" />
                @endif
            </p>
            <h2 id="parallels-drawer-title" tabindex="-1" class="mt-1 text-xl font-bold leading-tight">{{ $row->subject->name }} en {{ $row->campus->name }}</h2>
            <p class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-sm text-neutral">
                <span><span class="font-mono">{{ $hours }}</span> h a la semana</span>
                <span>Tamaño <span class="font-mono">{{ $row->size() }}</span>{{ $row->subject->needs_lab ? ', lleva laboratorio' : '' }}</span>
                <span><span class="font-mono">{{ count($row->careers) }}</span> {{ count($row->careers) === 1 ? 'carrera cuenta' : 'carreras cuentan' }}</span>
                @if ($row->excluded !== [])
                    <span data-testid="parallels-excluded">{{ collect($row->excluded)->map(fn ($career) => $career->career->code.' (regresada)')->join(', ', ' y ') }} no {{ count($row->excluded) === 1 ? 'cuenta' : 'cuentan' }}</span>
                @endif
            </p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" data-testid="parallels-close" />
    </header>

    <div class="flex-1 space-y-6 overflow-y-auto px-5 py-5">
        <div class="flex flex-wrap items-end gap-x-6 gap-y-3" data-testid="parallels-saving">
            <p class="min-w-0"><b class="block font-mono text-[26px] font-semibold leading-tight tabular-nums">{{ $row->requested() }}</b><span class="text-[12.5px] text-neutral">grupos pedidos</span></p>
            <x-icon name="o-arrow-long-right" class="mb-5 h-5 w-5 text-neutral" aria-hidden="true" />
            <p class="min-w-0"><b class="block font-mono text-[26px] font-semibold leading-tight tabular-nums">{{ $draft->size() }}</b><span class="text-[12.5px] text-neutral">{{ $draft->size() === $row->suggested() && $draft->isSuggestion() ? 'bastan juntando' : 'con tu armado · Nexus sugiere '.$row->suggested() }}</span></p>
            <p class="min-w-0"><b @class(['block font-mono text-[26px] font-semibold leading-tight tabular-nums', 'text-success' => $draft->saved() > 0])>{{ $signed($draft->saved()) }}</b><span class="text-[12.5px] text-neutral">{{ $draft->saved() >= 0 ? 'grupos · ≈ '.($draft->saved() * $hours).' h de docente menos a la semana' : 'grupos más de lo pedido' }}</span></p>
        </div>

        @if ($reorganize)
            <div class="flex items-start gap-2.5 rounded-field bg-info/10 px-3.5 py-2.5 text-sm" data-testid="parallels-existing">
                <x-icon name="o-information-circle" class="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" />
                <span>Ya existen <b class="font-mono">{{ count($row->groups) }}</b> {{ count($row->groups) === 1 ? 'grupo suelto' : 'grupos sueltos' }} de esta materia en {{ $row->campus->name }} ({{ $withSchedule }} con horario y {{ $withTeacher }} con docente). Armar los <b>reorganiza</b>: se queda el de más avance, el que se cancela pierde sus sesiones y ninguno con docente se cancela.</span>
            </div>
        @endif

        @if ($groupErrors->isNotEmpty())
            <div role="alert" class="grid gap-1 rounded-field border border-error/40 bg-error/10 px-3.5 py-2.5 text-sm" data-testid="parallels-error">
                @foreach ($groupErrors as $message)
                    <span class="flex items-start gap-2"><x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0 text-error" aria-hidden="true" />{{ $message }}</span>
                @endforeach
            </div>
        @endif

        <section aria-labelledby="parallels-matrix-title" class="space-y-2">
            <h3 id="parallels-matrix-title" class="text-base font-semibold"><span class="max-lg:hidden">Reparto de los esperados</span><span class="lg:hidden">Lo que piden</span></h3>
            <p class="text-sm text-neutral max-lg:hidden">Cada celda, los alumnos de esa carrera en ese grupo; la suma de cada grupo, abajo.{{ $editable ? ' Los completos se mueven con − y +; los mixtos, escribiendo.' : '' }}</p>
            <p class="text-sm text-neutral lg:hidden">Cada carrera, sus esperados y en qué grupos quedan.</p>
            @include('scheduling::parallels.matrix')
            @include('scheduling::parallels.demand-stack')
            <p class="flex items-start gap-2 text-sm text-neutral lg:hidden" data-testid="parallels-wide-only"><x-icon name="o-computer-desktop" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />Mover alumnos y armar se hace en una pantalla de 1024 px o más; aquí lo consultas.</p>
        </section>

        @include('scheduling::parallels.results')

        <section aria-labelledby="parallels-effects-title" class="space-y-2" data-testid="parallels-effects">
            <h3 id="parallels-effects-title" class="text-base font-semibold">{{ $reorganize ? 'Al reorganizar' : 'Al armar' }}</h3>
            @include('scheduling::parallels.effects')
        </section>
    </div>

    <footer class="flex flex-wrap items-center gap-x-3 gap-y-2.5 border-t border-base-300 bg-base-100 px-5 py-3">
        <div class="min-w-0 flex-1 basis-72 text-sm" aria-live="polite">
            @if ($armBlocked !== null)
                <p id="parallels-why" @class(['flex items-start gap-2', 'text-neutral' => $readOnlyRole, 'text-error' => ! $readOnlyRole]) data-testid="parallels-why">
                    <x-icon :name="$readOnlyRole ? 'o-lock-closed' : 'o-x-circle'" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span>{{ $armBlocked }}</span>
                </p>
            @elseif ($draft->warning() !== null)
                <p class="flex items-start gap-2 text-warning" data-testid="parallels-warning"><x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span class="text-base-content">{{ $draft->warning() }}</span></p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2 max-sm:w-full">
            <button type="button" class="btn btn-ghost max-sm:h-11 max-sm:flex-1" @click="close()">Cancelar</button>
            @if ($editable && ! $draft->isSuggestion())
                <button type="button" class="btn max-lg:hidden" wire:click="useSuggestion" wire:loading.attr="disabled" data-testid="parallels-reset"><x-icon name="o-arrow-path" class="h-4 w-4" aria-hidden="true" />Usar lo de Nexus</button>
            @endif
            <button type="button" class="btn btn-primary max-lg:hidden" wire:click="arm" data-testid="parallels-arm" @disabled($armBlocked !== null) @if ($armBlocked !== null) aria-describedby="parallels-why" @endif
                x-confirm="{{ $confirm }}" data-confirm-title="¿Armar los paralelos de {{ $row->subject->name }} en {{ $row->campus->name }}?" data-confirm-button="Armar grupos" data-confirm-tone="warning"
                wire:loading.attr="disabled" wire:target="arm">
                <x-icon name="o-arrows-pointing-in" class="h-4 w-4" aria-hidden="true" />{{ $reorganize ? 'Reorganizar grupos' : 'Armar grupos' }}
            </button>
        </div>
    </footer>
</div>
