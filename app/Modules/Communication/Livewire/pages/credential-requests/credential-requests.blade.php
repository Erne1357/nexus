{{--
    «Solicitudes de credencial» (communication::pages.credential-requests, CO-07 a CO-09): pestañas por estado con su
    conteo, barra de herramientas común (búsqueda y «Plazos»), tabla en una superficie con filete con la siguiente acción
    visible y lo demás en «Acciones», y debajo de 768 px renglones apilados. Cada solicitud se atiende en un drawer
    (ADR-0023); el rechazo pide su motivo en un modal. Maqueta A de .impeccable/mocks/decision/comunicacion.
--}}
@php
    use App\Modules\Students\Enums\CredentialRequestStatus as Status;
    $tz = 'America/Ciudad_Juarez';
    $asked = fn ($request): string => collect([$request->wants_reposition ? 'Reposición de credencial' : null, $request->wants_photo ? 'Foto' : null])->filter()->implode(' y ');
    $when = fn ($date): string => $date->setTimezone($tz)->locale('es')->isoFormat('D MMM YYYY, HH:mm');
    $tone = fn (Status $status): string => match ($status) {
        Status::Solicitada => 'info', Status::Citada => 'warning', Status::Lista => 'primary',
        Status::Entregada => 'success', Status::Rechazada => 'error', Status::Cancelada => 'draft',
    };
    $next = fn ($request): string => match ($request->status) {
        Status::Solicitada => 'Citar',
        Status::Citada => $request->wants_reposition ? 'Marcar lista' : 'Entregar',
        Status::Lista => 'Entregar',
        default => 'Ver',
    };
