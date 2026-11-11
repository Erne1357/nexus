{{--
    El detalle del acuerdo elegido (scheduling::pages.agreements): encabezado con el título, el tipo y el estado; una
    franja con el estado o lo que pasa al decidir, dicho una sola vez; los datos; la semana (la del salón en un préstamo,
    la del grupo en un grupo compartido) y quién lo pidió; al pie, fijas, las acciones. Lo que el actor no puede hacer
    queda deshabilitado con su motivo escrito en la franja (`aria-describedby`), no solo en un tooltip.

    Al rechazar, el motivo va primero, arriba de todo, y recibe el foco; Esc vuelve.
--}}
@php
    $statuses = \App\Modules\AcademicLoad\Enums\AgreementStatus::class;
    $isLoan = $detail['kind'] === \App\Modules\AcademicLoad\Enums\AgreementKind::RoomLoan;
    $proposed = $detail['status'] === $statuses::Proposed;
    [$badgeTone, $badgeLabel, $badgeIcon] = $proposed
        ? ($canDecide ? ['warning', 'Por decidir', 'o-clock'] : ['info', 'Enviado', 'o-paper-airplane'])
        : [$detail['status']->tone(), $detail['status']->label(), $detail['status']->icon()];
    $group = $detail['group']['label'] ?? 'el grupo';
    $seats = $detail['seats'].($detail['seats'] === 1 ? ' lugar' : ' lugares');
    $mine = $canCancel && ! $canDecide;
    $reason = $proposed && ! $canDecide
        ? ($mine ? "Lo decide el jefe de {$detail['to']['name']}; todavía no responde." : "Solo ves este acuerdo: lo decide el jefe de {$detail['to']['name']}.")
        : $acceptBlock;
    $date = fn ($moment): string => $moment->isoFormat('D MMM YYYY').' · '.$moment->format('H:i');
    $reasoning = $proposed && $rejecting && $canDecide;
    $label = 'block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral';
    $placement = $detail['placement'] ?? null;
