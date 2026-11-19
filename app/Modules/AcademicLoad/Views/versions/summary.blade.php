{{--
    La versión elegida, columna izquierda: «Versión N» con su motivo (deducido en una versión de antes de la tarea 8.4) y
    «Vigente»; quién y cuándo la publicó; su comentario y la reapertura o el re-análisis que la originó; tres cifras (lo
    que cambió contra la anterior, o grupos, sesiones y docentes en la primera); a quién se avisó (RA-05, RA-06: los
    afectados del resumen del diff; las de antes de la tarea 8.4 no avisaron), o «Por avisar a» si el aviso todavía no
    sale de la cola (`$notices`, LoadVersionsQuery::noticeChannels(), lo mismo que lee el drawer; ensayo 8.11, F-04), y
    los archivos. Los archivos se arman con lo publicado hoy: solo la versión vigente los descarga y las anteriores dicen
    por qué no.
--}}
@php
    $number = $selected['version'];
    $reason = $selected['reason'];
    $summary = $selected['summary'];
    $isCurrent = $number === $current;
    $label = 'text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral';
    $figures = $summary === null
        ? [['Grupos', number_format($selected['groups']), ''], ['Sesiones', number_format($selected['sessions']), ''], ['Docentes', number_format($selected['teachers']), '']]
        : [
            ['Nuevos', '+'.$summary['added'], $summary['added'] > 0 ? 'text-success' : ''],
            ['Cancelados', '−'.$summary['cancelled'], $summary['cancelled'] > 0 ? 'text-error' : ''],
            ['Con cambios', (string) $summary['changed'], ''],
        ];
    $notified = $summary === null ? [] : [
        ['o-user', $summary['teachers_affected'], 'docente', 'docentes'],
        ['o-academic-cap', $summary['careers_affected'], 'carrera', 'carreras'],
        ['o-building-office', $summary['departments_affected'], 'departamento', 'departamentos'],
    ];
    $pending = ! $selected['legacy'] && ! $notices['sent'] && array_sum(array_column($notified, 1)) > 0;
