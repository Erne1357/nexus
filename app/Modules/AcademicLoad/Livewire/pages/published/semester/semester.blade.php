{{--
    Un semestre publicado, variante A «Fichas por materia» (DESIGN.md, «Consulta publicada»): «← Horarios», el nombre de
    la carrera con su semestre, materias y grupos, la fecha de actualización, los selectores de carrera, retícula y
    semestre con la leyenda de iconos, y una sección por materia en orden de clave: su franja de color (`--chain`, un
    token por materia del semestre), clave en Mono, nombre, créditos y «N grupos», y debajo las fichas de sus grupos en
    una cuadrícula de 330 px mínimo (a todo el ancho en celular). La ficha lleva «CLAVE · grupo», el docente (o «Por
    asignar») y la mini-semana: una celda por día del perfil con las horas y el salón de cada sesión; un día sin sesión va
    vacío y tenue. La ficha no es un enlace; el docente y cada salón sí.
--}}
@use('App\Modules\Core\Support\WeekGrid')
@use('App\Modules\AcademicLoad\Queries\PublishedWeek')
@php
    $tint = 'bg-[color-mix(in_oklab,var(--chain)_5%,var(--color-base-100))] shadow-[inset_4px_0_0_var(--chain)]';
    $dayHead = 'bg-[color-mix(in_oklab,var(--color-secondary)_40%,var(--color-base-100))]';
    $plural = fn (int $n, string $one, string $many): string => $n.' '.($n === 1 ? $one : $many);
