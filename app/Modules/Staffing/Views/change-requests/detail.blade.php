{{--
    El detalle de la solicitud elegida (staffing::pages.change-requests, maqueta A): el grupo, el estado y, en un renglón,
    el docente, quién la pidió (si fue la coordinación), la materia, la carrera dueña y el campus de hoy de su grupo (M8);
    la foto de las sesiones
    al pedirla contra lo que se propone; el motivo; el historial (pidió, aprobó o rechazó con su comentario, aplicó) y,
    en un pie pegado abajo del panel, lo que le toca a quien lo ve: decidir (el jefe), aplicar (el coordinador si es de
    horario, el jefe si es de salón) o quién sigue.
--}}
@use('App\Modules\Staffing\Enums\ChangeRequestKind')
@use('App\Modules\Staffing\Enums\ChangeRequestStatus')
@php
    $tz = config('app.timezone');
    $group = $request->group;
    $career = $group->ownerCareer;
    $isSchedule = $request->kind === ChangeRequestKind::Schedule;
    $snapshot = \App\Modules\Staffing\Support\SessionLabels::snapshot($request->current ?? []);
    $byCoordination = $request->teacher_id === null;
    $when = fn ($date) => $date?->timezone($tz)->translatedFormat('j M Y, H:i');
@endphp
<div class="@container flex min-h-full flex-col" data-testid="request-detail-body">
    <header class="space-y-3 border-b border-base-300 px-5 py-4">
        <button type="button" wire:click="back" class="btn btn-ghost btn-sm -ms-2 lg:hidden max-md:h-11 max-md:min-h-11" data-testid="request-back"><x-icon name="o-chevron-left" class="h-4 w-4" aria-hidden="true" />Solicitudes</button>
        <h2 class="text-xl font-bold leading-tight" tabindex="-1" data-testid="request-detail-title">
            {{ $group->subject->name }} · <span class="font-mono">{{ $group->code }}</span>
        </h2>
        <div class="flex flex-wrap items-center gap-2">
            <x-core::status-badge :tone="$request->status->tone()" :label="$request->status->label()" :icon="$request->status->icon()" data-testid="request-status" />
            <span class="badge badge-neutral badge-soft h-6.5 gap-1.5 font-medium">
                <x-icon :name="$isSchedule ? 'o-calendar-days' : 'o-map-pin'" class="h-3.5 w-3.5" aria-hidden="true" />{{ $request->kind->label() }}
            </span>
            <span class="text-sm text-neutral">Pedida el <span class="tabular-nums">{{ $when($request->created_at) }}</span></span>
        </div>
        <dl class="flex flex-wrap gap-x-5 gap-y-1 text-sm" data-testid="request-facts">
            <div class="flex gap-1.5"><dt class="text-neutral">Docente</dt><dd class="font-semibold">{{ $request->teacher?->name ?? 'Sin docente' }}</dd></div>
            @if ($byCoordination)
                <div class="flex gap-1.5"><dt class="text-neutral">La pidió</dt><dd>{{ $request->requestedBy?->name }} <span class="text-neutral">· coordinación</span></dd></div>
            @endif
            <div class="flex gap-1.5"><dt class="text-neutral">Materia</dt><dd class="font-mono">{{ $group->subject->code }}</dd></div>
            <div class="flex min-w-0 gap-1.5"><dt class="shrink-0 text-neutral">Carrera dueña</dt><dd class="min-w-0"><span class="font-mono font-semibold">{{ $career?->code }}</span> {{ $career?->name }}</dd></div>
            @if ($group->campus !== null)
                <div class="flex items-center gap-1.5"><dt class="text-neutral">Campus</dt><dd class="inline-flex items-center gap-1.5">@if ($multiCampus)<x-core::campus-tag :campus="$group->campus" decorative />@endif{{ $group->campus->name }}</dd></div>
            @endif
        </dl>
    </header>

    <div class="max-w-[60rem] flex-1 space-y-6 px-5 py-5">
        <section aria-labelledby="request-change-title">
            <h3 id="request-change-title" class="mb-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $isSchedule ? 'Cambio de horario' : 'Cambio de salón' }}</h3>
            <div class="grid items-stretch gap-2 @[34rem]:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                <div class="rounded-field border border-base-300 px-3.5 py-3">
                    <span class="text-xs font-medium text-neutral">Al pedirla</span>
                    @forelse ($snapshot as $line)
                        <p class="mt-0.5 text-sm">{{ $line['days'] }} <span class="font-mono">{{ $line['time'] }}</span>@if ($line['room']) · <span class="font-mono font-semibold">{{ $line['room'] }}</span>@elseif (! $isSchedule) · <span class="text-neutral">sin salón</span>@endif</p>
                    @empty
                        <p class="mt-0.5 text-sm text-neutral">Sin sesiones</p>
                    @endforelse
                </div>
                <span class="flex items-center justify-center text-neutral" aria-hidden="true">
                    <x-icon name="o-arrow-right" class="h-5 w-5 @max-[34rem]:rotate-90" />
                </span>
                @if ($approvedLines !== [])
                    <div class="rounded-field border border-success/40 bg-success/7 px-3.5 py-3" data-testid="request-approved-box">
                        <span class="text-xs font-medium text-neutral">{{ $request->status === ChangeRequestStatus::Applied ? 'Aplicada' : 'Aprobada' }} · alternativa {{ $alternatives['chosen']['letter'] }}</span>
                        @foreach ($approvedLines as $line)
                            <p class="mt-0.5 font-mono text-sm [word-spacing:-0.25em]">@if ($line['label'])<span class="font-semibold">{{ $line['label'] }}</span> · @endif{{ $line['line'] }}</p>
                        @endforeach
                    </div>
                @else
                <div class="rounded-field border border-info/40 bg-info/10 px-3.5 py-3">
                    <span class="text-xs font-medium text-neutral">Propuesta</span>
                    <p class="mt-0.5 break-words font-semibold">{{ $request->proposed['description'] ?? '' }}</p>
                    @foreach (\App\Modules\Staffing\Support\SessionLabels::snapshot($request->proposed['sessions'] ?? []) as $line)
                        <p class="mt-0.5 text-sm">{{ $line['days'] }} <span class="font-mono">{{ $line['time'] }}</span></p>
                    @endforeach
                    @if (isset($request->proposed['room']))
                        <p class="mt-0.5 text-sm">Salón <span class="font-mono font-semibold">{{ $request->proposed['room'] }}</span></p>
                    @endif
                </div>
                @endif
            </div>
        </section>

        @if ($request->status === ChangeRequestStatus::Pending && $alternatives !== null)
            @include('staffing::change-requests.alternatives', ['alternatives' => $alternatives])
        @elseif ($alternatives !== null && $alternatives['chosen'] !== null && $alternatives['selected'] !== null)
            <section aria-labelledby="request-chosen-title" data-testid="request-chosen" class="min-w-0">
                <h3 id="request-chosen-title" class="mb-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $request->status === ChangeRequestStatus::Applied ? 'Lo aplicado' : 'Lo aprobado' }} · alternativa {{ $alternatives['chosen']['letter'] }}</h3>
                @if ($staleAlternatives && $request->status === ChangeRequestStatus::Approved)
                    <div class="mb-3 flex items-start gap-3 rounded-field border border-warning/45 bg-warning/10 px-3.5 py-3 text-sm" role="alert" data-testid="alternatives-stale">
                        <x-icon name="o-exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-warning" aria-hidden="true" />
                        <div class="min-w-0">
                            <p class="font-semibold text-warning">Esto cambió desde que se aprobó la alternativa.</p>
                            <p>El horario de esos grupos o lo que los rodea ya no es el mismo: no se puede escribir tal cual. Acomódala a mano en el editor de horarios y luego márcala como aplicada.</p>
                        </div>
                    </div>
                @endif
                @include('staffing::change-requests.alternative-panel', ['option' => $alternatives['selected'], 'stale' => $staleAlternatives && $request->status === ChangeRequestStatus::Approved, 'tabbed' => false])
            </section>
        @endif

        <section aria-labelledby="request-reason-title">
            <h3 id="request-reason-title" class="mb-1.5 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Motivo</h3>
            <p class="max-w-[65ch] break-words">{{ $request->reason }}</p>
        </section>

        <section aria-labelledby="request-history-title">
            <h3 id="request-history-title" class="mb-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Historial</h3>
            <ol class="space-y-3 border-s-2 border-base-300 ps-4" data-testid="request-history">
                <li class="relative">
                    <span aria-hidden="true" class="absolute -start-[1.4rem] top-1.5 h-2.5 w-2.5 rounded-full bg-primary"></span>
                    <p class="text-sm"><span class="font-semibold">Pidió</span> {{ $request->requestedBy?->name }}{{ $byCoordination ? ' (coordinación)' : '' }}</p>
                    <p class="text-xs text-neutral tabular-nums">{{ $when($request->created_at) }}</p>
                </li>
                @if ($request->decided_at !== null)
                    <li class="relative">
                        <span aria-hidden="true" @class(['absolute -start-[1.4rem] top-1.5 h-2.5 w-2.5 rounded-full', 'bg-error' => $request->status === ChangeRequestStatus::Rejected, 'bg-primary' => $request->status !== ChangeRequestStatus::Rejected])></span>
                        <p class="text-sm"><span class="font-semibold">{{ $request->status === ChangeRequestStatus::Rejected ? 'Rechazó' : 'Aprobó' }}</span> {{ $request->decidedBy?->name }}@if ($alternatives !== null && $alternatives['chosen'] !== null) · con la alternativa {{ $alternatives['chosen']['letter'] }} del motor (puntaje {{ $alternatives['chosen']['score'] }})@endif</p>
                        <p class="text-xs text-neutral tabular-nums">{{ $when($request->decided_at) }}</p>
                        @if ($request->decision_comment)
                            <blockquote class="mt-1.5 max-w-[65ch] break-words rounded-field bg-base-200 px-3 py-2 text-sm" data-testid="request-comment">{{ $request->decision_comment }}</blockquote>
                        @endif
                    </li>
                @endif
                @if ($request->applied_at !== null)
                    <li class="relative">
                        <span aria-hidden="true" class="absolute -start-[1.4rem] top-1.5 h-2.5 w-2.5 rounded-full bg-success"></span>
                        <p class="text-sm"><span class="font-semibold">Aplicó</span> {{ $request->appliedBy?->name }}</p>
                        <p class="text-xs text-neutral tabular-nums">{{ $when($request->applied_at) }}</p>
                    </li>
                @endif
                @if ($request->status->isOpen())
                    <li class="relative text-sm text-neutral">
                        <span aria-hidden="true" class="absolute -start-[1.4rem] top-1.5 h-2.5 w-2.5 rounded-full border border-base-content/30 bg-base-100"></span>
                        {{ $request->status === ChangeRequestStatus::Pending ? 'Falta la decisión del jefe del departamento.' : ($isSchedule ? 'Falta aplicarla en el horario.' : 'Falta asignar el salón.') }}
                    </li>
                @endif
            </ol>
        </section>
    </div>

    @if ($request->status === ChangeRequestStatus::Pending)
        @php
            $option = $alternatives['selected'] ?? null;
            $withAlternative = $option !== null && $option['letter'] !== null;
            $hasAlternatives = ($alternatives['items'] ?? 0) > 0;
            $staleChoice = $withAlternative && $staleAlternatives;
            $blockedClashes = $alternatives['pedidoHard'] ?? array_sum(array_map(fn ($rule) => count($rule['messages'] ?? []), $request->impact['hard'] ?? []));
            $softCount = count($request->impact['soft'] ?? []);
            $plural = fn (int $n) => $n === 1 ? 'choque duro' : 'choques duros';
        @endphp
        <footer class="sticky bottom-0 z-10 space-y-3 border-t border-base-300 bg-base-100 px-5 py-4 max-md:px-4" data-testid="request-decision">
            @if ($canDecide)
                @if (! $withAlternative && $blockedClashes === 0 && $softCount > 0 && $alternatives !== null && $alternatives['assessable'])
                    <p class="text-[12.5px] text-neutral">Lo pedido deja {{ $softCount }} {{ $softCount === 1 ? 'aviso' : 'avisos' }}; se puede aprobar así.</p>
                @endif
                <x-textarea label="Comentario" wire:model="comment" rows="2" maxlength="{{ $maxText }}" placeholder="Opcional al aprobar" data-testid="decision-comment" />
                @error('status')
                    <p class="text-sm text-error">{{ $message }}</p>
                @enderror
                @error('alternative')
                    @unless ($staleChoice)
                        <p class="text-sm text-error">{{ $message }}</p>
                    @endunless
                @enderror
                @if ($withAlternative && $staleChoice)
                    <p class="text-sm text-error" id="approve-why" data-testid="approve-stale">Las alternativas son de antes de un cambio en el horario: busca otra vez.</p>
                @elseif (! $withAlternative && $blockedClashes > 0)
                    <p class="text-sm text-error" id="approve-why" data-testid="approve-blocked">{{ $hasAlternatives
                        ? "No se puede aprobar lo pedido: deja {$blockedClashes} {$plural($blockedClashes)}. Elige una alternativa o recházala."
                        : "No se puede aprobar: el cambio deja {$blockedClashes} {$plural($blockedClashes)}. Recházala o busca alternativas." }}</p>
                @endif
                <div class="flex flex-wrap justify-end gap-2">
                    <x-button label="Rechazar" icon="o-x-circle" :class="'btn-outline btn-error max-md:h-11 max-md:min-h-11 '.($withAlternative ? 'max-md:w-full' : 'max-md:flex-1')" wire:click="reject" spinner="reject" data-testid="reject-request" />
                    @if ($withAlternative)
                        <x-button :label="'Aprobar con la alternativa '.$option['letter']" icon="o-check" class="btn-primary max-md:h-11 max-md:min-h-11 max-md:w-full" wire:click="chooseAlternative('{{ $option['key'] }}')" spinner="chooseAlternative" :disabled="$staleChoice" aria-describedby="{{ $staleChoice ? 'approve-why' : '' }}" data-testid="approve-alternative" />
                    @else
                        <x-button :label="$hasAlternatives ? 'Aprobar lo pedido' : 'Aprobar'" icon="o-check" class="btn-primary max-md:h-11 max-md:min-h-11 max-md:flex-1" wire:click="approve" spinner="approve" :disabled="$blockedClashes > 0" aria-describedby="{{ $blockedClashes > 0 ? 'approve-why' : '' }}" data-testid="approve-request" />
                    @endif
                </div>
            @else
                <p class="flex items-center gap-2 text-sm text-neutral"><x-icon name="o-information-circle" class="h-4 w-4 shrink-0" aria-hidden="true" />La revisa el jefe del departamento.</p>
            @endif
        </footer>
    @elseif ($request->status === ChangeRequestStatus::Approved)
        @php
            $chosenLetter = $alternatives['chosen']['letter'] ?? null;
            $chosenGroups = $chosenLetter === null ? 0 : count($alternatives['chosen']['option']['groups']);
        @endphp
        <footer class="sticky bottom-0 z-10 space-y-3 border-t border-base-300 bg-base-100 px-5 py-4 max-md:px-4" data-testid="request-application">
            @if ($canApply && $chosenLetter !== null)
                <p class="text-sm">«Aplicar la alternativa» escribe de una vez el horario de {{ $chosenGroups === 1 ? 'su grupo' : "los {$chosenGroups} grupos de su cadena" }} y la deja aplicada. Si prefieres acomodarla a mano, ábrela en el editor con sus sesiones por colocar y luego márcala como aplicada.</p>
                @error('status')
                    <p class="text-sm text-error">{{ $message }}</p>
                @enderror
                @error('alternative')
                    @unless ($staleAlternatives)
                        <p class="text-sm text-error">{{ $message }}</p>
                    @endunless
                @enderror
                @if ($staleAlternatives)
                    <p class="text-sm text-error" id="apply-why" data-testid="apply-stale">La alternativa ya no se puede escribir tal cual: acomódala en el editor.</p>
                @endif
                <div class="flex flex-wrap justify-end gap-2">
                    @if ($editorUrl !== null)
                        <a href="{{ $editorUrl }}" wire:navigate class="btn btn-outline max-md:h-11 max-md:min-h-11 max-md:flex-1" data-testid="open-editor"><x-icon name="o-arrow-top-right-on-square" class="h-4 w-4" aria-hidden="true" />Abrir en el editor</a>
                    @endif
                    <x-button label="Marcar como aplicada" icon="o-check-badge" class="btn-ghost max-md:h-11 max-md:min-h-11 max-md:flex-1" wire:click="markApplied" spinner="markApplied" data-testid="apply-request" />
                    <x-button :label="'Aplicar la alternativa '.$chosenLetter" icon="o-bolt" class="btn-primary max-md:h-11 max-md:min-h-11 max-md:w-full" wire:click="applyAlternative" spinner="applyAlternative" :disabled="$staleAlternatives" aria-describedby="{{ $staleAlternatives ? 'apply-why' : '' }}" data-testid="apply-alternative" />
                </div>
            @elseif ($canApply)
                <p class="text-sm">{{ $isSchedule ? 'Acomoda el cambio en el editor de horarios y luego márcala como aplicada.' : 'Asigna el salón y luego márcala como aplicada.' }}</p>
                @error('status')
                    <p class="text-sm text-error">{{ $message }}</p>
                @enderror
                <div class="flex flex-wrap justify-end gap-2">
                    @if ($editorUrl !== null)
                        <a href="{{ $editorUrl }}" wire:navigate class="btn btn-outline max-md:h-11 max-md:min-h-11 max-md:flex-1" data-testid="open-editor"><x-icon name="o-arrow-top-right-on-square" class="h-4 w-4" aria-hidden="true" />Abrir en el editor</a>
                    @endif
                    <x-button label="Marcar como aplicada" icon="o-check-badge" class="btn-primary max-md:h-11 max-md:min-h-11 max-md:flex-1" wire:click="markApplied" spinner="markApplied" data-testid="apply-request" />
                </div>
            @else
                <p class="flex items-start gap-2 text-sm text-neutral"><x-icon name="o-information-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{{ match (true) {
                        $chosenLetter !== null && $isSchedule => "La aplica la coordinación de {$career?->code}: escribe la alternativa {$chosenLetter} de una vez o la acomoda en el editor de horarios.",
                        $chosenLetter !== null => "La aplica el jefe del departamento: escribe la alternativa {$chosenLetter} de una vez o la acomoda en el editor.",
                        $isSchedule => "La aplica la coordinación de {$career?->code} en el editor de horarios.",
                        default => 'La aplica el jefe del departamento al asignar salones.',
                    } }}</span>
                </p>
            @endif
        </footer>
    @endif
</div>