@endphp
<div class="flex min-h-0 flex-1 flex-col" data-testid="agreement-detail" x-data="{ text: '' }" x-effect="if (! $wire.rejecting) { text = '' }">
    <div class="grid gap-2 border-b border-base-300 px-6 pt-[18px] pb-3.5 max-lg:px-4">
        <button type="button" wire:click="back" class="btn btn-ghost btn-sm -ms-2 w-max lg:hidden max-md:h-11 max-md:min-h-11" data-testid="agreement-back"><x-icon name="o-chevron-left" class="h-4 w-4" />Volver</button>
        <h2 class="scroll-mt-24 text-xl font-bold leading-tight focus:outline-none" tabindex="-1" data-testid="agreement-title">
            @if ($isLoan)
                Préstamo de <span class="font-mono">{{ $detail['room'] }}</span>
            @else
                Cupo de <span class="font-mono">{{ $detail['career']['code'] }}</span> en <span class="font-mono">{{ $group }}</span>
            @endif
        </h2>
        <div class="flex flex-wrap items-center gap-2.5">
            <span class="text-neutral">{{ $detail['kind']->label() }}</span>
            <x-core::status-badge :tone="$badgeTone" :label="$badgeLabel" :icon="$badgeIcon" data-testid="agreement-status" />
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-auto max-lg:overflow-visible" data-testid="agreement-body">
        @if ($reasoning)
            <section class="grid scroll-mt-24 gap-2 border-b border-base-300 px-6 py-4 max-lg:px-4" data-reason data-testid="agreement-reject-reason">
                <label for="agreement-comment" class="{{ $label }}">Motivo del rechazo (obligatorio)</label>
                <textarea id="agreement-comment" wire:model="comment" x-model="text" maxlength="{{ $maxComment }}" rows="3" aria-describedby="agreement-consequence @error('comment') agreement-comment-error @enderror"
                    x-init="$nextTick(() => { $el.focus({ preventScroll: true }); $el.closest('[data-reason]').scrollIntoView({ block: 'nearest' }) })" x-on:keydown.escape.prevent="$wire.cancelReject()"
                    class="textarea textarea-bordered min-h-20 w-full" data-testid="agreement-comment"></textarea>
                @error('comment')
                    <p id="agreement-comment-error" class="flex items-center gap-1.5 text-sm text-error" role="alert" data-testid="agreement-comment-error"><x-icon name="o-exclamation-circle" class="h-4 w-4" />{{ $message }}</p>
                @enderror
                <p id="agreement-consequence" class="flex items-start gap-2 text-sm">
                    <x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-warning" aria-hidden="true" />
                    <span><b class="font-medium">Al rechazar:</b>
                        @if ($isLoan)
                            la sesión de {{ $group }} del {{ mb_strtolower($detail['day_name']) }} de {{ $detail['hours'] }} se queda sin salón ({{ $detail['room'] }}) y su coordinación tendrá que elegir otro.
                        @else
                            el cupo de {{ $detail['career']['code'] }} ({{ $seats }}) no se abre en {{ $group }}.
                        @endif
                    </span>
                </p>
            </section>
        @else
            @php
                [$noteTone, $noteIconName, $noteIconColor] = match (true) {
                    $detail['status'] === $statuses::Accepted => ['bg-success/10', 'o-check-circle', 'text-success'],
                    $detail['status'] === $statuses::Rejected => ['bg-error/10', 'o-x-circle', 'text-error'],
                    $detail['status'] === $statuses::Cancelled => ['bg-base-200', 'o-minus-circle', 'text-neutral'],
                    $canDecide && $acceptBlock !== null => ['bg-error/10', 'o-x-circle', 'text-error'],
                    $canDecide => ['bg-info/10', 'o-information-circle', 'text-info'],
                    default => ['bg-base-200', 'o-clock', 'text-neutral'],
                };
            @endphp
            <section @class(['flex items-start gap-2.5 border-b border-base-300 px-6 py-3 text-sm max-lg:px-4', $noteTone]) @if ($proposed) id="agreement-reason" @endif
                data-testid="{{ $canDecide && $acceptBlock !== null && $proposed ? 'agreement-block' : 'agreement-note' }}">
                <x-icon :name="$noteIconName" @class(['mt-0.5 h-[18px] w-[18px] shrink-0', $noteIconColor]) aria-hidden="true" />
                <div class="min-w-0">
                    @if ($detail['status'] === $statuses::Accepted)
                        <b class="font-medium">Aceptado por {{ $detail['decided_by'] }}</b> · <span class="font-mono">{{ $date($detail['decided_at']) }}</span>
                    @elseif ($detail['status'] === $statuses::Rejected)
                        <b class="font-medium">Rechazado por {{ $detail['decided_by'] }}</b> · <span class="font-mono">{{ $date($detail['decided_at']) }}</span>
                        <br>Motivo: {{ $detail['comment'] }}
                        @if ($isLoan && $detail['room_cleared'])<br>La sesión de {{ $group }} se quedó sin salón.@endif
                    @elseif ($detail['status'] === $statuses::Cancelled)
                        <b class="font-medium">Cancelado por {{ $detail['cancelled_by'] }}</b> · <span class="font-mono">{{ $date($detail['cancelled_at']) }}</span>
                        @if ($isLoan && $detail['room_cleared'])<br>La sesión de {{ $group }} se quedó sin salón.@endif
                    @elseif ($canDecide && $acceptBlock !== null)
                        <b class="font-medium">No se puede aceptar.</b> {{ $acceptBlock }}
                    @elseif ($canDecide)
                        @if ($isLoan)
                            Si aceptas, {{ $detail['room'] }} queda reservado para {{ $group }}. Si rechazas, la sesión se queda sin salón.
                        @else
                            Si aceptas, se abre el cupo de {{ $detail['career']['code'] }} en {{ $group }} con {{ $seats }}.
                        @endif
                    @else
                        @if ($mine)<b class="font-medium">Enviado.</b>@endif {{ $reason }}
                    @endif
                </div>
            </section>
        @endif

        <div class="grid content-start gap-x-8 gap-y-[18px] px-6 py-[18px] max-lg:px-4 min-[1880px]:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] min-[1880px]:grid-rows-[auto_1fr]">
            <dl @class(['grid grid-cols-2 gap-x-4 gap-y-3.5 min-[1880px]:col-start-1 min-[1880px]:row-start-1 min-[1880px]:grid-cols-2!', 'md:grid-cols-4' => $isLoan, 'md:grid-cols-3' => ! $isLoan]) data-testid="agreement-facts">
                @if ($isLoan)
                    <div><dt class="{{ $label }}">Salón</dt><dd class="font-mono font-medium">{{ $detail['room'] }}</dd></div>
                    <div><dt class="{{ $label }}">Grupo</dt><dd class="font-mono font-medium">{{ $group }}</dd></div>
                    <div><dt class="{{ $label }}">Día</dt><dd class="font-medium">{{ $detail['day_name'] }}</dd></div>
                    <div><dt class="{{ $label }}">Horas</dt><dd class="font-mono font-medium">{{ $detail['hours'] }}</dd></div>
                @else
                    <div class="max-md:col-span-2"><dt class="{{ $label }}">Materia</dt><dd class="font-medium">{{ $detail['group']['subject_name'] ?? '—' }}</dd></div>
                    <div><dt class="{{ $label }}">Grupo</dt><dd class="font-mono font-medium">{{ $group }}</dd>
                        @if ($placement !== null)<dd class="text-sm text-neutral">Semestre {{ $placement['semester'] }} de {{ $placement['owner'] }} · <span class="whitespace-nowrap font-mono">{{ $placement['plan'] }}</span></dd>@endif
                    </div>
                    <div><dt class="{{ $label }}">Lugares</dt><dd class="font-mono font-medium">{{ $detail['seats'] }}</dd><dd class="text-sm text-neutral">para <span class="font-mono">{{ $detail['career']['code'] }}</span></dd></div>
                    <div class="max-md:col-span-2"><dt class="{{ $label }}">Dueño del grupo</dt><dd class="font-medium">{{ $detail['from']['name'] }}</dd></div>
                    <div><dt class="{{ $label }}">En {{ $detail['career']['code'] }}</dt>
                        @forelse ($placement['career'] ?? [] as $place)
                            <dd class="font-medium">Semestre {{ $place['semester'] }} <span class="whitespace-nowrap font-mono text-sm font-normal text-neutral">{{ $place['plan'] }}</span></dd>
                        @empty
                            <dd class="text-neutral">No está en sus planes</dd>
                        @endforelse
                    </div>
                @endif
            </dl>

            <div class="min-w-0 max-w-3xl min-[1880px]:col-start-2 min-[1880px]:row-span-2 min-[1880px]:row-start-1">
                @if ($isLoan)
                    <h3 class="mb-2 text-sm font-bold">Semana de <span class="font-mono">{{ $detail['room'] }}</span> · {{ $detail['to']['name'] }}</h3>
                    @include('scheduling::agreements.week')
                @elseif (($detail['schedule']['sessions'] ?? []) === [])
                    <h3 class="mb-1 text-sm font-bold">Horario de <span class="font-mono">{{ $group }}</span></h3>
                    <p class="text-neutral" data-testid="group-week-empty">El grupo todavía no tiene sesiones.</p>
                @else
                    <h3 class="mb-2 text-sm font-bold">Horario de <span class="font-mono">{{ $group }}</span></h3>
                    @include('scheduling::agreements.week')
                @endif
            </div>

            <dl class="grid grid-cols-2 content-start gap-x-4 gap-y-3.5 self-start border-t border-base-300 pt-[18px] min-[1880px]:col-start-1 min-[1880px]:row-start-2">
                <div><dt class="{{ $label }}">De</dt><dd><span class="font-medium">{{ $detail['from']['name'] }}</span> <span class="font-mono text-sm text-neutral">{{ $detail['from']['code'] }}</span></dd></div>
                <div><dt class="{{ $label }}">Para</dt><dd><span class="font-medium">{{ $detail['to']['name'] }}</span> <span class="font-mono text-sm text-neutral">{{ $detail['to']['code'] }}</span></dd></div>
                <div><dt class="{{ $label }}">Lo pidió</dt><dd class="font-medium">{{ $detail['requested_by'] ?? '—' }}</dd></div>
                <div><dt class="{{ $label }}">Fecha</dt><dd class="font-mono font-medium">{{ $date($detail['requested_at']) }}</dd></div>
            </dl>
        </div>
    </div>

    @if ($proposed)
        <div class="flex flex-wrap justify-end gap-2.5 border-t border-base-300 bg-base-100 px-6 py-3 max-lg:sticky max-lg:bottom-0 max-lg:z-10 max-lg:px-4 max-md:[&>*]:flex-1" data-testid="agreement-actions">
            @if ($rejecting && $canDecide)
                <x-button label="Volver" class="btn-outline max-md:h-11 max-md:min-h-11" wire:click="cancelReject" data-testid="agreement-reject-back" />
                <x-button label="Confirmar rechazo" class="btn-error max-md:h-11 max-md:min-h-11" wire:click="reject('{{ $detail['id'] }}')" spinner="reject" x-bind:disabled="! text.trim()" data-testid="agreement-reject-confirm" />
            @else
                @if ($canCancel)
                    <x-button label="Cancelar solicitud" icon="o-x-mark" class="btn-ghost mr-auto max-md:h-11 max-md:min-h-11 max-md:mr-0" wire:click="cancel('{{ $detail['id'] }}')" x-confirm="Ya no habrá nada que decidir." data-confirm-title="¿Cancelar la solicitud?" data-confirm-button="Cancelar solicitud" data-confirm-tone="error" spinner="cancel" data-testid="agreement-cancel" />
                @endif
                <x-button label="Rechazar…" class="btn-outline btn-error max-md:h-11 max-md:min-h-11" wire:click="confirmReject('{{ $detail['id'] }}')" :disabled="! $canDecide"
                    :aria-describedby="$canDecide ? null : 'agreement-reason'" data-testid="agreement-reject" />
                <x-button label="Aceptar" icon="o-check" class="btn-primary max-md:h-11 max-md:min-h-11" wire:click="accept('{{ $detail['id'] }}')" spinner="accept" :disabled="! $canDecide || $acceptBlock !== null"
                    :aria-describedby="$canDecide && $acceptBlock === null ? null : 'agreement-reason'" data-testid="agreement-accept" />
            @endif
        </div>
    @endif
</div>
