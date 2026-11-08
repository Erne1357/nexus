{{--
    El detalle del acuerdo elegido (scheduling::pages.agreements): encabezado con el título, el tipo y el estado;
    el cuerpo con sus datos y, en un préstamo, la semana del salón; y al pie, fijas, las acciones. Lo que el actor no
    puede hacer queda deshabilitado con su motivo escrito en el cuerpo (`aria-describedby`), no solo en un tooltip.
--}}
@php
    $isLoan = $detail['room'] !== null;
    $proposed = $detail['status'] === \App\Modules\AcademicLoad\Enums\AgreementStatus::Proposed;
    $answerable = $proposed && $canDecide;
    [$badgeTone, $badgeLabel, $badgeIcon] = $proposed
        ? ($canDecide ? ['warning', 'Por decidir', 'o-clock'] : ['info', 'Enviado', 'o-paper-airplane'])
        : [$detail['status']->tone(), $detail['status']->label(), $detail['status']->icon()];
    $group = $detail['group']['label'] ?? 'el grupo';
    $seats = $detail['seats'].($detail['seats'] === 1 ? ' lugar' : ' lugares');
    $mine = $canCancel && ! $canDecide;
    $reason = $proposed && ! $canDecide
        ? ($mine ? "Lo decide el jefe de {$detail['to']['code']}; todavía no responde." : "Solo ves este acuerdo: lo decide el jefe de {$detail['to']['code']}.")
        : $acceptBlock;
    $date = fn ($moment): string => $moment->isoFormat('D MMM YYYY').' · '.$moment->format('H:i');