@endphp
<div class="grid gap-5">
    <div class="grid gap-3">
        <a href="{{ $backUrl }}" wire:navigate class="btn btn-ghost btn-sm -ms-2.5 justify-self-start max-md:h-11"><x-icon name="o-arrow-left" class="h-4 w-4" aria-hidden="true" />Horarios</a>

        <div class="grid min-w-0 gap-1.5">
            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <h1 class="min-w-0 text-2xl font-bold leading-tight md:text-3xl" data-testid="published-career-name">{{ $career['name'] }}</h1>
                @if ($pdfUrl !== null)
                    <a href="{{ $pdfUrl }}" class="btn btn-outline btn-sm max-md:h-11" data-testid="published-pdf"><x-icon name="o-arrow-down-tray" class="h-4 w-4" aria-hidden="true" />Descargar PDF</a>
                @endif
            </div>
            <p class="flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                <span class="font-medium text-base-content">Semestre {{ $semester }}</span>
                <span>{{ $plural(count($subjects), 'materia', 'materias') }} · {{ $plural($groupCount, 'grupo', 'grupos') }}</span>
                <span>Retícula <span class="font-mono">{{ $currentPlan['plan']['code'] }}</span></span>
            </p>
            @if ($updated !== '')
                <p class="flex items-center gap-1.5 text-sm text-neutral" data-testid="published-updated"><x-icon name="o-clock" class="h-4 w-4" aria-hidden="true" />Actualizado el {{ $updated }}</p>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-x-4 gap-y-3 border-y border-base-300 py-4 max-md:[&_select]:w-full max-md:[&>*]:w-full">
        <div class="md:min-w-56"><x-select label="Carrera" wire:model.live="carrera" :options="$careerOptions" data-testid="published-career" /></div>
        <div class="md:min-w-40">
            @if (count($plans) > 1)
                <x-select label="Retícula" wire:model.live="plan" :options="$planOptions" class="font-mono" data-testid="published-plan" />
            @else
                <span class="fieldset-legend">Retícula</span>
                <span class="flex h-10 items-center font-mono">{{ $currentPlan['plan']['code'] }}</span>
            @endif
        </div>
        <div class="md:min-w-44"><x-select label="Semestre" wire:model.live="semester" :options="$semesterOptions" data-testid="published-semester" /></div>
        <span class="hidden flex-1 md:block"></span>
        <div class="flex flex-wrap gap-x-[18px] gap-y-1 text-[13px] text-neutral" aria-label="Iconos">
            <span class="inline-flex items-center gap-1.5"><x-icon name="o-beaker" class="h-3.5 w-3.5" aria-hidden="true" />Práctica</span>
            <span class="inline-flex items-center gap-1.5"><x-icon name="o-computer-desktop" class="h-3.5 w-3.5" aria-hidden="true" />Virtual</span>
            <span>Sin icono: teoría</span>
        </div>
    </div>

    @if ($subjects === [])
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="published-empty">No hay grupos publicados para este semestre.</div>
    @else
        <div class="grid gap-8" data-testid="published-subjects">
            @foreach ($subjects as $entry)
                @php
                    $subject = $entry['subject'];
                    $chain = PublishedWeek::chainClass($chains, $subject['code']);
                @endphp
                <section class="{{ $chain }} grid gap-3" aria-labelledby="subject-{{ $subject['code'] }}" data-subject="{{ $subject['code'] }}" wire:key="subject-{{ $subject['code'] }}">
                    <div class="grid grid-cols-[6px_minmax(0,1fr)] items-center gap-x-3 md:flex md:flex-wrap md:gap-x-3.5 md:gap-y-1">
                        <span class="row-span-3 min-h-14 self-stretch rounded-xs bg-(--chain) md:row-auto md:h-[30px] md:min-h-0 md:w-1.5 md:self-auto" aria-hidden="true"></span>
                        <span class="font-mono text-[15px] font-semibold">{{ $subject['code'] }}</span>
                        <h2 id="subject-{{ $subject['code'] }}" class="text-xl font-bold leading-tight">{{ $subject['name'] }}</h2>
                        <span class="mt-1 flex items-center gap-3 md:contents">
                            <span class="text-neutral">{{ $plural($subject['credits'], 'crédito', 'créditos') }}</span>
                            <span class="badge badge-sm border-base-300 bg-base-100 font-medium md:ms-auto">{{ $plural(count($entry['groups']), 'grupo', 'grupos') }}</span>
                        </span>
                    </div>

                    <div class="grid gap-3 max-md:grid-cols-1 md:grid-cols-[repeat(auto-fill,minmax(330px,1fr))]">
                        @foreach ($entry['groups'] as $group)
                            <article class="{{ $tint }} grid min-w-0 gap-2.5 rounded-box border border-base-300 py-3 ps-[18px] pe-3.5 pb-3.5" data-group="{{ $group->label() }}" wire:key="group-{{ $group->id }}">
                                <div class="grid gap-0.5">
                                    <div class="font-mono text-[17px] font-semibold">{{ $subject['code'] }} <span class="font-normal text-neutral">·</span> {{ $group->code }}</div>
                                    <div @class(['flex min-w-0 items-center gap-1.5', 'text-neutral' => $group->teacher === null])>
                                        <x-icon name="o-user" class="h-4 w-4 shrink-0 text-neutral" aria-hidden="true" />
                                        @if ($group->teacher === null)
                                            <span>Por asignar</span>
                                        @else
                                            <a href="{{ route('published.teacher', ['periodo' => $context->term->code, 'campus' => $context->campus->code, 'docente' => $group->teacher['id']]) }}" wire:navigate class="truncate hover:underline focus-visible:outline-2 focus-visible:outline-primary">{{ $group->teacher['name'] }}</a>
                                        @endif
                                    </div>
                                </div>

                                @if (! $profile->usesTimeBlocks)
                                    <p class="text-sm text-neutral">Sin horario fijo</p>
                                @else
                                    <div class="grid gap-1" style="grid-template-columns: repeat({{ count($days) }}, minmax(0, 1fr))" role="group" aria-label="Semana del grupo">
                                        @foreach ($days as $day)
                                            @php
                                                $sessions = array_values(array_filter($group->sessions, fn ($session): bool => $session->day === $day['day']));
                                                usort($sessions, fn ($a, $b): int => strcmp($a->startsAt, $b->startsAt));
                                            @endphp
                                            @if ($sessions === [])
                                                <div class="grid min-h-[72px] grid-rows-[auto_1fr] overflow-hidden rounded-xs border border-dashed border-base-300" role="img" aria-label="{{ $day['name'] }}: sin clase">
                                                    <div class="flex h-5 items-center px-1.5 text-[11.5px] font-semibold text-neutral opacity-60" aria-hidden="true">{{ $day['short'] }}</div>
                                                    <div></div>
                                                </div>
                                            @else
                                                <div class="grid min-h-[72px] grid-rows-[auto_1fr] overflow-hidden rounded-xs border border-base-300 bg-base-100">
                                                    <div class="{{ $dayHead }} flex h-5 items-center justify-between px-1.5 text-[11.5px] font-semibold text-neutral">
                                                        <span title="{{ $day['name'] }}">{{ $day['short'] }}</span>
                                                    </div>
                                                    <div class="grid content-start gap-1 px-1.5 pt-[3px] pb-1 font-mono text-[11.5px]/[1.3] tabular-nums">
                                                        @foreach ($sessions as $session)
                                                            <div class="grid min-w-0" title="{{ $day['name'] }} · {{ $session->kindLabel }}">
                                                                <span class="flex items-center justify-between gap-0.5">{{ WeekGrid::clock($session->startsAt) }}@if ($session->kind !== 'teoria')<x-academic-load::published.kind-icon :kind="$session->kind" :label="$session->kindLabel" />@endif</span>
                                                                <span>{{ WeekGrid::clock($session->endsAt) }}</span>
                                                                @if ($session->room !== null)
                                                                    <a href="{{ route('published.room', ['periodo' => $context->term->code, 'campus' => $context->campus->code, 'salon' => $session->room['id']]) }}" wire:navigate class="mt-0.5 font-semibold break-words hover:underline focus-visible:outline-2 focus-visible:outline-primary">{{ $session->room['code'] }}</a>
                                                                @elseif ($session->kind === 'virtual')
                                                                    <b class="mt-0.5 font-semibold">Virtual</b>
                                                                @elseif ($profile->usesRooms)
                                                                    <b class="mt-0.5 font-semibold break-words text-neutral">Por asignar</b>
                                                                @endif
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
