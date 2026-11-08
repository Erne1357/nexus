{{--
    Lo que le falta a un conjunto de grupos (una celda de la retícula o un departamento): sus choques duros, con su
    regla, el grupo y el mensaje del validador, y los grupos sin horario, sin docente y sin salón, cada uno con el
    enlace a la pantalla que lo corrige. `$detail` sale de ConflictBoardQuery::detail().
--}}
@php($group = fn (array $g): string => $g['subject_code'].' · '.$g['code'])
<div class="divide-y divide-base-300">
    @if ($detail['conflicts'] !== [])
        @php($total = array_sum(array_map(fn (array $entry): int => count($entry['items']), $detail['conflicts'])))
        <section class="py-3" aria-labelledby="{{ $prefix }}-hard">
            <h3 id="{{ $prefix }}-hard" class="mb-2 flex flex-wrap items-center gap-2 font-semibold"><x-icon name="o-x-circle" class="h-5 w-5 text-error" aria-hidden="true" />Choques duros <span class="badge badge-error badge-soft font-mono">{{ $total }}</span><span class="text-sm font-normal text-neutral">en {{ count($detail['conflicts']) }} {{ count($detail['conflicts']) === 1 ? 'grupo' : 'grupos' }}</span></h3>
            <ul class="grid gap-2 {{ $wide ?? false ? '@3xl:grid-cols-2' : '' }}">
                @foreach ($detail['conflicts'] as $entry)
                    <li wire:key="{{ $prefix }}-g-{{ $entry['group']['id'] }}" class="grid content-start gap-2 rounded-field border border-base-300 px-3 py-2.5" data-testid="conflict-group-{{ $entry['group']['id'] }}">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <span class="font-mono font-semibold whitespace-nowrap">{{ $group($entry['group']) }}</span>
                            <span class="font-semibold">{{ $entry['group']['subject_name'] }}</span>
                            <span class="text-sm text-neutral">{{ $entry['group']['career_code'] }} · sem. {{ $entry['group']['semester'] }}</span>
                        </div>
                        <ul class="grid gap-1.5">
                            @foreach ($entry['items'] as $item)
                                <li class="flex items-start gap-2" data-testid="conflict-{{ $item['rule'] }}">
                                    <x-core::status-badge tone="error" icon="o-x-circle" :label="$item['rule']" class="mt-px shrink-0 font-mono" />
                                    <span>{{ $item['message'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <div>@include('scheduling::conflicts.fix', ['to' => 'editor', 'group' => $entry['group']['id'], 'label' => 'Corregir en el editor'])</div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @foreach ([
        ['unscheduled', 'Sin horario', 'o-clock', 'editor', 'Acomodar'],
        ['withoutTeacher', 'Sin docente', 'o-user', 'assign', 'Asignar'],
        ['withoutRoom', 'Sin salón', 'o-building-office', 'editor', 'Asignar salón'],
    ] as [$key, $title, $icon, $to, $label])
        @if ($detail[$key] !== [])
            <section class="py-3" aria-labelledby="{{ $prefix }}-{{ $key }}" data-testid="pending-{{ $key }}">
                <h3 id="{{ $prefix }}-{{ $key }}" class="mb-1 flex items-center gap-2 font-semibold"><x-icon :name="$icon" class="h-5 w-5 text-warning" aria-hidden="true" />{{ $title }} <span class="badge badge-warning badge-soft font-mono">{{ count($detail[$key]) }}</span></h3>
                <ul class="grid {{ $wide ?? false ? '@2xl:grid-cols-2 @5xl:grid-cols-3' : '' }}">
                    @foreach ($detail[$key] as $g)
                        <li wire:key="{{ $prefix }}-{{ $key }}-{{ $g['id'] }}" class="flex min-w-0 items-center gap-3 border-b border-base-300/60 py-1.5 last:border-b-0">
                            <span class="font-mono text-sm font-semibold whitespace-nowrap">{{ $group($g) }}</span>
                            <span class="min-w-0 flex-1 truncate" title="{{ $g['subject_name'] }}">{{ $g['subject_name'] }}</span>
                            <span class="shrink-0">@include('scheduling::conflicts.fix', ['to' => $to, 'group' => $g['id'], 'label' => $label])</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach

    @if ($detail['conflicts'] === [] && $detail['unscheduled'] === [] && $detail['withoutTeacher'] === [] && $detail['withoutRoom'] === [])
        <p class="flex items-center gap-2 py-4 text-success"><x-icon name="o-check-circle" class="h-5 w-5" aria-hidden="true" />Todo listo para cerrar.</p>
    @endif
</div>