@endphp
<div class="flex min-h-0 flex-1 flex-col" data-testid="agreement-detail" x-data="{ text: '' }" x-effect="if (! $wire.rejecting) { text = '' }">
    <div class="grid gap-2 border-b border-base-300 px-6 pt-[18px] pb-3.5 max-lg:px-4">
        <button type="button" wire:click="back" class="btn btn-ghost btn-sm -ms-2 w-max lg:hidden max-md:h-11 max-md:min-h-11" data-testid="agreement-back"><x-icon name="o-chevron-left" class="h-4 w-4" />Volver</button>
        <h2 class="text-[22px] font-bold leading-tight" tabindex="-1" data-testid="agreement-title">
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

    <div class="grid min-h-0 flex-1 content-start gap-[18px] overflow-auto px-6 py-[18px] max-lg:overflow-visible max-lg:px-4" data-testid="agreement-body">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3.5 md:grid-cols-4">
            @if ($isLoan)
                <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Salón</dt><dd class="font-mono font-medium">{{ $detail['room'] }}</dd></div>
                <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Grupo</dt><dd class="font-mono font-medium">{{ $group }}</dd></div>
                <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Día</dt><dd class="font-medium">{{ $detail['day_name'] }}</dd></div>
                <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Horas</dt><dd class="font-mono font-medium">{{ $detail['hours'] }}</dd></div>
            @else
                <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Grupo</dt><dd class="font-mono font-medium">{{ $group }}</dd></div>
                <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Carrera</dt><dd class="font-mono font-medium">{{ $detail['career']['code'] }}</dd></div>
                <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Lugares</dt><dd class="font-mono font-medium">{{ $detail['seats'] }}</dd></div>
                <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Dueño del grupo</dt><dd class="font-medium">{{ $detail['from']['name'] }}</dd></div>
            @endif
        </dl>

        <div @class(['grid items-start gap-6', 'min-[1480px]:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]' => $isLoan])>
            @if ($isLoan)
                <div class="max-w-2xl min-[1480px]:order-2">
                    <h3 class="mb-2 text-sm font-bold">Semana de <span class="font-mono">{{ $detail['room'] }}</span> · {{ $detail['to']['name'] }}</h3>
                    @include('scheduling::agreements.week')
                </div>
            @endif

            <div class="grid content-start gap-[18px] min-[1480px]:order-1">
                @if (! $proposed)
                    @if ($detail['status'] === \App\Modules\AcademicLoad\Enums\AgreementStatus::Accepted)
                        <x-alert icon="o-check-circle" class="alert-success alert-soft text-sm" data-testid="agreement-note">
                            <div><b class="font-medium">Aceptado por {{ $detail['decided_by'] }}</b><br><span class="font-mono">{{ $date($detail['decided_at']) }}</span></div>
                        </x-alert>
                    @elseif ($detail['status'] === \App\Modules\AcademicLoad\Enums\AgreementStatus::Rejected)
                        <x-alert icon="o-x-circle" class="alert-error alert-soft text-sm" data-testid="agreement-note">
                            <div>
                                <b class="font-medium">Rechazado por {{ $detail['decided_by'] }}</b><br><span class="font-mono">{{ $date($detail['decided_at']) }}</span>
                                <br>Motivo: {{ $detail['comment'] }}
                                @if ($isLoan)<br>La sesión de {{ $group }} se quedó sin salón.@endif
                            </div>
                        </x-alert>
                    @else
                        <x-alert icon="o-minus-circle" class="alert-soft text-sm" data-testid="agreement-note">
                            <div><b class="font-medium">Cancelado por {{ $detail['cancelled_by'] }}</b><br><span class="font-mono">{{ $date($detail['cancelled_at']) }}</span></div>
                        </x-alert>
                    @endif
                @elseif ($rejecting && $canDecide)
                    <div class="grid gap-1.5">
                        <label for="agreement-comment" class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Motivo del rechazo (obligatorio)</label>
                        <textarea id="agreement-comment" wire:model="comment" x-model="text" maxlength="1000" rows="4" aria-describedby="agreement-consequence @error('comment') agreement-comment-error @enderror"
                            class="textarea textarea-bordered min-h-24 w-full" data-testid="agreement-comment"></textarea>
                        @error('comment')
                            <p id="agreement-comment-error" class="flex items-center gap-1.5 text-[12.5px] text-error" role="alert" data-testid="agreement-comment-error"><x-icon name="o-exclamation-circle" class="h-4 w-4" />{{ $message }}</p>
                        @enderror
                    </div>
                    <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm" id="agreement-consequence">
                        <div><b class="font-medium">Al rechazar:</b>
                            @if ($isLoan)
                                la sesión de {{ $group }} del {{ mb_strtolower($detail['day_name']) }} de {{ $detail['hours'] }} se queda sin salón ({{ $detail['room'] }}) y su coordinación tendrá que elegir otro.
                            @else
                                el cupo de {{ $detail['career']['code'] }} ({{ $seats }}) no se abre en {{ $group }}.
                            @endif
                        </div>
                    </x-alert>
                @elseif ($canDecide)
                    @if ($acceptBlock !== null)
                        <x-alert icon="o-x-circle" class="alert-error alert-soft text-sm" id="agreement-reason" data-testid="agreement-block"><div><b class="font-medium">No se puede aceptar.</b> {{ $acceptBlock }}</div></x-alert>
                    @endif
                    <x-alert icon="o-information-circle" class="alert-soft text-sm" data-testid="agreement-note">
                        <div>
                            @if ($isLoan)
                                Si aceptas, {{ $detail['room'] }} queda reservado para {{ $group }}. Si rechazas, la sesión se queda sin salón.
                            @else
                                Si aceptas, el cupo de {{ $detail['career']['code'] }} se abre en {{ $group }}.
                            @endif
                        </div>
                    </x-alert>
                @else
                    <x-alert icon="o-clock" class="alert-soft text-sm" id="agreement-reason" data-testid="agreement-note"><div><b class="font-medium">Enviado a {{ $detail['to']['name'] }}.</b> {{ $reason }}</div></x-alert>
                @endif

                <dl class="grid grid-cols-2 gap-x-4 gap-y-3.5">
                    <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">De</dt><dd class="font-medium"><span class="font-mono">{{ $detail['from']['code'] }}</span> · {{ $detail['from']['name'] }}</dd></div>
                    <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Para</dt><dd class="font-medium"><span class="font-mono">{{ $detail['to']['code'] }}</span> · {{ $detail['to']['name'] }}</dd></div>
                    <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Lo pidió</dt><dd class="font-medium">{{ $detail['requested_by'] ?? '—' }}</dd></div>
                    <div><dt class="block text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Fecha</dt><dd class="font-mono font-medium">{{ $date($detail['requested_at']) }}</dd></div>
                </dl>
                <p data-testid="agreement-context">
                    @if ($isLoan)
                        {{ $mine ? "Pediste {$detail['room']}, salón de {$detail['to']['name']}, para {$group}." : "{$detail['from']['name']} pide {$detail['room']} para {$group}." }}
                        Lo acepta el departamento dueño del salón: {{ $detail['to']['name'] }}.
                    @else
                        {{ $mine ? "Ofreciste {$seats} de {$detail['career']['code']} en {$group}." : "{$detail['from']['name']} abre {$seats} de {$detail['career']['code']} en {$group}." }}
                        Lo acepta el jefe del departamento de {{ $detail['career']['code'] }}: {{ $detail['to']['name'] }}.
                    @endif
                </p>
            </div>
        </div>
    </div>

    @if ($proposed)
        <div class="flex flex-wrap justify-end gap-2.5 border-t border-base-300 bg-base-100 px-6 py-3 max-lg:sticky max-lg:bottom-0 max-lg:z-10 max-lg:px-4 max-md:[&>*]:flex-1" data-testid="agreement-actions">
            @if ($rejecting && $canDecide)
                <x-button label="Volver" class="btn-outline max-md:h-11 max-md:min-h-11" wire:click="cancelReject" data-testid="agreement-reject-back" />
                <x-button label="Confirmar rechazo" class="btn-error max-md:h-11 max-md:min-h-11" wire:click="reject('{{ $detail['id'] }}')" spinner="reject" x-bind:disabled="! text.trim()" data-testid="agreement-reject-confirm" />
            @else
                @if ($canCancel)
                    <x-button label="Cancelar solicitud" icon="o-x-mark" class="btn-ghost mr-auto max-md:h-11 max-md:min-h-11 max-md:mr-0" wire:click="cancel('{{ $detail['id'] }}')" wire:confirm="¿Cancelar la solicitud? Ya no habrá nada que decidir." spinner="cancel" data-testid="agreement-cancel" />
                @endif
                <x-button label="Rechazar…" class="btn-outline btn-error max-md:h-11 max-md:min-h-11" wire:click="confirmReject('{{ $detail['id'] }}')" :disabled="! $canDecide"
                    :aria-describedby="$canDecide ? null : 'agreement-reason'" data-testid="agreement-reject" />
                <x-button label="Aceptar" icon="o-check" class="btn-primary max-md:h-11 max-md:min-h-11" wire:click="accept('{{ $detail['id'] }}')" spinner="accept" :disabled="! $canDecide || $acceptBlock !== null"
                    :aria-describedby="$canDecide && $acceptBlock === null ? null : 'agreement-reason'" data-testid="agreement-accept" />
            @endif
        </div>
    @endif
</div>
