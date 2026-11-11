{{--
    La vista de un docente o de un salón publicados (DESIGN.md, «Consulta publicada»; `$mode` es `teacher` o `room`):
    «← Horarios», el título, «N grupos · N horas a la semana» con la fecha de actualización, y la semana. Desde 768 px,
    la semana del perfil del campus (extendida a lo que cae fuera): una columna por día, tan ancha como sus carriles
    (cada uno de al menos 7rem), un renglón de 3.5rem por bloque y cada sesión como un bloque con la franja y el tinte de
    su materia, «CLAVE · grupo» en Mono, la materia, el salón (docente) o el docente (salón) y el icono de su tipo; las
    que se empalman van en carriles (WeekGrid::lay()). Desde 1200 px, a la derecha, la lista de sus grupos. Bajo 768 px,
    los días en botones (`?dia=`) y la lista de horas del día. Recrea la marca de la semana del editor de Scheduling,
    que AcademicLoad no importa. Sin `uses_time_blocks` no hay semana: solo la lista de grupos.
--}}
@use('App\Modules\Core\Support\WeekGrid')
@use('App\Modules\AcademicLoad\Queries\PublishedWeek')
@php
    $teacherMode = $mode === 'teacher';
    $headBg = 'bg-[color-mix(in_oklab,var(--color-secondary)_40%,var(--color-base-100))]';
    $chainOf = fn (string $code): string => PublishedWeek::chainClass($chains, $code);
    $tint = 'bg-[color-mix(in_oklab,var(--chain)_9%,var(--color-base-100))] shadow-[inset_4px_0_0_var(--chain)]';
    $plural = fn (int $n, string $one, string $many): string => $n.' '.($n === 1 ? $one : $many);
    $roomUrl = fn (string $id): string => route('published.room', ['periodo' => $context->term->code, 'campus' => $context->campus->code, 'salon' => $id]);
    $teacherUrl = fn (string $id): string => route('published.teacher', ['periodo' => $context->term->code, 'campus' => $context->campus->code, 'docente' => $id]);
    $dayName = fn (int $day): string => array_find($grid->days, fn (array $row): bool => $row['day'] === $day)['name'] ?? '';
    $placeOf = function ($group): ?array {
        $placement = array_find($group->placements, fn (array $row): bool => $row['is_owner']) ?? ($group->placements[0] ?? null);

        return $placement === null ? null : ['career' => $placement['career']['code'], 'semester' => $placement['semester']];
    };
    $metaOf = fn (array $item): array => $teacherMode ? ['room', $item['room']] : ['teacher', $item['teacher']];
