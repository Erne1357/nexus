{{--
    «Mis datos» del alumno (AC-15), con cuatro pestañas (`?tab=`). «Mis datos»: sus datos escolares como los ficha del alumno (nombre, número de control, estatus y
    la línea de carrera, plan, campus y semestre) y, debajo, su acceso: usuario, correo institucional, de dónde sale su
    contraseña y el enlace para cambiarla.
    «Retícula» y «Kárdex» (1.42): cada una en su pestaña (students::reticula y students::kardex, las mismas de la ficha
    del personal; la retícula es interactiva, con resaltado de cadenas, leyenda y barra de selección, 1.68), con las cifras arriba de ambas (S5). `?tab=perfil` (enlaces viejos) abre «Retícula».
    «Credencial»: su credencial de alumno con la plantilla del ITCJ, su foto y el QR verificable (AC-20, tarea 1.27).
--}}
<div class="space-y-5">
    <x-header title="Mis datos" subtitle="Tus datos escolares, tu retícula, tu kárdex y tu credencial." separator />

    <div role="tablist" class="tabs tabs-border">
        @foreach (['datos' => 'Mis datos', 'reticula' => 'Retícula', 'kardex' => 'Kárdex', 'credencial' => 'Credencial'] as $key => $label)
            <button type="button" role="tab" @class(['tab', 'tab-active font-bold' => $tab === $key]) aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}" data-testid="tab-{{ $key }}">{{ $label }}</button>
        @endforeach
    </div>

    @if (in_array($tab, ['reticula', 'kardex'], true))
        @if ($record === null)
            <x-alert title="Todavía no encontramos tus datos escolares." description="Si eres alumno, acude a Servicios Escolares." icon="o-information-circle" class="alert-info" data-testid="my-record-empty" />
        @else
            <section class="space-y-5" role="tabpanel" id="panel-{{ $tab }}" aria-labelledby="tab-{{ $tab }}" data-testid="{{ $tab === 'kardex' ? 'my-kardex' : 'my-reticula' }}">
                <div class="grid grid-cols-3 overflow-hidden rounded-box border border-base-300 bg-base-100">
                    @foreach ([
                        ['Créditos aprobados', $record->creditsApproved, $record->totalCredits],
                        ['Materias acreditadas', $record->subjectsApproved, $record->subjectsInGrid],
                        ['Cursando', $record->taking, null],
                    ] as [$label, $value, $of])
                        <div class="flex flex-col gap-1.5 border-base-300 px-5 py-3.5 [&:nth-child(n+2)]:border-l">
                            <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</span>
                            <span class="font-mono text-2xl font-semibold tabular-nums">{{ $value }}@if ($of !== null) <span class="text-base font-normal text-neutral">de {{ $of }}</span>@endif</span>
                        </div>
                    @endforeach
                </div>

                @if ($tab === 'kardex')
                    <x-students::kardex :record="$record" audience="alumno" />
                @else
                    <x-students::reticula :record="$record" audience="alumno" />
                @endif
            </section>
        @endif
    @elseif ($tab === 'credencial')
        @if ($credential === null)
            <x-alert title="Todavía no encontramos tus datos escolares." description="Si eres alumno, acude a Servicios Escolares." icon="o-information-circle" class="alert-info" data-testid="my-record-empty" />
        @else
            {{-- La credencial con la plantilla del ITCJ: frente con foto y reverso con el QR que cambia cada 30 s (AC-20, tarea 1.27). --}}
            <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,640px)_minmax(0,1fr)]">
                <x-students::credential-card :credential="$credential" />

                <section class="rounded-box border border-base-300 bg-base-100 p-5" data-testid="credential-request-panel">
                    <h2 class="text-lg font-bold leading-tight">Tu solicitud</h2>
                    @if ($openRequests->isEmpty())
                        <p class="mt-2 text-neutral">No tienes ninguna solicitud abierta. Puedes pedir la reposición de tu credencial física o tomarte o actualizar la foto.</p>
                    @endif
                    @foreach ($openRequests as $openRequest)
                        <div class="mt-3 space-y-3 border-t border-base-300 pt-3 first:border-t-0 first:pt-0" data-testid="open-request">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="badge badge-soft font-medium">{{ $openRequest->status->label() }}</span>
                                <span class="text-neutral">{{ collect([$openRequest->wants_reposition ? 'Reposición de credencial' : null, $openRequest->wants_photo ? 'Foto' : null])->filter()->implode(' y ') }}</span>
                            </div>
                            <p class="text-sm text-neutral">Pedida el {{ $openRequest->requested_at->setTimezone('America/Ciudad_Juarez')->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}.</p>
                            @if ($openRequest->scheduled_at !== null)
                                @php($at = $openRequest->scheduled_at->setTimezone('America/Ciudad_Juarez')->locale('es'))
                                <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-[auto_1fr]" data-testid="request-appointment">
                                    <dt class="text-neutral">Fecha</dt>
                                    <dd class="font-medium">{{ $at->isoFormat('dddd D [de] MMMM [de] YYYY') }}</dd>
                                    <dt class="text-neutral">Hora</dt>
                                    <dd class="font-mono font-medium tabular-nums">{{ $at->format('H:i') }}</dd>
                                    @if ($openRequest->place)
                                        <dt class="text-neutral">Lugar</dt>
                                        <dd class="font-medium">{{ $openRequest->place }}</dd>
                                    @endif
                                </dl>
                            @endif
                            @if ($openRequest->status === \App\Modules\Students\Enums\CredentialRequestStatus::Solicitada)
                                <x-button label="Cancelar solicitud" icon="o-x-mark" class="btn-outline" wire:click="cancelRequest('{{ $openRequest->getKey() }}')" data-testid="cancel-request" />
                            @endif
                        </div>
                    @endforeach
                    {{-- CO-S4: solo pide quien tiene credencial vigente (activo o egresado). --}}
                    @if ($credential['current'] && in_array(null, $availability, true))
                        <x-button label="Solicitar" icon="o-plus" class="btn-primary mt-4" wire:click="$set('showRequest', true)" data-testid="credential-request-open" />
                    @endif
                </section>
            </div>

            <h2 class="text-lg font-bold leading-tight">Historial de solicitudes</h2>
            <x-students::credential-requests-history :requests="$requests" />

            <x-drawer wire:model="showRequest" right close-on-escape class="w-full max-w-md" role="dialog" aria-modal="true" aria-labelledby="request-title" data-testid="request-drawer">
                <h2 id="request-title" class="text-lg font-bold leading-tight">Solicitar</h2>
                <p class="mt-1 text-neutral">Elige qué necesitas. Comunicación y Difusión te avisará cuándo pasar.</p>
                <div class="mt-4 space-y-4">
                    @foreach (['reposition' => ['wantsReposition', 'Reposición de credencial física'], 'photo' => ['wantsPhoto', 'Tomarme o actualizar la foto']] as $type => [$model, $label])
                        <div>
                            <x-checkbox :label="$label" wire:model="{{ $model }}" :disabled="$availability[$type] !== null" data-testid="want-{{ $type }}" />
                            @if ($availability[$type] !== null)
                                <p class="ml-8 mt-1 text-sm text-neutral" data-testid="reason-{{ $type }}">{{ $availability[$type] }}</p>
                            @endif
                            @error($type) <p class="ml-8 mt-1 text-sm text-error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    @error('request') <p class="text-sm text-error" data-testid="request-error">{{ $message }}</p> @enderror
                </div>
                <x-slot:actions>
                    <x-button label="Cerrar" wire:click="$set('showRequest', false)" />
                    <x-button label="Solicitar" class="btn-primary" wire:click="requestCredential" spinner="requestCredential" data-testid="credential-request-submit" />
                </x-slot:actions>
            </x-drawer>
        @endif
    @else

        @if ($student === null)
            <x-alert
                title="Todavía no encontramos tus datos escolares."
                description="Si eres alumno, acude a Servicios Escolares."
                icon="o-information-circle"
                class="alert-info"
                data-testid="my-record-empty"
            />
        @else
            <section class="rounded-box border border-base-300 bg-base-100 p-5" data-testid="my-record">
                <h2 class="flex flex-wrap items-center gap-3 text-xl font-bold leading-tight md:text-2xl">
                    {{ $student->name }}
                    <span class="font-mono text-lg font-semibold text-neutral tabular-nums">{{ $student->control_number }}</span>
                    @include('students::status-badge', ['status' => $student->status])
                </h2>
                <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                    <span>Carrera <span class="font-medium text-base-content"><span class="font-mono">{{ $student->career->code }}</span> · {{ $student->career->name }}</span></span>
                    <span>Plan <span class="font-mono font-medium text-base-content">{{ $student->studyPlan->code }}</span></span>
                    <span>Campus <span class="font-medium text-base-content">{{ $student->campus->name }}</span></span>
                    <span>Semestre <span class="font-mono font-medium text-base-content">{{ $student->current_semester ?? '—' }}</span></span>
                </p>
            </section>
        @endif

        <section class="rounded-box border border-base-300 bg-base-100 p-5" data-testid="my-access">
            <h2 class="text-lg font-bold leading-tight">Tu acceso</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-[auto_1fr]">
                <dt class="text-neutral">Usuario</dt>
                <dd class="font-mono font-medium">{{ $username }}</dd>
                <dt class="text-neutral">Correo institucional</dt>
                <dd class="font-medium break-all">{{ $email ?? 'Sin correo institucional' }}</dd>
            </dl>
            <p class="mt-3">
                @if ($usesSiiNip)
                    Entras con tu NIP del SII. Puedes cambiarlo por una contraseña propia cuando quieras; no es obligatorio.
                @else
                    Ya no entras con tu NIP del SII: entras con tu contraseña de Nexus.
                @endif
            </p>
            <x-button label="Cambiar contraseña" icon="o-key" link="{{ route('user-password.edit') }}" class="btn-outline mt-4" data-testid="change-password" />
        </section>
    @endif
</div>
