{{--
    La tarjeta de un nombramiento en la ficha de una persona (core::pages.person): el puesto, su estado, el alcance en
    chips (o «Sin alcance»), las fechas y los días que faltan, y quién lo terminó. «Terminar…» solo está en los que no
    han terminado; si el puesto no se termina aquí (el docente, las jefaturas, un nombramiento de Administración que dejaría días sin admin)
    queda deshabilitado con su motivo en un segundo renglón (DESIGN.md, «Nada oculto»). Abre el recuadro con la fecha
    de fin en la misma tarjeta. Espera `$row` (un elemento de PersonRecord::$assignments).
--}}
@php
    $assignment = $row['assignment'];
    $status = $row['status'];
    $ended = $status === \App\Modules\Core\Enums\AssignmentStatus::Ended;
    $monoLabels = ['Carrera', 'Campus'];
@endphp

<article @class(['rounded-box border border-base-300 p-4', 'bg-base-100' => ! $ended, 'bg-base-200/60' => $ended]) data-testid="assignment-{{ $assignment->id }}">
    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
        <h3 @class(['text-base font-bold', 'text-neutral' => $ended])>{{ $row['label']->title }}</h3>
        <x-core::status-badge :tone="$status->tone()" :label="$status->label()" />
    </div>

    <div class="mt-2 flex flex-wrap gap-1.5">
        @forelse ($row['label']->chips as $chip)
            <span class="rounded border border-base-300 bg-base-100 px-2 py-0.5 text-sm">{{ $chip['label'] }} <b @class(['font-semibold', 'font-mono tabular-nums' => in_array($chip['label'], $monoLabels, true)])>{{ $chip['value'] }}</b></span>
        @empty
            <span class="text-sm text-neutral">Sin alcance</span>
        @endforelse
    </div>

    <p class="mt-2 flex flex-wrap gap-x-4 gap-y-0.5 text-sm">
        <span>Desde <span class="font-mono tabular-nums">{{ $assignment->valid_from->format('d/m/Y') }}</span></span>
        @if ($assignment->valid_to !== null)
            <span>Hasta <span class="font-mono tabular-nums">{{ $assignment->valid_to->format('d/m/Y') }}</span></span>
        @else
            <span>Sin fecha de fin</span>
        @endif

        @if ($row['daysLeft'] !== null)
            <span class="text-neutral">
                @if ($row['daysLeft'] === 0)
                    Termina hoy
                @elseif ($row['daysLeft'] === 1)
                    Falta <span class="font-mono tabular-nums">1</span> día
                @else
                    Faltan <span class="font-mono tabular-nums">{{ $row['daysLeft'] }}</span> días
                @endif
            </span>
        @endif

        @if ($row['endedBy'] !== null)
            <span class="text-neutral">Terminado por {{ $row['endedBy']['name'] }} el <span class="font-mono tabular-nums">{{ $row['endedBy']['on']->format('d/m/Y') }}</span></span>
        @endif
    </p>

    @unless ($ended)
        @if ($endingId === $assignment->id)
            <form wire:submit="end" class="mt-3 space-y-2 rounded border border-base-300 bg-base-200/60 p-3" data-testid="end-box">
                <div class="flex flex-wrap items-end gap-2">
                    <x-input label="Fecha de fin" name="lastDay" type="date" wire:model="lastDay" class="max-md:h-11" />
                    <x-button label="Terminar nombramiento" type="submit" class="btn-primary btn-sm max-md:h-11" spinner="end" data-testid="end-submit" />
                    <x-button label="Cancelar" wire:click="cancelEnd" class="btn-outline btn-sm max-md:h-11" />
                </div>

                <p class="text-sm text-neutral">
                    @if ($status === \App\Modules\Core\Enums\AssignmentStatus::Scheduled)
                        Todavía no empieza: si la fecha de fin es anterior a su inicio, el nombramiento se quita y queda en la bitácora.
                    @else
                        El puesto deja de ejercerse después de esa fecha. Queda en el historial y en la bitácora.
                    @endif
                </p>
            </form>
        @else
            <div class="mt-3 flex flex-col items-start gap-1">
                @if ($row['endBlocker'] === null)
                    <x-button label="Terminar…" wire:click="confirmEnd('{{ $assignment->id }}')" class="btn-outline btn-sm max-md:h-11" data-testid="end-{{ $assignment->id }}" />
                @else
                    <x-button label="Terminar…" class="btn-outline btn-sm max-md:h-11" disabled data-testid="end-{{ $assignment->id }}" />
                    <p class="text-sm text-neutral" data-testid="end-blocker">{{ $row['endBlocker'] }}</p>
                @endif
            </div>
        @endif
    @endunless
</article>
