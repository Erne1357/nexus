{{--
    El drawer de un docente (staffing::pages.teacher-loads): sus cifras, sus grupos por campus y su semana de lunes a
    viernes de 7 a 21 h, con el campus por color. `close()` es del x-drawer.
--}}
@php
    $status = $row->status();
    $rowHeight = 22;
    $first = 7;
    $last = 21;
    $campusText = ['text-info-content', 'text-primary-content', 'text-neutral-content', 'text-warning-content'];
    $days = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie'];
    $byCampus = collect($row->groups)->groupBy('campus');
@endphp
<div class="flex min-h-dvh flex-col" data-testid="teacher-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <p class="font-mono text-[15px] font-semibold">{{ $row->rfc !== '' ? $row->rfc : 'Sin RFC' }} · {{ implode(' + ', $row->positionLabels) ?: 'Sin plaza' }}</p>
            <h2 id="teacher-drawer-title" class="mt-0.5 text-xl font-bold leading-tight">{{ $row->name }}</h2>
            <p class="mt-2 flex flex-wrap gap-1.5">
                @if ($status === 'over')
                    <x-core::status-badge tone="error" :label="'Excede +'.$row->excessHours().' h'" />
                @elseif ($status === 'none')
                    <x-core::status-badge tone="warning" label="Sin carga" icon="o-clock" />
                @elseif ($status === 'full')
                    <x-core::status-badge tone="success" label="Carga completa" />
                @elseif ($status === 'hon')
                    <x-core::status-badge tone="draft" label="Honorarios" icon="o-pencil-square" />
                @else
                    <x-core::status-badge tone="info" :label="$row->remainingHours.' h libres'" icon="o-arrow-trending-down" />
                @endif
            </p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-6 px-5 py-4">
        <dl class="grid grid-cols-4 divide-x divide-base-300 overflow-hidden rounded-box border border-base-300">
            @foreach ([['Base', $row->baseHours, ''], ['Externas', $row->externalHours, ''], ['Asignadas', $row->assignedHours, ''], ['Restan', $row->remainingHours, $row->remainingHours < 0 ? 'text-error' : '']] as [$label, $value, $tone])
                <div class="min-w-0 p-3">
                    <dt class="truncate text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</dt>
                    <dd class="mt-1 font-mono text-2xl font-semibold leading-none tabular-nums {{ $tone }}" data-testid="drawer-{{ Str::slug($label) }}">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @forelse ($byCampus as $code => $groups)
            <section aria-labelledby="campus-{{ $code }}" data-testid="drawer-campus-{{ $code }}">
                <h3 id="campus-{{ $code }}" class="mb-2 flex items-center gap-2 font-semibold">
                    <i class="inline-block h-2.5 w-2.5 rounded-sm {{ $campusBar[$campusTones[$code] % count($campusBar)] }}"></i>{{ $code }}
                    <span class="badge badge-sm badge-neutral badge-soft font-mono">{{ $groups->sum('hours') }} h</span>
                    <span class="badge badge-sm badge-neutral badge-soft font-mono">{{ $groups->count() }} {{ $groups->count() === 1 ? 'grupo' : 'grupos' }}</span>
                </h3>
                <ul class="divide-y divide-base-300 rounded-box border border-base-300">
                    @foreach ($groups as $group)
                        <li class="flex items-center gap-3 px-3 py-2">
                            <span class="w-14 shrink-0 font-mono text-[15px] font-semibold">{{ $group['code'] }}</span>
                            <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ $group['subject'] }}</span><span class="font-mono text-xs text-neutral">{{ $group['subject_code'] }}</span></span>
                            <span class="font-mono text-sm tabular-nums">{{ $group['hours'] }} h</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="text-neutral" data-testid="drawer-no-groups">Sin grupos en este periodo.</p>
        @endforelse

        <section aria-labelledby="week-title">
            <h3 id="week-title" class="mb-2 font-semibold">Horario semanal</h3>
            <div class="grid grid-cols-[2rem_repeat(5,minmax(0,1fr))] gap-x-px text-xs" role="img" aria-label="Horario semanal de {{ $row->name }}" data-testid="drawer-week">
                <div></div>
                @foreach ($days as $label)
                    <div class="pb-1 text-center font-medium text-neutral">{{ $label }}</div>
                @endforeach
                <div class="font-mono text-neutral">
                    @for ($hour = $first; $hour < $last; $hour++)
                        <div class="pr-1 text-right" style="height: {{ $rowHeight }}px">{{ sprintf('%02d', $hour) }}</div>
                    @endfor
                </div>
                @foreach ($days as $day => $label)
                    <div class="relative rounded-sm border border-base-300 bg-base-100" style="height: {{ ($last - $first) * $rowHeight }}px; background-image: repeating-linear-gradient(to bottom, transparent 0, transparent {{ $rowHeight - 1 }}px, var(--color-base-300) {{ $rowHeight - 1 }}px, var(--color-base-300) {{ $rowHeight }}px)">
                        @foreach (array_filter($row->sessions, fn ($session) => $session['day'] === $day) as $session)
                            @php
                                $top = max(0, ($session['from'] / 60 - $first) * $rowHeight);
                                $height = max($rowHeight / 2, (min($session['to'] / 60, $last) - max($session['from'] / 60, $first)) * $rowHeight);
                            @endphp
                            <div class="absolute inset-x-0.5 overflow-hidden rounded-sm px-1 font-mono font-semibold leading-tight {{ $campusBar[$campusTones[$session['campus']] % count($campusBar)] }} {{ $campusText[$campusTones[$session['campus']] % count($campusText)] }}"
                                style="top: {{ $top }}px; height: {{ $height }}px" title="{{ $session['code'] }} · {{ $session['subject'] }} · {{ $session['campus'] }}">{{ $session['code'] }}</div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</div>
