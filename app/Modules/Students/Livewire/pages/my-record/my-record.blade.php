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
                            @if ($openRequest->reposition_reason !== null)
                                <p class="text-sm text-neutral" data-testid="open-request-reason">Motivo: {{ $openRequest->reposition_reason->label() }}</p>
                            @endif
                            <p class="text-sm text-neutral">Pedida el {{ $openRequest->requested_at->setTimezone('America/Ciudad_Juarez')->locale('es')->isoFormat('D [de] MMMM [de] YYYY') }}.</p>
                            @if ($openRequest->scheduled_at !== null)
                                @php $at = $openRequest->scheduled_at->setTimezone('America/Ciudad_Juarez')->locale('es'); @endphp
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

            {{-- CO-07, CO-12: el drawer «Solicitar», una sola hoja que se abre al contestar (maqueta A de .impeccable/mocks/decision/alumnos-2026-11). --}}
            @php
                $siisaeUrl = 'http://siisae.cdjuarez.tecnm.mx/login/login';
                $dataBlocked = $wantsReposition && $siisaeDataCorrect === 'no';
                $noPhotoInSiisae = $wantsReposition && $siisaePhotoVisible === 'no';
                $photoLocked = $noPhotoInSiisae && $availability['photo'] === null;
                $repositionIncomplete = $wantsReposition && ($repositionReason === null || $siisaePhotoVisible === null || $siisaeDataCorrect === null);
                $footerWhy = match (true) {
                    ! $wantsReposition && ! $wantsPhoto => ['information-circle', 'Elige qué necesitas.'],
                    $dataBlocked => ['lock-closed', 'Primero valida tus datos en el SIISAE.'],
                    $repositionIncomplete => ['information-circle', 'Contesta el motivo y las dos preguntas del SIISAE.'],
                    default => null,
                };
                $summary = collect([$wantsReposition ? 'Reposición de credencial física' : null, $wantsPhoto ? 'Foto' : null])->filter()->implode(' y ');
            @endphp
            <x-drawer wire:model="showRequest" right close-on-escape class="w-full max-w-md" role="dialog" aria-modal="true" aria-labelledby="request-title" data-testid="request-drawer">
                <h2 id="request-title" class="text-lg font-bold leading-tight">Solicitar</h2>
                <p class="mt-1 text-neutral">Elige qué necesitas. Comunicación y Difusión te avisará cuándo pasar.</p>
                <div class="mt-4 space-y-3">
                    <div>
                        <label @class(['flex cursor-pointer flex-col gap-0.5 rounded-box border-2 px-3.5 py-3', 'border-primary' => $wantsReposition, 'border-base-300' => ! $wantsReposition, 'cursor-not-allowed opacity-70' => $availability['reposition'] !== null])>
                            <span class="flex items-center gap-3 font-semibold">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="wantsReposition" @disabled($availability['reposition'] !== null) data-testid="want-reposition" />
                                Reposición de credencial física
                            </span>
                            <span class="ml-8 text-sm text-neutral">Sin costo. Te avisamos cuándo pasar por ella.</span>
                        </label>
                        @if ($availability['reposition'] !== null)
                            <p class="ml-8 mt-1 text-sm text-neutral" data-testid="reason-reposition">{{ $availability['reposition'] }}</p>
                        @endif
                        @error('reposition') <p class="ml-8 mt-1 text-sm text-error">{{ $message }}</p> @enderror

                        @if ($wantsReposition)
                            <div class="ml-4 mt-3 space-y-4 border-l-2 border-base-300 pl-4" data-testid="reposition-questions">
                                <fieldset data-testid="reposition-reason">
                                    <legend class="mb-1 font-semibold">Motivo</legend>
                                    @foreach ($reasons as $reason)
                                        <label @class(['flex cursor-pointer items-start gap-3 rounded-field px-2 py-2', 'bg-primary/10' => $repositionReason === $reason->value])>
                                            <input type="radio" class="radio radio-sm mt-0.5" wire:model.live="repositionReason" value="{{ $reason->value }}" data-testid="reason-{{ $reason->value }}" />
                                            <span class="text-sm">{{ $reason->label() }}</span>
                                        </label>
                                    @endforeach
                                    @error('reason') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                                </fieldset>

                                @if ($repositionReason !== null)
                                    <div data-testid="siisae-block">
                                        <h3 class="mb-2 font-semibold">Verifica en el SIISAE</h3>
                                        <div class="space-y-4 rounded-box border border-base-300 bg-base-200/50 p-3.5">
                                            <p class="text-sm">Entra al <abbr title="Sistema Integral de Servicios Administrativos y Estudiantiles del TecNM-ITCJ" class="font-semibold">SIISAE</abbr> (Sistema Integral de Servicios Administrativos y Estudiantiles del TecNM-ITCJ) y revisa tu fotografía y tus datos.</p>
                                            <a href="{{ $siisaeUrl }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline" data-testid="siisae-link"><x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" aria-hidden="true" />Abrir el SIISAE<span class="sr-only"> (se abre en otra pestaña)</span></a>

                                            @foreach (['siisaePhotoVisible' => ['photo_visible', '¿En el SIISAE puedes ver tu fotografía?'], 'siisaeDataCorrect' => ['data_correct', '¿En el SIISAE tus datos son correctos?']] as $model => [$key, $question])
                                                <fieldset data-testid="q-{{ $key }}">
                                                    <legend class="mb-1.5 text-sm font-semibold">{{ $question }}</legend>
                                                    <div class="join" role="radiogroup">
                                                        @foreach (['si' => 'Sí', 'no' => 'No'] as $value => $label)
                                                            <label @class(['btn join-item min-w-[5.5rem]', 'btn-primary' => $this->{$model} === $value])>
                                                                <input type="radio" class="sr-only" wire:model.live="{{ $model }}" value="{{ $value }}" data-testid="{{ $key }}-{{ $value }}" />{{ $label }}
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                    @error($key) <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                                                </fieldset>
                                            @endforeach
                                        </div>

                                        @if ($dataBlocked)
                                            <div class="mt-3 flex gap-3 rounded-box border border-error/40 bg-error/10 p-3.5 text-sm" role="alert" data-testid="data-blocked">
                                                <x-icon name="o-x-circle" class="mt-0.5 w-5 h-5 shrink-0 text-error" aria-hidden="true" />
                                                <div>
                                                    <p class="font-semibold text-error">Todavía no puedes pedir la reposición.</p>
                                                    <p class="mt-1">Valida tus datos en el SIISAE. Si no te aparece la opción «Validar datos», espera a que se publique la convocatoria para validar datos en el SIISAE.</p>
                                                    <a href="{{ $siisaeUrl }}" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1.5 font-semibold text-error underline" data-testid="siisae-link-blocked"><x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" aria-hidden="true" />Ir al SIISAE<span class="sr-only"> (se abre en otra pestaña)</span></a>
                                                </div>
                                            </div>
                                        @elseif ($photoLocked)
                                            <div class="mt-3 flex gap-3 rounded-box border border-info/40 bg-info/10 p-3.5 text-sm" role="status" data-testid="photo-added">
                                                <x-icon name="o-camera" class="mt-0.5 w-5 h-5 shrink-0 text-info" aria-hidden="true" />
                                                <div>
                                                    <p class="font-semibold">Agregamos «Tomarme o actualizar la foto»</p>
                                                    <p class="mt-1">Como el SIISAE no muestra tu fotografía, también te citamos para tomártela.</p>
                                                </div>
                                            </div>
                                        @elseif ($noPhotoInSiisae)
                                            {{-- CO-12 (supuesto por confirmar): la foto no está disponible (plazo o ya hay una abierta); la reposición sigue sin ella. --}}
                                            <div class="mt-3 flex gap-3 rounded-box border border-warning/40 bg-warning/10 p-3.5 text-sm" role="status" data-testid="photo-not-added">
                                                <x-icon name="o-camera" class="mt-0.5 w-5 h-5 shrink-0" aria-hidden="true" />
                                                <div>
                                                    <p class="font-semibold">No pudimos agregar la foto</p>
                                                    <p class="mt-1">{{ $availability['photo'] }} Tu reposición sigue; la foto la pides después.</p>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div>
                        <label @class(['flex cursor-pointer flex-col gap-0.5 rounded-box border-2 px-3.5 py-3', 'border-primary' => $wantsPhoto, 'border-base-300' => ! $wantsPhoto, 'cursor-not-allowed opacity-70' => $availability['photo'] !== null || $photoLocked])>
                            <span class="flex items-center gap-3 font-semibold">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model="wantsPhoto" @disabled($availability['photo'] !== null || $photoLocked) data-testid="want-photo" />
                                Tomarme o actualizar la foto
                            </span>
                            <span class="ml-8 text-sm text-neutral">Te citamos para tomártela.</span>
                        </label>
                        @if ($availability['photo'] !== null)
                            <p class="ml-8 mt-1 text-sm text-neutral" data-testid="reason-photo">{{ $availability['photo'] }}</p>
                        @elseif ($photoLocked)
                            <p class="ml-8 mt-1 flex items-center gap-1.5 text-sm text-neutral" data-testid="reason-photo-locked"><x-icon name="o-lock-closed" class="w-4 h-4" aria-hidden="true" />Se agregó porque no ves tu foto en el SIISAE.</p>
                        @endif
                        @error('photo') <p class="ml-8 mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    </div>
                    @error('request') <p class="text-sm text-error" data-testid="request-error">{{ $message }}</p> @enderror
                </div>
                <x-slot:actions>
                    @if ($footerWhy !== null)
                        <span class="me-auto flex items-center gap-1.5 text-sm text-neutral" id="request-why" data-testid="request-why"><x-icon :name="'o-'.$footerWhy[0]" class="w-4 h-4 shrink-0" aria-hidden="true" />{{ $footerWhy[1] }}</span>
                    @else
                        <span class="me-auto text-sm text-neutral" data-testid="request-summary">Pides: <b>{{ $summary }}</b></span>
                    @endif
                    <x-button label="Cancelar" wire:click="$set('showRequest', false)" />
                    <x-button label="Solicitar" class="btn-primary" wire:click="requestCredential" spinner="requestCredential" :disabled="$footerWhy !== null" aria-describedby="request-why" data-testid="credential-request-submit" />
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
                    Entras con tu NIP del SII. Cámbialo por una contraseña propia: es obligatorio para seguir usando Nexus.
                @else
                    Ya no entras con tu NIP del SII: entras con tu contraseña de Nexus.
                @endif
            </p>
            <x-button label="Cambiar contraseña" icon="o-key" link="{{ route('user-password.edit') }}" class="btn-outline mt-4" data-testid="change-password" />
        </section>
    @endif
</div>
