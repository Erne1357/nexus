{{--
    Lo que pasa al armar o reorganizar (maqueta B, «Al reorganizar»), de la vista previa: cuántos grupos nacen; cada grupo
    que ya existe y se queda, como qué grupo, con su horario, su docente y sus cupos nuevos; cada uno que se cancela, con el
    horario que pierde (`warning`) o sin él; el que no se puede cancelar y no cabe (con docente o con una solicitud de cambio
    abierta), en `error` con su motivo (no se arma así) y, a quien arma, «Ir a Asignación docente» en el grupo con docente; las partes
    pendientes, que se arman al aceptarlas; y a quién se avisa. Sin acuerdo que aceptar (confirmado el 18 de noviembre de
    2026).
--}}
@php
    $created = array_keys(array_filter($draft->slots, fn ($slot): bool => $slot->keeps === null));
    $createdFull = count(array_filter($created, fn ($index): bool => $draft->slots[$index]->full));
    $createdRest = array_map(fn ($index): string => $draft->slotName($index), array_values(array_filter($created, fn ($index): bool => ! $draft->slots[$index]->full)));
    $blockedIds = array_map(fn ($group): string => $group->id, $draft->blocked);
    $pending = $draft->pending();
    $quotaLine = fn ($slot): string => collect($slot->quotas)->map(fn ($seats, $career) => $draft->code($career).' '.$seats)->join(' · ');
@endphp
<ul class="space-y-2 text-sm">
    @if ($created !== [])
        <li class="flex items-start gap-2.5">
            <x-icon name="o-plus" class="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" />
            <span>Nacen <b>{{ count($created) === 1 ? '1 grupo nuevo' : count($created).' grupos nuevos' }}</b>, sin horario: {{ collect([$createdFull > 0 ? ($createdFull === 1 ? '1 completo' : $createdFull.' completos') : null, ...$createdRest])->filter()->join(', ', ' y ') }}.</span>
        </li>
    @endif
    @foreach ($draft->slots as $index => $slot)
        @if ($slot->keeps)
            <li class="flex items-start gap-2.5" wire:key="kept-{{ $slot->keeps->id }}">
                <x-icon name="o-check" class="mt-0.5 h-4 w-4 shrink-0 text-success" aria-hidden="true" />
                <span><b class="font-mono">{{ $slot->keeps->code }}</b> de {{ $draft->code($slot->keeps->ownerCareerId) }} se queda como <b>{{ $slot->full ? 'su completo' : $draft->slotName($index) }}</b>{{ $slot->keeps->minutes > 0 ? ': conserva su horario ('.$duration($slot->keeps->minutes).')' : '' }}{{ $slot->keeps->hasTeacher() ? ($slot->keeps->minutes > 0 ? ' y su docente' : ': conserva su docente') : '' }}{{ $slot->full ? '' : '; sus cupos pasan a '.$quotaLine($slot) }}.</span>
            </li>
        @endif
    @endforeach
    @foreach ($draft->cancelled as $group)
        @if (in_array($group->id, $blockedIds, true))
            <li class="flex items-start gap-2.5 text-error" wire:key="blocked-{{ $group->id }}" data-testid="parallels-blocked">
                <x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <span>{{ $group->lockReason($draft->code($group->ownerCareerId)) }}@if ($group->hasTeacher()) <span class="text-neutral">(docente: {{ $group->teacher }})</span>@endif
                    @php($toAssign = $canArrange && $group->hasTeacher() ? $assignUrl($group) : null)
                    @if ($toAssign !== null)
                        <a href="{{ $toAssign }}" wire:navigate class="link inline-flex min-h-11 items-center gap-1 font-semibold text-base-content no-underline hover:underline md:min-h-0">Ir a Asignación docente<x-icon name="o-arrow-right" class="h-3.5 w-3.5" aria-hidden="true" /></a>
                    @endif
                </span>
            </li>
        @elseif ($group->minutes > 0)
            <li class="flex items-start gap-2.5" wire:key="cancel-{{ $group->id }}">
                <x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-warning" aria-hidden="true" />
                <span>Se cancela <b class="font-mono">{{ $group->code }}</b> de {{ $draft->code($group->ownerCareerId) }} y se quitan sus {{ $duration($group->minutes) }} colocadas ({{ $group->sessions }} {{ $group->sessions === 1 ? 'sesión' : 'sesiones' }}). Queda en la bitácora.</span>
            </li>
        @else
            <li class="flex items-start gap-2.5" wire:key="cancel-{{ $group->id }}">
                <x-icon name="o-minus-circle" class="mt-0.5 h-4 w-4 shrink-0 text-neutral" aria-hidden="true" />
                <span>Se cancela <b class="font-mono">{{ $group->code }}</b> de {{ $draft->code($group->ownerCareerId) }}, sin horario ni docente. Queda en la bitácora.</span>
            </li>
        @endif
    @endforeach
    @if ($pending !== [])
        <li class="flex items-start gap-2.5">
            <x-icon name="o-clock" class="mt-0.5 h-4 w-4 shrink-0 text-neutral" aria-hidden="true" />
            <span>{{ count($pending) === 1 ? 'La parte de '.$pending[0].' está pendiente' : 'Las partes de '.collect($pending)->join(', ', ' y ').' están pendientes' }}: sus lugares nacen al aceptarla en la bandeja, y se arma después.</span>
        </li>
    @endif
    <li class="flex items-start gap-2.5 text-neutral">
        <x-icon name="o-bell" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
        <span>Se avisa a los coordinadores de las carreras cuyos lugares cambian, y al jefe de su departamento cuando no es este. No hay acuerdo que aceptar: las carreras ya pidieron estos alumnos en su propuesta.</span>
    </li>
</ul>
