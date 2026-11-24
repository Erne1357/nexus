{{--
    La ficha de un grupo en su renglón de hora (maqueta A, «gc»): clave · grupo, el tipo de espacio y el cupo; la
    materia en dos renglones; quién lo tiene («Libre», «Tú» o su nombre) con el salón (o «Sin salón») y los días como
    marcas L M Mi J V. Libre con borde punteado; tomado en gris; tuyo en `info`; si te choca, rayado. Para el docente,
    «Fuera de tu perfil» y «Choca con tu …»; para quien asigna, «Asignar a…». Toda la ficha abre el drawer del grupo.
    Espera $card, $days (los del horario) y $inQueue (si quien ve está en la cola).
--}}
@use('App\Modules\Scheduling\Validation\Minutes')
@php
    $spaces = [
        'aula' => ['icon' => 'o-book-open', 'short' => 'Aula', 'label' => 'Aula'],
        'sala_computo' => ['icon' => 'o-computer-desktop', 'short' => 'Sala', 'label' => 'Sala de cómputo'],
        'laboratorio' => ['icon' => 'o-beaker', 'short' => 'Lab.', 'label' => 'Laboratorio'],
    ];
    $space = $spaces[$card['required_space']] ?? $spaces['aula'];
    $state = match (true) {
        $card['mine'] => 'mine',
        $card['teacher'] !== null => 'taken',
        $card['clashes'] => 'clash',
        default => 'free',
    };
    $room = $card['rooms'] === [] ? null : implode(', ', $card['rooms']);
    $letters = [1 => 'L', 2 => 'M', 3 => 'Mi', 4 => 'J', 5 => 'V', 6 => 'S'];
@endphp
<button type="button" wire:key="card-{{ $card['starts_at'] }}-{{ $card['group_id'] }}" data-testid="selection-card-{{ $card['group_id'] }}"
    wire:click="$dispatchTo('staffing::components.selection-group-drawer', 'open-selection-group', { id: '{{ $card['group_id'] }}' })"
    @class([
        'grid min-w-0 content-start gap-1.5 rounded-field border px-3 py-2.5 text-start hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary',
        'border-dashed border-base-content/25 bg-base-100' => $state === 'free',
        'border-base-300 bg-base-200/70' => $state === 'taken',
        'border-info/40 bg-info/10 hover:bg-info/15' => $state === 'mine',
        'border-dashed border-base-content/25 bg-[image:repeating-linear-gradient(135deg,var(--color-base-100)_0_7px,var(--color-base-200)_7px_14px)]' => $state === 'clash',
    ])>
    <span class="flex min-w-0 items-center gap-2">
        <b @class(['font-mono text-[14.5px] whitespace-nowrap', 'text-neutral' => $state === 'taken'])>{{ $card['subject_code'] }}<span class="mx-1 text-neutral" aria-hidden="true">·</span>{{ $card['group_code'] }}</b>
        @if ($card['time'])
            <span class="font-mono text-[12.5px] whitespace-nowrap text-neutral">{{ $card['time'] }}</span>
        @endif
        <span class="ms-auto flex flex-none items-center gap-2.5 text-[12.5px] text-neutral">
            <span class="inline-flex items-center gap-1" title="{{ $space['label'] }}"><x-icon :name="$space['icon']" class="h-3.5 w-3.5" aria-hidden="true" /><span aria-hidden="true">{{ $space['short'] }}</span><span class="sr-only">{{ $space['label'] }}</span></span>
            <span class="inline-flex items-center gap-1" title="{{ $card['capacity'] }} lugares"><x-icon name="o-users" class="h-3.5 w-3.5" aria-hidden="true" /><span class="font-mono font-semibold text-base-content">{{ $card['capacity'] }}</span><span class="sr-only"> lugares</span></span>
        </span>
    </span>
    <span @class(['line-clamp-2 min-h-[2.6em] font-medium leading-snug', 'text-neutral' => in_array($state, ['taken', 'clash'], true)]) title="{{ $card['subject_name'] }}">{{ $card['subject_name'] }}</span>
    <span class="flex min-w-0 items-center gap-2 text-[13.5px]">
        @if ($state === 'mine')
            <b class="text-info">Tú</b>
        @elseif ($card['teacher'] !== null)
            <span class="min-w-0 truncate">{{ $card['teacher'] }}</span>
        @else
            <b>Libre</b>
        @endif
        @if ($card['teacher'] !== null)
            @if ($room !== null)
                <b class="font-mono whitespace-nowrap">{{ $room }}</b>
            @else
                <span class="inline-flex items-center gap-1 text-[12.5px] font-semibold whitespace-nowrap text-warning"><x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5" aria-hidden="true" />Sin salón</span>
            @endif
        @endif
        <span class="sr-only">{{ implode(', ', array_map(fn ($day) => Minutes::dayName($day), $card['days'])) }}</span>
        <span class="ms-auto inline-flex flex-none gap-0.5" aria-hidden="true">
            @foreach ($days as $day)
                <span @class(['inline-grid h-[18px] min-w-[19px] place-items-center rounded-[4px] px-0.5 font-mono text-[11px] font-semibold', 'bg-secondary text-base-content' => in_array($day, $card['days'], true), 'border border-dashed border-base-300 text-neutral' => ! in_array($day, $card['days'], true)])>{{ $letters[$day] ?? $day }}</span>
            @endforeach
        </span>
    </span>
    @php
        $outOfProfile = $inQueue && ! $card['in_profile'] && in_array($state, ['free', 'mine', 'clash'], true);
    @endphp
    @if ($outOfProfile || $state === 'clash')
        <span class="flex flex-wrap gap-x-3 gap-y-0.5 text-[12.5px] font-semibold">
            @if ($outOfProfile)
                <span class="inline-flex items-center gap-1 text-warning"><x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5" aria-hidden="true" />Fuera de tu perfil</span>
            @endif
            @if ($state === 'clash')
                <span class="inline-flex items-center gap-1 text-error"><x-icon name="o-x-circle" class="h-3.5 w-3.5" aria-hidden="true" />Choca con tu {{ $card['clash_with'] }}</span>
            @endif
        </span>
    @endif
    @if ($card['can_assign'])
        <span class="-mx-1 -mb-1 flex justify-end">
            <span class="inline-flex items-center gap-1.5 rounded-field px-2 py-1 text-sm font-semibold" aria-hidden="true"><x-icon name="o-user-plus" class="h-4 w-4" />{{ $card['teacher'] === null ? 'Asignar a…' : 'Asignar a otro…' }}</span>
            <span class="sr-only">Abre para asignar el docente</span>
        </span>
    @endif
</button>
