{{--
    Un salón de la lista del cajón de la sesión (scheduling::components.session-room): radio (Alpine `selected`), clave
    en Mono y tipo; solo los motivos que deciden («Cabe: 30 de 32», por defecto o el historial de la materia, el mismo
    edificio; el tipo ya va junto a la clave); su estado con insignias cortas («Préstamo aceptado», «Ocupado»,
    «Apartado», «Pide préstamo» o «Préstamo pendiente» en `warning`, «Bloqueado», «No cabe»…; «Libre» solo si algún
    salón de la lista no lo está, `allFree`) y el detalle de texto libre en renglones que parten (quién lo ocupa, de
    qué departamento es, el motivo del bloqueo), para no desbordar a 390 px. Ocupado (HC-02) o apartado por el
    préstamo de otro grupo no se elige. Al elegirlo, lo que pasaría al guardarlo. Un sugerido que coincide con la
    búsqueda se resalta. Sin permiso o con el grupo sin editar, el radio queda deshabilitado.
--}}
@php($disabled = ! $row['selectable'] || ! $canAssign)
@php($asksLoan = $row['state']['needsLoan'] && ! $row['state']['covered'])
<label wire:key="room-{{ $list }}-{{ $row['id'] }}" data-room="{{ $row['code'] }}" data-asks-loan="{{ $asksLoan ? 1 : 0 }}" data-matched="{{ $matched ? 1 : 0 }}"
    class="grid min-w-0 grid-cols-[1.25rem_minmax(0,1fr)] items-start gap-x-2.5 rounded-box border px-3 py-2.5 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-info
        {{ $row['selectable'] ? 'border-base-300 bg-base-100 has-checked:border-2 has-checked:border-primary has-checked:bg-primary/5' : 'border-base-300 bg-base-200' }}
        {{ $matched ? 'ring-2 ring-info' : '' }}
        {{ $disabled ? 'cursor-not-allowed' : 'cursor-pointer' }}">
    <input type="radio" name="session-room-{{ $sessionId }}" value="{{ $row['id'] }}" x-model="selected" class="radio radio-sm radio-primary mt-1"
        @disabled($disabled) aria-describedby="room-state-{{ $list }}-{{ $row['id'] }}" />
    <span class="min-w-0">
        <span class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 {{ $row['selectable'] ? '' : 'opacity-70' }}">
            <span class="font-mono text-base font-semibold">{{ $row['code'] }}</span>
            <span class="text-neutral">{{ $row['type'] }}</span>
        </span>
        @php($facts = array_values(array_filter($row['reasons'], fn (string $reason): bool => $reason !== $row['type'])))
        @if ($facts !== [])
            <span class="mt-1.5 flex flex-wrap gap-1.5 {{ $row['selectable'] ? '' : 'opacity-70' }}">
                @foreach ($facts as $reason)
                    <span class="max-w-full rounded-full border border-base-300 bg-base-200 px-2 text-xs leading-normal break-words">{{ $reason }}</span>
                @endforeach
            </span>
        @endif
        @if ($row['state']['free'] && ! $row['state']['covered'] && $allFree)
            <span id="room-state-{{ $list }}-{{ $row['id'] }}" class="sr-only">Libre</span>
        @else
        <span id="room-state-{{ $list }}-{{ $row['id'] }}" class="mt-2 grid gap-1">
            <span class="flex flex-wrap items-center gap-1.5">
                @if ($row['state']['busy'] !== null)
                    <x-core::status-badge tone="error" :label="$row['state']['busy']" />
                @elseif ($row['conflicts'] === [] && $row['state']['covered'])
                    <x-core::status-badge tone="success" label="Préstamo aceptado" />
                @elseif ($row['conflicts'] === [] && ! $allFree)
                    <x-core::status-badge tone="success" label="Libre" />
                @else
                    @foreach ($row['conflicts'] as $conflict)
                        @if ($conflict['rule'] === 'HC-10')
                            <x-core::status-badge tone="warning" :label="$conflict['label']" :icon="$row['state']['pending'] ? 'o-clock' : 'o-arrows-right-left'" />
                        @else
                            <x-core::status-badge tone="error" :label="$conflict['label']" />
                        @endif
                    @endforeach
                @endif
            </span>
            @if ($row['state']['busy'] !== null)
                <span class="text-sm break-words">{{ (string) $row['state']['busyDetail'] }}</span>
                <span class="text-sm text-neutral break-words">{{ $row['state']['busyNote'] }}</span>
            @else
                @foreach ($row['conflicts'] as $conflict)
                    @if ($conflict['detail'] !== null)
                        <span class="text-sm break-words">{{ $conflict['detail'] }}</span>
                    @endif
                @endforeach
            @endif
        </span>
        @endif
        @if ($row['conflicts'] !== [] && $row['selectable'])
            <span class="mt-2 grid gap-2" x-show="selected === @js($row['id'])" x-cloak data-testid="room-preview">
                <span class="text-sm text-neutral">Si eliges {{ $row['code'] }}, la sesión se guarda y queda marcada:</span>
                @foreach ($row['conflicts'] as $conflict)
                    @php($loan = $conflict['rule'] === 'HC-10')
                    <span class="flex items-start gap-2 rounded-field border px-3 py-2 text-sm {{ $loan ? 'border-warning/40 bg-warning/10' : 'border-error/40 bg-error/10' }}">
                        <x-icon :name="$loan ? 'o-arrows-right-left' : 'o-x-circle'" class="mt-0.5 h-4 w-4 shrink-0 {{ $loan ? 'text-warning' : 'text-error' }}" aria-hidden="true" />
                        <span class="min-w-0 break-words"><b class="font-mono">{{ $conflict['rule'] }}</b> {{ $conflict['message'] }}</span>
                    </span>
                @endforeach
            </span>
        @endif
    </span>
</label>
