{{--
    Un salón de la lista del cajón de la sesión (scheduling::components.session-room): radio, clave en Mono, tipo y
    lugares; sus motivos como chips; y su estado con insignia: «Libre», «Préstamo aceptado», «Ocupado · …» (HC-02, no se
    elige) o el choque que causaría (HC-05, HC-06, HC-10, HC-11). Sin permiso, el radio queda deshabilitado.
--}}
@php($disabled = ! $row['selectable'] || ! $canAssign)
<label wire:key="room-{{ $list }}-{{ $row['id'] }}" data-room="{{ $row['code'] }}"
    class="grid grid-cols-[1.25rem_1fr] items-start gap-x-2.5 rounded-box border px-3 py-2.5 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-info
        {{ $row['selectable'] ? 'border-base-300 bg-base-100 has-checked:border-2 has-checked:border-primary has-checked:bg-primary/5' : 'border-base-300 bg-base-200' }}
        {{ $disabled ? 'cursor-not-allowed' : 'cursor-pointer' }}">
    <input type="radio" name="session-room-{{ $sessionId }}" value="{{ $row['id'] }}" wire:model.live="roomId" class="radio radio-sm radio-primary mt-1"
        @disabled($disabled) aria-describedby="room-state-{{ $list }}-{{ $row['id'] }}" />
    <span class="min-w-0">
        <span class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 {{ $row['selectable'] ? '' : 'opacity-70' }}">
            <span class="font-mono text-base font-semibold">{{ $row['code'] }}</span>
            <span class="text-neutral">{{ $row['type'] }}</span>
            <span class="font-mono text-neutral">{{ $row['capacity'] }} lugares</span>
        </span>
        @if ($row['reasons'] !== [])
            <span class="mt-1.5 flex flex-wrap gap-1.5 {{ $row['selectable'] ? '' : 'opacity-70' }}">
                @foreach ($row['reasons'] as $reason)
                    <span class="rounded-full border border-base-300 bg-base-200 px-2 text-xs leading-normal">{{ $reason }}</span>
                @endforeach
            </span>
        @endif
        <span id="room-state-{{ $list }}-{{ $row['id'] }}" class="mt-2 flex flex-wrap items-center gap-1.5">
            @if ($row['state']['busy'] !== null)
                <x-core::status-badge tone="error" :label="'Ocupado · '.$row['state']['busy']" />
                <span class="w-full text-sm text-neutral">HC-02: no se puede elegir.</span>
            @elseif ($row['conflicts'] === [])
                <x-core::status-badge tone="success" :label="$row['state']['covered'] ? 'Préstamo aceptado' : 'Libre'" />
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
    </span>
</label>