@endphp
<div class="grid min-w-0 content-start gap-3.5 border-base-300 p-4 md:p-5 @5xl:border-e @max-5xl:border-b" data-testid="version-summary">
    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
        <h2 id="version-title" class="text-xl font-bold leading-tight">Versión {{ $number }}</h2>
        <x-core::status-badge :tone="$reason->tone()" :label="$reason->label()" :icon="$reason->icon()" data-testid="version-reason"
            :title="$selected['legacy'] && $number > 1 ? 'Deducido: se publicó antes de que Nexus guardara el motivo.' : null" />
        @if ($isCurrent)
            <x-core::status-badge tone="success-solid" label="Vigente" data-testid="version-current" />
        @endif
    </div>

    <p class="text-sm text-neutral">Publicada el {{ $day($selected['publishedAt']) }} · <span class="font-mono">{{ $clock($selected['publishedAt']) }}</span> por {{ $selected['publisher'] }}</p>

    @if ($selected['comment'] !== null)
        <p class="flex gap-2.5 rounded-box bg-base-200 px-3.5 py-2.5 text-sm" data-testid="version-comment">
            <x-icon name="o-chat-bubble-left-ellipsis" class="mt-0.5 h-4 w-4 shrink-0 text-neutral" aria-hidden="true" />
            <span class="min-w-0"><span class="sr-only">Comentario: </span>{{ $selected['comment'] }}</span>
        </p>
    @endif

    @if ($selected['reopened'] !== null)
        @php
            $reopened = $selected['reopened'];
        @endphp
        <p class="flex gap-2 text-[12.5px] text-neutral" data-testid="version-reopened">
            <x-icon :name="$reopened['to'] === \App\Modules\AcademicLoad\Enums\LoadStatus::Reanalysis ? 'o-arrow-path' : 'o-arrow-uturn-left'" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span class="min-w-0">{{ $reopened['to'] === \App\Modules\AcademicLoad\Enums\LoadStatus::Reanalysis ? 'Re-análisis iniciado' : 'Reabierta' }} por {{ $reopened['by'] }} el {{ $day($reopened['at']) }}{{ $reopened['comment'] !== null ? ': «'.$reopened['comment'].'»' : '.' }}</span>
        </p>
    @endif

    <dl class="grid grid-cols-3 rounded-box border border-base-300" data-testid="version-figures">
        @foreach ($figures as [$name, $value, $tone])
            <div class="min-w-0 border-base-300 px-3 py-2.5 not-first:border-s">
                <dt class="text-[12.5px] text-neutral">{{ $name }}</dt>
                <dd @class(['mt-0.5 font-mono text-[26px] leading-tight font-semibold tabular-nums', $tone])>{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($notified !== [])
        <div class="grid gap-1.5" data-testid="version-notified">
            <p class="{{ $label }}">{{ $selected['legacy'] ? 'Afectados' : ($pending ? 'Por avisar a' : 'Se avisó a') }}</p>
            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                @foreach ($notified as [$icon, $count, $one, $many])
                    <li @class(['inline-flex items-center gap-1.5', 'text-neutral' => $count === 0])>
                        <x-icon :name="$icon" class="h-4 w-4 shrink-0 text-neutral" aria-hidden="true" />
                        <span><span class="font-mono font-semibold">{{ $count }}</span> {{ $count === 1 ? $one : $many }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($selected['legacy'])
                <p class="text-[12.5px] text-neutral">Se publicó antes de que Nexus avisara las republicaciones: nadie recibió aviso.</p>
            @elseif ($pending)
                <p class="text-[12.5px] text-neutral" data-testid="version-notified-pending">No ha salido ningún aviso por la campana ni por correo.</p>
            @endif
        </div>
    @endif

    <div class="grid gap-1.5" data-testid="version-files">
        <p class="{{ $label }}">Archivos de esta versión</p>
        @foreach ($files as $file)
            @if ($isCurrent && $file['url'] !== null)
                <a href="{{ $file['url'] }}" class="flex min-h-11 items-center gap-3 rounded-field border border-base-300 px-3 py-2 hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-testid="file-{{ $file['kind'] }}">
                    <x-icon :name="$file['icon']" class="h-5 w-5 shrink-0 text-primary" aria-hidden="true" />
                    <span class="min-w-0">
                        @php($extension = pathinfo($file['filename'], PATHINFO_EXTENSION))
                        {{-- Se parte entre palabras, nunca a media extensión (ensayo 8.11, E-17: «…xls / x» a 375 px). --}}
                        <span class="block font-mono text-[13px] font-semibold break-words">{{ $extension === '' ? $file['filename'] : \Illuminate\Support\Str::beforeLast($file['filename'], '.'.$extension) }}@if ($extension !== '')<span class="whitespace-nowrap">.{{ $extension }}</span>@endif</span>
                        <span class="block text-[12.5px] text-neutral">{{ $file['label'] }} · se prepara al descargar</span>
                    </span>
                </a>
            @else
                <div class="flex min-h-11 items-center gap-3 rounded-field border border-dashed border-base-300 px-3 py-2 text-neutral" aria-disabled="true" data-testid="file-{{ $file['kind'] }}">
                    <x-icon :name="$file['icon']" class="h-5 w-5 shrink-0" aria-hidden="true" />
                    <span class="min-w-0">
                        <span class="block text-[13px] font-semibold">{{ $file['label'] }}</span>
                        <span class="block text-[12.5px]">{{ $isCurrent ? $file['reason'] : 'No disponible' }}</span>
                    </span>
                </div>
            @endif
        @endforeach
        @unless ($isCurrent)
            <p class="flex gap-2 text-[12.5px] text-neutral" data-testid="files-unavailable">
                <x-icon name="o-information-circle" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>Los archivos de las versiones anteriores no se ofrecen: solo se descargan los de la versión vigente (v{{ $current }}).</span>
            </p>
        @endunless
    </div>
</div>