@endphp
<div class="grid gap-5">
    <div class="grid gap-3">
        <a href="{{ $backUrl }}" wire:navigate class="btn btn-ghost btn-sm -ms-2.5 justify-self-start max-md:h-11"><x-icon name="o-arrow-left" class="h-4 w-4" aria-hidden="true" />Horarios</a>

        <div class="grid min-w-0 gap-1.5">
            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <h1 class="min-w-0 text-2xl font-bold leading-tight md:text-3xl" data-testid="published-title">{{ $title }}</h1>
                <a href="{{ $pdfUrl }}" class="btn btn-outline btn-sm max-md:h-11" data-testid="published-pdf"><x-icon name="o-arrow-down-tray" class="h-4 w-4" aria-hidden="true" />Descargar PDF</a>
            </div>
            <p class="flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                @if ($subtitle !== null)
                    <span class="font-medium text-base-content">{{ $subtitle }}</span>
                @endif
                <span data-testid="published-facts"><span class="font-medium text-base-content">{{ $plural(count($groups), 'grupo', 'grupos') }}</span> · <span class="font-mono">{{ $hours }}</span> {{ $hours === 1 ? 'hora' : 'horas' }} a la semana</span>
                <span>{{ $context->campus->name }}</span>
            </p>
            @if ($updated !== '')
                <p class="flex items-center gap-1.5 text-sm text-neutral" data-testid="published-updated"><x-icon name="o-clock" class="h-4 w-4" aria-hidden="true" />Actualizado el {{ $updated }}</p>
            @endif
        </div>
    </div>

    <div class="grid items-start gap-6 desktop:grid-cols-[minmax(0,1fr)_340px]">
        @if ($profile->usesTimeBlocks)
            <div class="min-w-0">
                {{-- La semana, desde 768 px. --}}
                <div class="max-h-[calc(100dvh-5rem)] overflow-auto rounded-box border border-base-300 bg-base-100 [scrollbar-color:var(--color-base-300)_transparent] [scrollbar-width:thin] max-md:hidden" data-testid="week">
                    <div class="grid min-w-[calc(3.25rem+var(--units)*var(--lane-min))] grid-cols-[3.25rem_var(--cols)] [--lane-min:7rem]"
                        style="--cols: {{ implode(' ', array_map(fn (int $count): string => "minmax(calc({$count}*var(--lane-min)),{$count}fr)", $lanes)) }}; --units: {{ array_sum($lanes) }}"
                        role="group" aria-label="Semana de {{ mb_strtolower($grid->days[0]['name'] ?? '') }} a {{ mb_strtolower(array_last($grid->days)['name'] ?? '') }}">
                        <div class="{{ $headBg }} sticky top-0 left-0 z-[7] border-e border-b border-base-300"></div>
                        @foreach ($grid->days as $index => $day)
                            <div @class([$headBg, 'sticky top-0 z-[6] flex min-h-10 min-w-0 items-center border-b border-base-300 px-2.5 py-1.5', 'border-l' => $index > 0])><b class="truncate font-semibold">{{ $day['name'] }}</b></div>
                        @endforeach

                        <div class="sticky left-0 z-[5] border-e border-base-300 bg-base-100" aria-hidden="true">
                            @foreach ($grid->blocks as $block)
                                <div class="h-14 pe-2 pt-1 text-end font-mono text-xs text-neutral tabular-nums">{{ $block['label'] }}</div>
                            @endforeach
                        </div>

                        @foreach ($grid->days as $index => $day)
                            <div @class(['relative min-w-0 border-base-300', 'border-l' => $index > 0]) role="group" aria-label="{{ $day['name'] }}">
                                @foreach ($grid->blocks as $h => $block)
                                    <div @class(['h-14', 'border-t border-base-300' => $h > 0])></div>
                                @endforeach

                                @foreach ($laid[$day['day']] as $item)
                                    @php
                                        [$metaKind, $meta] = $metaOf($item);
                                    @endphp
                                    <div class="{{ $chainOf($item['subject_code']) }} {{ $tint }} absolute top-[calc(var(--top)*3.5rem+1px)] left-[calc(var(--lane)*100%/var(--lanes)+2px)] z-[2] flex h-[calc(var(--span)*3.5rem-2px)] w-[calc(100%/var(--lanes)-4px)] min-w-0 flex-col overflow-hidden rounded-field border border-base-300 py-0.5 ps-2 pe-1 text-left text-[11.5px] leading-[1.25]"
                                        style="--top: {{ $item['top'] }}; --span: {{ $item['span'] }}; --lane: {{ $item['lane'] }}; --lanes: {{ $item['lanes'] }}"
                                        data-session="{{ $item['title'] }}" aria-label="{{ $item['title'] }}, {{ $item['subject'] }}, {{ $dayName($item['day']) }} {{ WeekGrid::range($item['starts_at'], $item['ends_at']) }}">
                                        <span class="truncate font-mono font-semibold [word-spacing:-0.3em]">{{ $item['title'] }}</span>
                                        <span class="truncate">{{ $item['subject'] }}</span>
                                        <span class="flex items-center justify-between gap-1 text-neutral">
                                            @if ($metaKind === 'room')
                                                @if ($meta !== null)
                                                    <a href="{{ $roomUrl($meta['id']) }}" wire:navigate class="truncate font-mono hover:underline focus-visible:outline-2 focus-visible:outline-primary">{{ $meta['code'] }}</a>
                                                @elseif ($item['kind'] === 'virtual')
                                                    <span class="truncate font-mono">Virtual</span>
                                                @elseif ($profile->usesRooms)
                                                    <span class="truncate font-mono">Por asignar</span>
                                                @else
                                                    <span></span>
                                                @endif
                                            @elseif ($meta !== null)
                                                <a href="{{ $teacherUrl($meta['id']) }}" wire:navigate class="truncate hover:underline focus-visible:outline-2 focus-visible:outline-primary">{{ $meta['name'] }}</a>
                                            @else
                                                <span class="truncate">Por asignar</span>
                                            @endif
                                            <x-academic-load::published.kind-icon :kind="$item['kind']" always />
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Los días en botones y la lista de horas del día, bajo 768 px. --}}
                <div class="grid gap-3 md:hidden" data-testid="day-list">
                    <div class="flex gap-1.5" role="group" aria-label="Día">
                        @foreach ($grid->days as $day)
                            <button type="button" wire:click="$set('day', {{ $day['day'] }})" aria-pressed="{{ $day['day'] === $selectedDay ? 'true' : 'false' }}" aria-label="{{ $day['name'] }}"
                                @class(['btn h-11 min-w-0 flex-1 px-0 font-semibold', 'btn-primary' => $day['day'] === $selectedDay, 'border-base-300 bg-base-100' => $day['day'] !== $selectedDay])>{{ mb_substr($day['name'], 0, 3) }}</button>
                        @endforeach
                    </div>

                    @php
                        $today = array_values($laid[$selectedDay] ?? []);
                        usort($today, fn (array $a, array $b): int => [$a['starts_at'], $a['ends_at']] <=> [$b['starts_at'], $b['ends_at']]);
                    @endphp
                    @if ($today === [])
                        <p class="py-6 text-neutral">Sin clases el {{ mb_strtolower($dayName($selectedDay)) }}.</p>
                    @else
                        <div class="grid gap-2">
                            @foreach ($today as $item)
                                @php
                                    [$metaKind, $meta] = $metaOf($item);
                                @endphp
                                <div class="grid grid-cols-[58px_minmax(0,1fr)] items-stretch gap-2.5" data-session="{{ $item['title'] }}">
                                    <div class="grid content-between justify-items-end py-2 font-mono text-[13px]/[1.3] tabular-nums"><b class="font-semibold">{{ WeekGrid::clock($item['starts_at']) }}</b><span class="text-neutral">{{ WeekGrid::clock($item['ends_at']) }}</span></div>
                                    <div class="{{ $chainOf($item['subject_code']) }} {{ $tint }} grid min-w-0 gap-0.5 rounded-field border border-base-300 py-2 ps-4 pe-3">
                                        <span class="font-mono font-semibold">{{ $item['title'] }}</span>
                                        <span>{{ $item['subject'] }}</span>
                                        <span class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[13px] text-neutral">
                                            <span class="inline-flex items-center gap-1"><x-academic-load::published.kind-icon :kind="$item['kind']" always class="[&_svg]:h-3.5 [&_svg]:w-3.5" /></span>
                                            @if ($metaKind === 'room')
                                                @if ($meta !== null)
                                                    <a href="{{ $roomUrl($meta['id']) }}" wire:navigate class="font-mono hover:underline">{{ $meta['code'] }}</a>
                                                @elseif ($item['kind'] === 'virtual')
                                                    <span class="font-mono">Virtual</span>
                                                @elseif ($profile->usesRooms)
                                                    <span class="font-mono">Por asignar</span>
                                                @endif
                                            @elseif ($meta !== null)
                                                <a href="{{ $teacherUrl($meta['id']) }}" wire:navigate class="inline-flex items-center gap-1 hover:underline"><x-icon name="o-user" class="h-3.5 w-3.5" aria-hidden="true" />{{ $meta['name'] }}</a>
                                            @else
                                                <span class="inline-flex items-center gap-1"><x-icon name="o-user" class="h-3.5 w-3.5" aria-hidden="true" />Por asignar</span>
                                            @endif
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @else
            <p class="text-neutral">Sin horario fijo</p>
        @endif

        {{-- Sus grupos, desde 1200 px a la derecha; debajo de la semana en el resto. --}}
        <aside class="grid gap-1 max-desktop:order-2" aria-labelledby="week-groups" data-testid="week-groups">
            <h2 id="week-groups" class="mb-1 text-base font-bold">{{ $plural(count($groups), 'grupo', 'grupos') }}</h2>
            @foreach ($groups as $group)
                @php
                    $place = $placeOf($group);
                @endphp
                <div class="{{ $chainOf($group->subject['code']) }} relative grid gap-0.5 border-t border-base-300 py-3 ps-4 before:absolute before:start-0 before:top-3 before:bottom-3 before:w-1 before:rounded-xs before:bg-(--chain)" data-group="{{ $group->label() }}">
                    <span class="font-mono font-semibold">{{ $group->label() }}</span>
                    <span class="font-medium">{{ $group->subject['name'] }}</span>
                    @if ($teacherMode)
                        @if ($place !== null)
                            <span class="text-[13px] text-neutral"><span class="font-mono">{{ $place['career'] }}</span> · semestre {{ $place['semester'] }}</span>
                        @endif
                    @else
                        <span class="flex items-center gap-1.5 text-[13px] text-neutral"><x-icon name="o-user" class="h-3.5 w-3.5" aria-hidden="true" />
                            @if ($group->teacher !== null)
                                <a href="{{ $teacherUrl($group->teacher['id']) }}" wire:navigate class="hover:underline">{{ $group->teacher['name'] }}</a>
                            @else
                                Por asignar
                            @endif
                        </span>
                    @endif
                    <div class="mt-0.5 grid gap-0.5">
                        @foreach (PublishedWeek::summary($group) as $line)
                            <div class="flex flex-wrap items-center gap-x-2 text-[12.5px]">
                                <x-academic-load::published.kind-icon :kind="$line['kind']" always class="text-neutral [&_svg]:h-3.5 [&_svg]:w-3.5" />
                                <span>{{ $line['days'] }}</span>
                                <span class="font-mono">{{ $line['hours'] }}</span>
                                @if ($line['room'] !== null)
                                    <a href="{{ $roomUrl($line['room']['id']) }}" wire:navigate class="font-mono hover:underline">{{ $line['room']['code'] }}</a>
                                @elseif ($line['kind'] === 'virtual')
                                    <span class="font-mono">Virtual</span>
                                @elseif ($profile->usesRooms)
                                    <span class="font-mono">Por asignar</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </aside>
    </div>
</div>
