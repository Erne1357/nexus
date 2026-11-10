{{--
    Inicio de la consulta pública (DESIGN.md, «Consulta publicada», variante A). «Por carrera» es lo principal: una
    fila por carrera con su clave en Mono, su retícula (un select si tiene varias) y los botones de semestre que tienen
    grupos (44 px en celular). Desde 1200 px, a la derecha, «Por docente» y «Por salón» con un buscador sin acentos;
    debajo, en el resto. Sin publicaciones, el estado vacío. El público no ve cupos, versiones ni avisos de lo que falta:
    solo quien tiene sesión y permiso ve la franja «Sin publicar».
--}}
<div class="grid gap-5">
    @if ($context === null)
        <div>
            <h1 class="text-2xl font-bold leading-tight md:text-3xl">Horarios</h1>
        </div>
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="published-empty">Todavía no hay horarios publicados.</div>
    @else
        <div class="grid gap-1.5">
            <h1 class="text-2xl font-bold leading-tight md:text-3xl">Horarios</h1>
            <p class="flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                <span class="font-medium text-base-content" data-testid="published-term-name">{{ $context->term->name }}</span>
                <span data-testid="published-campus-name">{{ $context->campus->name }}</span>
            </p>
            @if ($updated !== '')
                <p class="flex items-center gap-1.5 text-sm text-neutral" data-testid="published-updated"><x-icon name="o-clock" class="h-4 w-4" aria-hidden="true" />Actualizado el {{ $updated }}</p>
            @endif
        </div>

        @if ($unpublished !== [])
            <div class="rounded-field border border-base-300 bg-base-100 px-3 py-2 text-sm text-neutral" data-testid="published-unpublished" role="note">
                <span class="font-medium text-base-content">Sin publicar:</span>
                {{ implode(', ', array_map(fn (array $load): string => $load['department'].' · '.$load['status'], $unpublished)) }}
            </div>
        @endif

        @if ($staffExports !== null && $staffExports['departments'] !== [])
            <section class="flex flex-wrap items-end gap-x-4 gap-y-3 rounded-box border border-base-300 bg-base-100 px-4 py-3" aria-labelledby="staff-exports" data-testid="published-staff-exports">
                <div class="grid gap-0.5 md:me-auto">
                    <h2 id="staff-exports" class="font-semibold">Exportes del personal</h2>
                    <p class="text-sm text-neutral">Todo lo publicado de {{ $context->campus->name }} en {{ $context->term->name }}.</p>
                </div>
                <a href="{{ $staffExports['excel'] }}" class="btn btn-outline btn-sm max-md:h-11 max-md:w-full" data-testid="published-export-excel"><x-icon name="o-table-cells" class="h-4 w-4" aria-hidden="true" />Excel del campus</a>
                @if ($staffExports['sii'] !== null)
                    <a href="{{ $staffExports['sii'] }}" class="btn btn-outline btn-sm max-md:h-11 max-md:w-full" data-testid="published-export-sii"><x-icon name="o-archive-box-arrow-down" class="h-4 w-4" aria-hidden="true" />Archivos para el SII</a>
                @endif
                <form method="get" action="{{ $staffExports['department'] }}" class="flex flex-wrap items-end gap-2 max-md:w-full">
                    <label class="grid gap-1 max-md:w-full">
                        <span class="text-xs font-medium uppercase tracking-wider text-neutral">Departamento</span>
                        <select name="departamento" class="select select-sm max-md:h-11 max-md:w-full" data-testid="published-export-department">
                            @foreach ($staffExports['departments'] as $department)
                                <option value="{{ $department['id'] }}">{{ $department['code'] }} · {{ $department['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="btn btn-outline btn-sm max-md:h-11 max-md:w-full"><x-icon name="o-arrow-down-tray" class="h-4 w-4" aria-hidden="true" />PDF del departamento</button>
                </form>
            </section>
        @endif

        @if ($careers === [])
            <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="published-empty">Todavía no hay horarios publicados para {{ $context->campus->name }} en {{ $context->term->name }}.</div>
        @else
            <div class="grid items-start gap-10 desktop:grid-cols-[minmax(0,1fr)_380px] desktop:gap-14">
                <section aria-labelledby="by-career">
                    <h2 id="by-career" class="text-xl font-bold leading-tight">Tu carrera y semestre</h2>
                    <p class="mt-1 mb-3 text-sm text-neutral">Elige el semestre que cursas y verás sus materias, grupos, docentes y salones.</p>

                    <div class="border-t border-base-300" data-testid="published-careers">
                        @foreach ($careers as $entry)
                            @php
                                $planCode = $plans[$entry['career']['code']] ?? $entry['plans'][0]['plan']['code'];
                                $selected = array_find($entry['plans'], fn (array $plan): bool => $plan['plan']['code'] === $planCode) ?? $entry['plans'][0];
                            @endphp
                            <div class="grid gap-3 border-b border-base-300 py-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-center md:gap-6" wire:key="career-{{ $entry['career']['code'] }}" data-career="{{ $entry['career']['code'] }}">
                                <div class="grid min-w-0 gap-0.5">
                                    <b class="text-base font-semibold">{{ $entry['career']['name'] }}</b>
                                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-sm text-neutral">
                                        <span class="font-mono">{{ $entry['career']['code'] }}</span>
                                        @if (count($entry['plans']) > 1)
                                            <label class="flex items-center gap-2 font-mono">Retícula
                                                <select wire:model.live="plans.{{ $entry['career']['code'] }}" aria-label="Retícula de {{ $entry['career']['code'] }}" class="select select-sm font-mono max-md:h-11">
                                                    @foreach ($entry['plans'] as $plan)
                                                        <option value="{{ $plan['plan']['code'] }}" @selected($plan['plan']['code'] === $selected['plan']['code'])>{{ $plan['plan']['code'] }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        @else
                                            <span>Retícula <span class="font-mono">{{ $selected['plan']['code'] }}</span></span>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Semestre de {{ $entry['career']['code'] }}">
                                    @foreach ($selected['semesters'] as $semester)
                                        <a href="{{ route('published.semester', ['periodo' => $context->term->code, 'campus' => $context->campus->code, 'carrera' => $entry['career']['code'], 'reticula' => $selected['plan']['code'], 'semestre' => $semester]) }}" wire:navigate
                                            aria-label="{{ $entry['career']['code'] }}, semestre {{ $semester }}"
                                            class="btn btn-outline btn-square h-11 w-11 border-base-300 bg-base-100 font-mono font-semibold hover:btn-primary md:h-10 md:w-10">{{ $semester }}</a>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <aside class="grid gap-7">
                    <section class="grid gap-2" aria-labelledby="by-teacher">
                        <h2 id="by-teacher" class="text-xl font-bold leading-tight">Por docente</h2>
                        <label class="relative block">
                            <span class="sr-only">Buscar docente</span>
                            <x-icon name="o-magnifying-glass" class="pointer-events-none absolute start-3 top-3 h-5 w-5 text-neutral" aria-hidden="true" />
                            <input type="search" wire:model.live.debounce.250ms="teacherSearch" placeholder="Nombre del docente" autocomplete="off" data-testid="published-teacher-search" class="input w-full ps-10 max-md:h-11">
                        </label>
                        @if (trim($teacherSearch) !== '')
                            @if ($teachers === [])
                                <p class="text-sm text-neutral">Ningún docente coincide.</p>
                            @else
                                <ul class="grid" data-testid="published-teacher-results">
                                    @foreach ($teachers as $teacher)
                                        <li><a href="{{ route('published.teacher', ['periodo' => $context->term->code, 'campus' => $context->campus->code, 'docente' => $teacher['id']]) }}" wire:navigate class="flex min-h-11 items-center gap-2 rounded-field px-2 hover:bg-base-300/50 focus-visible:outline-2 focus-visible:outline-primary"><x-icon name="o-user" class="h-4 w-4 shrink-0 text-neutral" aria-hidden="true" />{{ $teacher['name'] }}</a></li>
                                    @endforeach
                                </ul>
                            @endif
                        @else
                            <p class="text-sm text-neutral">Escribe el nombre para ver su horario.</p>
                        @endif
                    </section>

                    @if ($usesRooms)
                        <section class="grid gap-2" aria-labelledby="by-room">
                            <h2 id="by-room" class="text-xl font-bold leading-tight">Por salón</h2>
                            <label class="relative block">
                                <span class="sr-only">Buscar salón</span>
                                <x-icon name="o-map-pin" class="pointer-events-none absolute start-3 top-3 h-5 w-5 text-neutral" aria-hidden="true" />
                                <input type="search" wire:model.live.debounce.250ms="roomSearch" placeholder="Clave del salón" autocomplete="off" data-testid="published-room-search" class="input w-full ps-10 max-md:h-11">
                            </label>
                            @if (trim($roomSearch) !== '')
                                @if ($rooms === [])
                                    <p class="text-sm text-neutral">Ningún salón coincide.</p>
                                @else
                                    <ul class="grid" data-testid="published-room-results">
                                        @foreach ($rooms as $room)
                                            <li><a href="{{ route('published.room', ['periodo' => $context->term->code, 'campus' => $context->campus->code, 'salon' => $room['id']]) }}" wire:navigate class="flex min-h-11 flex-wrap items-center gap-x-2 rounded-field px-2 hover:bg-base-300/50 focus-visible:outline-2 focus-visible:outline-primary"><x-icon name="o-map-pin" class="h-4 w-4 shrink-0 text-neutral" aria-hidden="true" /><b class="font-mono font-semibold">{{ $room['code'] }}</b>@if ($room['name'] !== $room['code'])<span>{{ $room['name'] }}</span>@endif<span class="text-sm text-neutral">{{ $room['building'] }}</span></a></li>
                                        @endforeach
                                    </ul>
                                @endif
                            @else
                                <p class="text-sm text-neutral">Escribe la clave para ver su horario.</p>
                            @endif
                        </section>
                    @endif
                </aside>
            </div>
        @endif
    @endif
</div>
