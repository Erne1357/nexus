{{--
    «Ver por docente o carrera» (drawer): la versión elegida, su motivo y contra cuál se compara; el filtro segmentado
    Docente | Carrera (en el navegador) y, para cada docente o carrera afectado (VersionChanges::byTeacher() y
    byCareer()), las líneas que le dijo el aviso de la republicación (RA-05, RA-06), y por dónde les llegó a los docentes:
    la campana y el correo que de verdad salieron (LoadVersionsQuery::teacherNoticeChannels(); ensayo 8.11, E-04). Una
    versión de antes de la tarea 8.4 no avisó: lo dice. Al pie, cuándo la vieron docentes y alumnos.
--}}
@php
    $number = $selected['version'];
    $reason = $selected['reason'];
    $channels = array_keys(array_filter(['por la campana' => $affected['channels']['bell'], 'por correo' => $affected['channels']['mail']]));
    $teachersNote = match (true) {
        $selected['legacy'] => 'Lo que cambió para cada docente. Esta versión se publicó antes de los avisos: nadie lo recibió.',
        $channels === [] => 'Lo que cambió para cada docente afectado. No ha salido ningún aviso por la campana ni por correo.',
        count($channels) === 2 => 'Lo que se le avisó a cada docente afectado, por la campana y el correo.',
        default => 'Lo que se le avisó a cada docente afectado, '.$channels[0].'.',
    };
    $views = [
        'docente' => ['Docente', count($affected['teachers'])],
        'carrera' => ['Carrera', count($affected['careers'])],
    ];
@endphp
<div class="flex h-dvh flex-col" x-data="{ by: 'docente' }" data-testid="affected-panel">
    @include('academic-load::forecast-accuracy.drawer-header', [
        'id' => 'affected-title',
        'title' => 'Versión '.$number.': por docente o carrera',
        'subtitle' => 'Publicada el '.$day($selected['publishedAt']).' · '.$clock($selected['publishedAt']).' por '.$selected['publisher'],
    ])

    <div class="grid flex-1 content-start gap-4 overflow-y-auto px-5 py-4 max-md:px-3.5">
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 text-sm text-neutral">
            <x-core::status-badge :tone="$reason->tone()" :label="$reason->label()" :icon="$reason->icon()" />
            <span>{{ number_format($selected['groups']) }} grupos · {{ number_format($selected['sessions']) }} sesiones · contra la versión {{ $number - 1 }}</span>
        </div>

        <div role="group" aria-label="Ver por" class="join grid w-full grid-cols-2 md:inline-grid md:w-auto md:justify-self-start" data-testid="affected-by">
            @foreach ($views as $value => [$name, $count])
                <button type="button" x-on:click="by = '{{ $value }}'" x-bind:aria-pressed="by === '{{ $value }}' ? 'true' : 'false'" x-bind:class="by === '{{ $value }}' && 'btn-primary'"
                    class="btn join-item gap-2 max-md:h-11" data-testid="affected-by-{{ $value }}">
                    {{ $name }} <span class="font-mono text-[12.5px]">{{ $count }}</span>
                </button>
            @endforeach
        </div>

        <section x-show="by === 'docente'" class="grid gap-2" aria-label="Por docente" data-testid="affected-teachers">
            <p class="text-[12.5px] text-neutral" data-testid="affected-teachers-note">{{ $teachersNote }}</p>
            @forelse ($affected['teachers'] as $teacher)
                <article class="grid gap-1.5 border-b border-base-300 pb-3 last:border-b-0" wire:key="affected-teacher-{{ $loop->index }}">
                    <h3 class="flex items-center gap-2 font-semibold"><x-icon name="o-user" class="h-4 w-4 shrink-0 text-neutral" aria-hidden="true" />{{ $teacher['name'] }}</h3>
                    <ul class="grid list-disc gap-1 ps-10 text-sm marker:text-neutral">
                        @foreach ($teacher['lines'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </article>
            @empty
                <p class="text-sm text-neutral">Ningún docente tuvo cambios en esta versión.</p>
            @endforelse
        </section>

        <section x-show="by === 'carrera'" x-cloak class="grid gap-2" aria-label="Por carrera" data-testid="affected-careers">
            <p class="text-[12.5px] text-neutral">{{ $selected['legacy'] ? 'Lo que cambió para cada carrera. Esta versión se publicó antes de los avisos: nadie lo recibió.' : 'Lo que se le avisó a la coordinación de cada carrera afectada.' }}</p>
            @forelse ($affected['careers'] as $career)
                <article class="grid gap-1.5 border-b border-base-300 pb-3 last:border-b-0" wire:key="affected-career-{{ $loop->index }}">
                    <h3 class="flex flex-wrap items-baseline gap-x-2 font-semibold">
                        <span class="font-mono">{{ $career['code'] }}</span>
                        @if ($career['name'] !== null)
                            <span>{{ $career['name'] }}</span>
                        @endif
                    </h3>
                    <ul class="grid list-disc gap-1 ps-10 text-sm marker:text-neutral">
                        @foreach ($career['lines'] as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </article>
            @empty
                <p class="text-sm text-neutral">Ninguna carrera tuvo cambios en esta versión.</p>
            @endforelse
        </section>
    </div>

    <footer class="border-t border-base-300 px-5 py-3 text-[12.5px] text-neutral max-md:px-3.5">
        @if ($until !== null)
            Lo que vieron docentes y alumnos del {{ $day($selected['publishedAt']) }} al {{ $day($until) }}.
        @else
            Lo que ven docentes y alumnos desde el {{ $day($selected['publishedAt']) }}.
        @endif
    </footer>
</div>