@endphp
<div>
    <x-header title="Solicitudes de credencial" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Las reposiciones de credencial y las fotos que piden los alumnos: cita, foto, entrega o rechazo.
        </x-slot:subtitle>
    </x-header>

    <div role="tablist" class="tabs tabs-border mb-4 max-md:overflow-x-auto" aria-label="Estado de la solicitud" data-testid="request-tabs">
        @foreach ($tabs as $status)
            <button type="button" role="tab" wire:key="tab-{{ $status->value }}" @class(['tab gap-2 whitespace-nowrap', 'tab-active font-bold' => $tab === $status->value]) aria-selected="{{ $tab === $status->value ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $status->value }}')" data-testid="tab-{{ $status->value }}">
                {{ $status->label() }}<span class="font-mono text-xs tabular-nums text-neutral">{{ $counts[$status->value] }}</span>
            </button>
        @endforeach
    </div>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input wire:model.live.debounce.300ms="search" placeholder="Número de control o nombre" aria-label="Buscar solicitudes" icon="o-magnifying-glass" clearable />
        </x-slot:search>

        <x-slot:action>
            <x-button label="Plazos" icon="o-adjustments-horizontal" wire:click="openSettings" spinner="openSettings" data-testid="settings-open" />
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        @if ($requests->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-neutral" data-testid="empty">No hay solicitudes en este estado.</p>
        @else
            <table class="table max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40" data-testid="requests-table">
                <thead>
                    <tr>
                        <th>Alumno</th>
                        <th>Pidió</th>
                        <th class="max-[1023px]:hidden">Motivo</th>
                        <th class="max-[1023px]:hidden">En el SIISAE</th>
                        <th class="max-[1023px]:hidden">Solicitada</th>
                        <th>Cita</th>
                        <th class="w-px"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requests as $row)
                        <tr wire:key="row-{{ $row->id }}" data-testid="request-row">
                            <td>
                                <p class="font-semibold">{{ $row->student->name }}</p>
                                <p class="font-mono text-sm tabular-nums text-neutral">{{ $row->student->control_number }}</p>
                            </td>
                            <td class="text-sm">{{ $asked($row) }}</td>
                            <td class="text-sm max-[1023px]:hidden" data-testid="row-reason">{{ $row->reposition_reason?->label() ?? '—' }}</td>
                            <td class="max-[1023px]:hidden" data-testid="row-siisae">@include('communication::credential-requests.siisae-badges', ['row' => $row])</td>
                            <td class="whitespace-nowrap text-sm text-neutral max-[1023px]:hidden">{{ $when($row->requested_at) }}</td>
                            <td class="text-sm text-neutral">
                                @if ($row->scheduled_at !== null && in_array($row->status, [Status::Citada, Status::Lista], true))
                                    {{ $when($row->scheduled_at) }}<br>{{ $row->place }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>@include('communication::credential-requests.row-actions', ['row' => $row, 'next' => $next($row), 'inTable' => true])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <ul class="divide-y divide-base-300 md:hidden" data-testid="requests-cards">
                @foreach ($requests as $row)
                    <li wire:key="card-{{ $row->id }}" class="space-y-1 px-4 py-3">
                        <p class="font-semibold">{{ $row->student->name }}</p>
                        <p class="text-sm text-neutral"><span class="font-mono tabular-nums">{{ $row->student->control_number }}</span> · {{ $asked($row) }}</p>
                        @if ($row->reposition_reason !== null)
                            <p class="text-sm text-neutral">{{ $row->reposition_reason->label() }}</p>
                            @include('communication::credential-requests.siisae-badges', ['row' => $row])
                        @endif
                        @if ($row->scheduled_at !== null && in_array($row->status, [Status::Citada, Status::Lista], true))
                            <p class="text-sm text-neutral">{{ $when($row->scheduled_at) }} · {{ $row->place }}</p>
                        @endif
                        @include('communication::credential-requests.row-actions', ['row' => $row, 'next' => $next($row), 'inTable' => false])
                    </li>
                @endforeach
            </ul>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                <span>{{ $requests->total() }} {{ $requests->total() === 1 ? 'solicitud' : 'solicitudes' }}</span>
                {{ $requests->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <x-drawer wire:model.live="showRequest" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="request-drawer-title" data-testid="request-drawer">
        @if ($showRequest && $current !== null)
            @php($student = $current->student)
            @php($open = $current->status->isOpen())
            <div class="flex h-dvh flex-col">
                <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
                    @include('communication::student-photos.thumb', ['url' => $currentPhoto, 'initials' => '', 'size' => 'w-14'])
                    <div class="min-w-0">
                        <h2 id="request-drawer-title" class="text-xl font-bold">{{ $student->name }}</h2>
                        <p class="text-sm text-neutral"><span class="font-mono tabular-nums">{{ $student->control_number }}</span> · {{ $student->career?->name }}</p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto max-md:size-11" wire:click="closeRequest" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
                </header>

                <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    @error('request')
                        <p class="rounded-box border border-error/40 bg-error/10 px-3 py-2 text-sm text-error" role="alert" data-testid="request-error">{{ $message }}</p>
                    @enderror

                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                        <dt class="text-neutral">Estado</dt>
                        <dd><x-core::status-badge :tone="$tone($current->status)" :label="$current->status->label()" data-testid="request-status" /></dd>
                        <dt class="text-neutral">Pidió</dt>
                        <dd>{{ $asked($current) }}</dd>
                        @if ($current->reposition_reason !== null)
                            <dt class="text-neutral">Motivo</dt>
                            <dd data-testid="request-reason">{{ $current->reposition_reason->label() }}</dd>
                            <dt class="text-neutral">En el SIISAE</dt>
                            <dd>
                                @include('communication::credential-requests.siisae-badges', ['row' => $current])
                                @if ($current->photoAddedBySiisae())
                                    <p class="mt-1.5 text-neutral" data-testid="photo-auto-note">La foto se agregó sola porque no ve su foto en el SIISAE.</p>
                                @endif
                            </dd>
                        @endif
                        <dt class="text-neutral">Solicitada</dt>
                        <dd>{{ $when($current->requested_at) }}</dd>
                        @if ($current->scheduled_at !== null)
                            <dt class="text-neutral">Cita</dt>
                            <dd>{{ $when($current->scheduled_at) }} · {{ $current->place }}</dd>
                        @endif
                        @if ($current->status === Status::Rechazada)
                            <dt class="text-neutral">Motivo del rechazo</dt>
                            <dd>{{ $current->rejection_reason }}</dd>
                        @endif
                    </dl>

                    @if ($open && in_array($current->status, [Status::Solicitada, Status::Citada], true))
                        <form wire:submit="schedule" class="space-y-3" data-testid="schedule-form">
                            <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">{{ $current->status === Status::Citada ? 'Reprogramar' : 'Citar' }}</h3>
                            <div class="grid grid-cols-2 gap-3">
                                <x-input label="Fecha" type="date" wire:model="scheduleDate" data-testid="schedule-date" />
                                <x-input label="Hora" type="time" wire:model="scheduleTime" data-testid="schedule-time" />
                            </div>
                            <x-input label="Lugar" wire:model="place" placeholder="Edificio y ventanilla" data-testid="schedule-place" />
                            <x-button :label="$current->status === Status::Citada ? 'Reprogramar' : 'Citar'" type="submit" icon="o-calendar-days" class="btn-primary" spinner="schedule" data-testid="schedule-submit" />
                        </form>
                    @endif

                    @if ($open && $canManagePhotos)
                        <form wire:submit="savePhoto" class="space-y-3" data-testid="photo-form">
                            <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Foto</h3>
                            <x-file label="{{ $currentPhoto === null ? 'Subir foto' : 'Cambiar foto' }}" name="photo" wire:model="photo" accept="image/jpeg,image/png,image/webp" hint="jpg, png o webp de hasta 10 MB." data-testid="photo-input" />
                            @error('photo')
                                <p class="text-sm text-error" data-testid="photo-error">{{ $message }}</p>
                            @enderror
                            <div class="grid grid-cols-2 gap-4">
                                @if ($currentPhoto !== null)
                                    <figure class="space-y-1.5">
                                        <img src="{{ $currentPhoto }}" alt="Foto actual de {{ $student->name }}" class="aspect-3/4 w-full rounded-box border border-base-300 bg-base-200 object-cover object-top" />
                                        <figcaption class="text-xs text-neutral">Actual</figcaption>
                                    </figure>
                                @endif
                                @if ($preview !== null)
                                    <figure class="space-y-1.5" data-testid="photo-preview">
                                        <img src="{{ $preview }}" alt="Vista previa del recorte" class="aspect-3/4 w-full rounded-box border border-base-300 bg-base-200 object-cover object-top" />
                                        <figcaption class="text-xs text-neutral">Así se verá: recorte 3:4.</figcaption>
                                    </figure>
                                @endif
                            </div>
                            @if ($photo !== null)
                                <x-button label="Guardar foto" type="submit" icon="o-check" class="btn-sm" spinner="savePhoto" data-testid="photo-save" />
                            @endif
                        </form>
                    @endif
                </div>

                <footer class="flex flex-wrap justify-end gap-2 border-t border-base-300 px-5 py-3">
                    @if ($open)
                        <x-button label="Rechazar" icon="o-no-symbol" class="btn-ghost text-error me-auto" wire:click="openReject" data-testid="reject-open" />
                    @endif
                    @if ($current->status === Status::Citada && $current->wants_reposition)
                        <x-button label="Marcar lista" icon="o-check-circle" wire:click="markReady" spinner="markReady" data-testid="mark-ready" />
                    @endif
                    @if ($current->status === Status::Lista || ($current->status === Status::Citada && ! $current->wants_reposition))
                        <x-button label="Entregar" icon="o-check" class="btn-primary" wire:click="deliver" spinner="deliver" data-testid="deliver" />
                    @endif
                    <x-button label="Cerrar" wire:click="closeRequest" />
                </footer>
            </div>
        @endif
    </x-drawer>

    <x-modal wire:model="showReject" title="Rechazar solicitud" box-class="max-w-md" separator data-testid="reject-modal">
        <form wire:submit="reject" class="space-y-3">
            <x-textarea label="Motivo" wire:model="reason" rows="3" hint="El alumno lo ve en su solicitud." data-testid="reject-reason" />
            @error('reason')
                <p class="text-sm text-error" data-testid="reject-error">{{ $message }}</p>
            @enderror
            <div class="flex justify-end gap-2">
                <x-button label="Cancelar" wire:click="closeReject" />
                <x-button label="Rechazar" type="submit" icon="o-no-symbol" class="btn-error" spinner="reject" data-testid="reject-submit" />
            </div>
        </form>
    </x-modal>

    <x-drawer wire:model.live="showSettings" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="settings-drawer-title" data-testid="settings-drawer">
        @if ($showSettings)
            <form wire:submit="saveSettings" class="flex h-dvh flex-col">
                <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
                    <div>
                        <h2 id="settings-drawer-title" class="text-xl font-bold">Plazos</h2>
                        <p class="text-sm text-neutral">Días que deben pasar desde la última solicitud entregada del mismo tipo para que el alumno pida otra. 0 es sin límite.</p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto max-md:size-11" wire:click="closeSettings" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
                </header>
                <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    <x-input label="Reposición de credencial" type="number" min="0" max="3650" suffix="días" wire:model="repositionDays" data-testid="reposition-days" />
                    <x-input label="Foto" type="number" min="0" max="3650" suffix="días" wire:model="photoDays" data-testid="photo-days" />
                </div>
                <footer class="flex flex-wrap justify-end gap-2 border-t border-base-300 px-5 py-3">
                    <x-button label="Cancelar" wire:click="closeSettings" />
                    <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="saveSettings" data-testid="settings-save" />
                </footer>
            </form>
        @endif
    </x-drawer>
</div>
