{{--
    La versión elegida, columna derecha: «Qué cambió contra la versión N − 1» por tipo (VersionChanges::byKind(): nuevos,
    cancelados, horario, docente, salón, cupo y cupos por carrera), cada grupo con su clave, su campus si la modalidad
    tiene varios y su materia, y debajo su detalle o lo que tenía → lo que quedó. «Ver por docente o carrera» abre el
    drawer. La primera versión no tiene contra qué compararse y lo dice con sus cifras.
--}}
@php
    $number = $selected['version'];
    $multiCampus = $campuses->count() > 1;
@endphp
<div class="grid min-w-0 content-start gap-3.5 p-4 md:p-5" data-testid="version-changes">
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <h3 class="text-base font-bold leading-tight">{{ $number > 1 ? 'Qué cambió contra la versión '.($number - 1) : 'Contenido' }}</h3>
        @if ($sections !== [])
            <x-button label="Ver por docente o carrera" icon="o-eye" class="btn-outline btn-sm max-md:h-11 max-md:w-full" wire:click="openAffected" spinner="openAffected" data-testid="open-affected" />
        @endif
    </div>

    @if ($number === 1)
        <div class="flex gap-3 rounded-box border border-base-300 bg-base-200/60 px-4 py-3" data-testid="first-version">
            <x-icon name="o-information-circle" class="mt-0.5 h-5 w-5 shrink-0 text-info" aria-hidden="true" />
            <p class="grid gap-0.5 text-sm">
                <strong>Primera versión: no hay contra qué comparar.</strong>
                <span class="text-neutral">{{ number_format($selected['groups']) }} grupos y {{ number_format($selected['sessions']) }} sesiones de {{ number_format($selected['teachers']) }} docentes.</span>
            </p>
        </div>
    @elseif ($diff === null)
        <div class="flex gap-3 rounded-box border border-base-300 bg-base-200/60 px-4 py-3" data-testid="no-diff">
            <x-icon name="o-information-circle" class="mt-0.5 h-5 w-5 shrink-0 text-info" aria-hidden="true" />
            <p class="text-sm">Falta la versión {{ $number - 1 }} para compararla.</p>
        </div>
    @elseif ($sections === [])
        <div class="flex gap-3 rounded-box border border-base-300 bg-base-200/60 px-4 py-3" data-testid="no-changes">
            <x-icon name="o-check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-success" aria-hidden="true" />
            <p class="text-sm">Ningún grupo cambió contra la versión {{ $number - 1 }}.</p>
        </div>
    @else
        <div class="grid gap-4">
            @foreach ($sections as $section)
                <section aria-labelledby="changes-{{ $section['kind'] }}-title" data-testid="changes-{{ $section['kind'] }}" wire:key="changes-{{ $number }}-{{ $section['kind'] }}">
                    <h4 id="changes-{{ $section['kind'] }}-title" class="flex items-center gap-1.5 border-b border-base-300 pb-1.5 text-[13.5px] font-bold">
                        <x-icon :name="$section['icon']" class="h-4 w-4 shrink-0 text-neutral" aria-hidden="true" />
                        {{ $section['label'] }}
                        <span class="ms-0.5 font-mono font-semibold text-neutral">{{ count($section['rows']) }}</span>
                    </h4>
                    <ul>
                        @foreach ($section['rows'] as $row)
                            @php
                                $group = $row['group'];
                            @endphp
                            <li class="grid gap-0.5 border-b border-dashed border-base-300 py-2 last:border-b-0">
                                <p class="flex min-w-0 items-center gap-2">
                                    <span class="font-mono text-[13px] font-semibold whitespace-nowrap">{{ $group['subject_code'] }} · {{ $group['code'] }}</span>
                                    @if ($multiCampus && $group['campus'] !== null)
                                        <x-core::campus-tag :campus="$campuses[$group['campus']] ?? (object) ['code' => $group['campus'], 'name' => $group['campus']]" />
                                    @endif
                                    <span class="min-w-0 truncate text-[13.5px]" title="{{ $group['subject_name'] }}">{{ $group['subject_name'] }}</span>
                                </p>
                                @if ($row['detail'] !== null)
                                    <p class="text-[13px] text-neutral">{{ $row['detail'] }}</p>
                                @else
                                    <p class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[13px]">
                                        <span class="text-neutral">{{ $row['from'] }}</span>
                                        <x-icon name="o-arrow-long-right" class="h-3.5 w-3.5 shrink-0 text-neutral" aria-hidden="true" />
                                        <span class="sr-only">pasa a</span>
                                        <span class="font-semibold">{{ $row['to'] }}</span>
                                    </p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</div>
