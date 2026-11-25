{{--
    Ficha del docente (DESIGN.md, "Ficha del docente"), con la forma de la ficha del alumno: un enlace de regreso a
    Docentes, el encabezado con su nombre, número y estado, sus datos clave y a la derecha «Editar datos» y
    «Acciones» (baja o reactivación y, según su acceso, «Enviar invitación» o «Reenviar invitación» si su usuario no
    tiene contraseña, CF-07 y AC-07, o «Restablecer contraseña» por enlace si la tiene, CF-08, cada una deshabilitada con
    su motivo para quien no la puede; con la cuenta dada de baja en el panel, CF-09, solo «Se reactiva desde el panel»);
    una franja de cifras (horas base del periodo activo, plazas con las que cuenta y asignaciones) y cinco
    pestañas (`tabs tabs-border`): Datos, Plazas, Disponibilidad, Perfil (materias apto/preferente y «Carga académica en
    otra modalidad») e Historial (grupos por periodo y evaluaciones, de solo lectura). Disponibilidad es la vista de solo lectura
    del periodo activo con el enlace al editor (faculty::teachers.availability-tab). Agregar o editar una plaza y quitar
    una asignación o una plaza
    se hacen en un modal. Los renglones de asignaciones y plazas son una cuadrícula que se apila debajo de 768 px.
--}}
<div class="space-y-5">
    <x-core::back-link :validation="$this->validationReturnUrl()" :href="route('faculty.teachers')" label="Docentes" />

    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="flex min-w-0 items-start gap-4">
            {{-- CO-04: la foto del docente (URL firmada de 2 horas); sin foto, sus iniciales. --}}
            @if ($record->user !== null && ($photo = \App\Modules\Core\Support\StaffPhotoUrl::for($record->user, mini: true)) !== null)
                <img src="{{ $photo }}" alt="" data-testid="teacher-photo" class="h-14 w-14 shrink-0 rounded-full bg-base-300 object-cover object-top" />
            @else
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-base-300 text-lg font-semibold" aria-hidden="true">{{ \Illuminate\Support\Str::of($record->teacher->name)->explode(' ')->filter()->take(2)->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))->implode('') }}</span>
            @endif

        <div class="min-w-0">
            <h1 class="flex flex-wrap items-center gap-3 text-[1.625rem] font-bold leading-tight md:text-3xl">
                {{ $record->teacher->name }}
                <span class="font-mono text-xl font-semibold text-neutral tabular-nums">{{ $record->teacher->rfc }}</span>
                <x-core::status-badge :tone="$record->teacher->is_active ? 'success' : 'neutral'" :label="$record->teacher->is_active ? 'Activo' : 'Inactivo'" />
            </h1>
            <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                @php($primary = $record->adscriptions->first())
                <span>Asignación principal <span class="font-medium text-base-content">@if ($primary)<span class="font-mono">{{ $primary->department?->code }}</span> · {{ $primary->department?->name }}@else — @endif</span></span>
                @if ($record->adscriptions->count() > 1)
                    <span>y <span class="font-medium text-base-content">{{ $record->adscriptions->count() - 1 }} {{ $record->adscriptions->count() === 2 ? 'otra' : 'otras' }}</span></span>
                @endif
                <span>Grado <span class="font-medium text-base-content">{{ $record->teacher->degree?->label() ?? '—' }}</span></span>
                <span>Ingreso <span class="font-medium text-base-content">{{ $record->teacher->hired_on?->format('d/m/Y') ?? '—' }}</span></span>
            </p>
        </div>
        </div>

        <div class="flex gap-2 max-md:w-full">
            <x-button label="Editar datos" icon="o-pencil-square" class="btn-outline" wire:click="editData" spinner="editData" data-testid="edit-data" />

            <x-core::row-menu :name="$record->teacher->rfc">
                @if ($canDeactivate)
                    <x-core::row-menu-item
                        :label="$record->teacher->is_active ? 'Desactivar' : 'Activar'"
                        wire:click="toggleActive"
                        x-confirm="{{ $record->teacher->is_active ? 'Se desactiva su usuario y terminan sus jefaturas vigentes.' : 'Su usuario vuelve a estar activo.' }}"
                        data-confirm-title="{{ $record->teacher->is_active ? '¿Dar de baja al docente '.$record->teacher->rfc.'?' : '¿Activar al docente '.$record->teacher->rfc.'?' }}"
                        data-confirm-button="{{ $record->teacher->is_active ? 'Dar de baja' : 'Activar' }}"
                        data-confirm-tone="{{ $record->teacher->is_active ? 'error' : 'primary' }}"
                        spinner="toggleActive"
                        data-testid="toggle-active"
                    />
                @else
                    <x-core::row-menu-item :label="$record->teacher->is_active ? 'Desactivar' : 'Activar'" :reason="$deactivateReason" disabled data-testid="toggle-active" />
                @endif
                @if ($panelDeactivated)
                    @if ($canOpenPerson)
                        <x-core::row-menu-item label="Se reactiva desde el panel" reason="Cuenta dada de baja por el admin." :href="route('core.people.show', $record->user->id)" data-testid="reactivate-from-panel" />
                    @else
                        <x-core::row-menu-item label="Se reactiva desde el panel" reason="Cuenta dada de baja por el admin." disabled data-testid="reactivate-from-panel" />
                    @endif
                @endif
                @if ($resetReason === '')
                    <x-core::row-menu-item
                        label="Restablecer contraseña"
                        wire:click="resetPassword"
                        x-confirm="Le llega a {{ $record->user->email }} un enlace para elegir una contraseña nueva. Vale {{ $resetMinutes }} minutos."
                        data-confirm-title="¿Mandar el enlace a {{ $record->teacher->rfc }}?"
                        data-confirm-button="Mandar enlace"
                        data-confirm-tone="primary"
                        spinner="resetPassword"
                        data-testid="reset-password"
                    />
                @elseif ($resetReason !== null)
                    <x-core::row-menu-item label="Restablecer contraseña" :reason="$resetReason" disabled data-testid="reset-password" />
                @endif
                @if ($personalInvite !== null)
                    <x-core::row-menu-item
                        label="Reenviar al correo personal"
                        wire:click="resendToPersonal"
                        x-confirm="No tiene correo institucional y está en una orden de selección: se manda un enlace nuevo a {{ $personalInvite }}; el anterior deja de servir."
                        data-confirm-title="¿Reenviar la invitación de {{ $record->teacher->rfc }} a su correo personal?"
                        data-confirm-button="Reenviar"
                        data-confirm-tone="primary"
                        spinner="resendToPersonal"
                        data-testid="resend-personal-invitation"
                    />
                @elseif ($inviteReason === '' && $invited)
                    <x-core::row-menu-item
                        label="Reenviar invitación"
                        wire:click="resendInvitation"
                        x-confirm="Se manda un enlace nuevo a {{ $record->teacher->institutional_email }}; el anterior deja de servir."
                        data-confirm-title="¿Reenviar la invitación de {{ $record->teacher->rfc }}?"
                        data-confirm-button="Reenviar"
                        data-confirm-tone="primary"
                        spinner="resendInvitation"
                        data-testid="resend-invitation"
                    />
                @elseif ($inviteReason === '')
                    <x-core::row-menu-item
                        label="Enviar invitación"
                        wire:click="resendInvitation"
                        x-confirm="Se manda la invitación a {{ $record->teacher->institutional_email }}."
                        data-confirm-title="¿Enviar la invitación de {{ $record->teacher->rfc }}?"
                        data-confirm-button="Enviar"
                        data-confirm-tone="primary"
                        spinner="resendInvitation"
                        data-testid="resend-invitation"
                    />
                @elseif ($inviteReason !== null)
                    <x-core::row-menu-item :label="$invited ? 'Reenviar invitación' : 'Enviar invitación'" :reason="$inviteReason" disabled data-testid="resend-invitation" />
                @endif
            </x-core::row-menu>
        </div>
    </div>

    <div class="grid grid-cols-1 overflow-hidden rounded-box border border-base-300 bg-base-100 min-[560px]:grid-cols-3" data-testid="teacher-figures">
        <div class="flex flex-col gap-1.5 px-5 py-3.5">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Horas base{{ $record->term ? ' · '.$record->term->name : '' }}</span>
            <span class="font-mono text-2xl font-semibold tabular-nums">{{ $record->baseHours ?? '—' }}<span class="text-base font-normal text-neutral"> h</span></span>
        </div>
        <div class="flex flex-col gap-1.5 border-base-300 px-5 py-3.5 max-[559px]:border-t min-[560px]:border-l">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Plazas con las que cuenta</span>
            <span class="font-mono text-2xl font-semibold tabular-nums">{{ count(array_filter($record->countsInTerm)) }}<span class="text-base font-normal text-neutral"> de {{ $record->positions->count() }}</span></span>
        </div>
        <div class="flex flex-col gap-1.5 border-base-300 px-5 py-3.5 max-[559px]:border-t min-[560px]:border-l">
            <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Asignaciones</span>
            <span class="font-mono text-2xl font-semibold tabular-nums">{{ $record->adscriptions->count() }}</span>
        </div>
    </div>

    <div role="tablist" aria-label="Secciones de la ficha" class="tabs tabs-border flex-nowrap overflow-x-auto">
        @foreach ($tabs as $key => [$label, $count])
            <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" data-testid="tab-{{ $key }}"
                wire:click="$set('tab', '{{ $key }}')" @class(['tab shrink-0 gap-2', 'tab-active font-bold' => $tab === $key])>
                {{ $label }}@if ($count !== null)<span class="badge badge-neutral badge-soft badge-sm font-mono">{{ $count }}</span>@endif
            </button>
        @endforeach
    </div>

    <div id="panel" role="tabpanel" aria-labelledby="tab-{{ $tab }}" class="space-y-5">
        @if ($tab === 'plazas')
            <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-plazas" data-testid="positions">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 px-5 py-3">
                    <h2 id="h-plazas" class="text-lg font-bold">Plazas <span class="font-mono text-neutral">{{ $record->positions->count() }}</span></h2>
                    <x-button label="Agregar plaza" icon="o-plus" class="btn-outline btn-sm" wire:click="createPosition" spinner="createPosition" data-testid="add-position" />
                </header>

                @forelse ($record->positions as $position)
                    @php($status = $record->positionStatus[$position->id])
                    <div wire:key="position-{{ $position->id }}" @class(['grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 border-b border-base-300 px-5 py-3 md:grid-cols-[minmax(0,1fr)_5rem_8rem_8rem_9rem_auto]', 'text-neutral' => $status === 'vencida'])>
                        <span @class(['font-semibold', 'font-normal' => $status === 'vencida'])>{{ $position->kind->label() }}</span>
                        <span class="font-mono tabular-nums md:text-right"><span class="font-semibold">{{ $position->base_hours }}</span> h</span>
                        <span class="max-md:col-span-2"><span class="text-neutral md:hidden">Desde </span>{{ $position->valid_from?->format('d/m/Y') ?? 'Sin fecha' }}</span>
                        <span class="max-md:col-span-2"><span class="text-neutral md:hidden">Hasta </span>{{ $position->valid_to?->format('d/m/Y') ?? 'Sin fecha' }}</span>
                        <span class="max-md:col-span-2">
                            <x-core::status-badge
                                :tone="['vigente' => 'success', 'por_empezar' => 'info', 'vencida' => 'neutral'][$status]"
                                :label="['vigente' => 'Vigente', 'por_empezar' => 'Por empezar', 'vencida' => 'Vencida'][$status]"
                            />
                        </span>
                        <span class="flex justify-end gap-1 max-md:col-span-2 max-md:justify-start max-md:-ms-3">
                            <x-button label="Editar" icon="o-pencil-square" class="btn-ghost btn-sm" wire:click="editPosition('{{ $position->id }}')" spinner="editPosition('{{ $position->id }}')" :data-testid="'edit-position-'.$loop->index" aria-label="Editar la plaza {{ $position->kind->label() }}" />
                            <x-button label="Quitar" icon="o-trash" class="btn-ghost btn-sm text-error" wire:click="confirmRemovePosition('{{ $position->id }}')" spinner="confirmRemovePosition('{{ $position->id }}')" :data-testid="'remove-position-'.$loop->index" aria-label="Quitar la plaza {{ $position->kind->label() }}" />
                        </span>
                    </div>
                @empty
                    <p class="px-5 py-6 text-center text-neutral" data-testid="positions-empty">No tiene plazas: sin ellas no tiene horas base.</p>
                @endforelse

                <footer class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5 text-sm text-neutral">
                    @if ($record->term)
                        <span>Horas base en {{ $record->term->name }}: <span class="font-mono font-semibold text-base-content">{{ $record->baseHours }} h</span></span>
                        <span><span class="font-mono">{{ count(array_filter($record->countsInTerm)) }}</span> {{ count(array_filter($record->countsInTerm)) === 1 ? 'plaza vigente' : 'plazas vigentes' }} el {{ $record->term->starts_on->format('d/m/Y') }}</span>
                    @else
                        <span>Sin periodo activo no hay horas base.</span>
                    @endif
                </footer>
            </section>
        @elseif ($tab === 'perfil')
            @php($preferred = $profile->filter(fn ($qualification) => $qualification->level === \App\Modules\Faculty\Enums\QualificationLevel::Preferente)->count())
            <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-perfil" data-testid="qualifications">
                <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-base-300 px-5 py-3">
                    <h2 id="h-perfil" class="text-lg font-bold">Perfil <span class="font-mono text-neutral">{{ $profile->count() }}</span></h2>
                    <span class="text-sm text-neutral max-md:order-last max-md:w-full">{{ $preferred }} {{ $preferred === 1 ? 'preferente' : 'preferentes' }} · {{ $profile->count() - $preferred }} {{ $profile->count() - $preferred === 1 ? 'apta' : 'aptas' }}</span>
                    <x-button label="Agregar materia" icon="o-plus" class="btn-outline btn-sm" wire:click="createQualification" spinner="createQualification" data-testid="add-qualification" />
                </header>

                @forelse ($profile as $qualification)
                    <div wire:key="qualification-{{ $qualification->id }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 border-b border-base-300 px-5 py-3 md:grid-cols-[6rem_minmax(0,1fr)_8rem_7rem_10rem_auto]">
                        <span class="font-mono text-neutral">{{ $qualification->subject?->code }}</span>
                        <span class="min-w-0 max-md:col-start-1 max-md:row-start-2 max-md:col-span-2">
                            <span class="font-semibold">{{ $qualification->subject?->name }}</span>
                            <span class="block text-sm text-neutral">{{ $qualification->subject?->department?->name }}</span>
                        </span>
                        <span class="max-md:col-start-2 max-md:row-start-1 max-md:justify-self-end">
                            <x-core::status-badge :tone="$qualification->level === \App\Modules\Faculty\Enums\QualificationLevel::Preferente ? 'primary' : 'neutral'" :label="$qualification->level->label()" />
                            @if ($qualification->source === \App\Modules\Faculty\Enums\QualificationSource::History)
                                <x-badge value="Del historial" class="badge-ghost badge-sm" data-testid="qualification-from-history" />
                            @endif
                        </span>
                        <span class="max-md:col-span-2 md:text-right"><span class="font-mono font-semibold tabular-nums">{{ $qualification->taught_count }}</span> {{ $qualification->taught_count === 1 ? 'vez' : 'veces' }}<span class="text-neutral md:hidden"> impartida</span></span>
                        <span class="min-w-0 text-sm text-neutral max-md:col-span-2">Calificó <span class="text-base-content">{{ $qualification->qualifier?->name ?? '—' }}</span>, {{ $qualification->updated_at->format('d/m/Y') }}</span>
                        <span class="flex justify-end gap-1 max-md:col-span-2 max-md:justify-start max-md:-ms-3">
                            @if ($canQualify[$qualification->id])
                                <x-button label="Editar" icon="o-pencil-square" class="btn-ghost btn-sm" wire:click="editQualification('{{ $qualification->id }}')" spinner="editQualification('{{ $qualification->id }}')" :data-testid="'edit-qualification-'.$loop->index" aria-label="Editar {{ $qualification->subject?->code }} del perfil" />
                                <x-button label="Quitar" icon="o-trash" class="btn-ghost btn-sm text-error" wire:click="removeQualification('{{ $qualification->id }}')" x-confirm="Queda en la bitácora." data-confirm-title="¿Quitar {{ $qualification->subject?->code }} del perfil de {{ $record->teacher->name }}?" data-confirm-button="Quitar" data-confirm-tone="error" spinner="removeQualification('{{ $qualification->id }}')" :data-testid="'remove-qualification-'.$loop->index" aria-label="Quitar {{ $qualification->subject?->code }} del perfil" />
                            @else
                                <span class="tooltip tooltip-left" data-tip="Solo quien administra el departamento de la materia cambia su perfil.">
                                    <x-button label="Editar" icon="o-pencil-square" class="btn-ghost btn-sm" disabled aria-label="Editar {{ $qualification->subject?->code }}: solo quien administra su departamento" />
                                </span>
                            @endif
                        </span>
                    </div>
                @empty
                    <p class="px-5 py-6 text-center text-neutral" data-testid="qualifications-empty">Todavía no tiene materias en su perfil: sin ellas el motor no la sugiere para ninguna.</p>
                @endforelse
            </section>

            <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-loads" data-testid="external-loads">
                <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-base-300 px-5 py-3">
                    <h2 id="h-loads" class="text-lg font-bold">Carga académica en otra modalidad @if ($record->term)<span class="font-normal text-neutral">· {{ $record->term->name }}</span>@endif</h2>
                    @if ($record->term && $canManageLoads)
                        <x-button label="Agregar horas" icon="o-plus" class="btn-outline btn-sm" wire:click="createLoad" spinner="createLoad" data-testid="add-load" />
                    @endif
                </header>

                @if ($record->term === null)
                    <p class="px-5 py-6 text-center text-neutral" data-testid="loads-no-term">Elige un periodo en la credencial para capturar la carga académica que da en otra modalidad.</p>
                @else
                    @forelse ($loads as $load)
                        <div wire:key="load-{{ $load->id }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 border-b border-base-300 px-5 py-3 md:grid-cols-[minmax(0,1fr)_6rem_minmax(0,1fr)_auto]">
                            <span class="min-w-0 font-semibold">{{ $load->source }}</span>
                            <span class="font-mono tabular-nums max-md:col-start-2 max-md:row-start-1 md:text-right"><span class="font-semibold">{{ $load->hours }}</span> h</span>
                            <span class="min-w-0 text-sm text-neutral max-md:col-span-2">{{ $load->notes }}</span>
                            <span class="flex justify-end gap-1 max-md:col-span-2 max-md:justify-start max-md:-ms-3">
                                @if ($canManageLoads)
                                    <x-button label="Editar" icon="o-pencil-square" class="btn-ghost btn-sm" wire:click="editLoad('{{ $load->id }}')" spinner="editLoad('{{ $load->id }}')" :data-testid="'edit-load-'.$loop->index" aria-label="Editar las horas de {{ $load->source }}" />
                                    <x-button label="Quitar" icon="o-trash" class="btn-ghost btn-sm text-error" wire:click="removeLoad('{{ $load->id }}')" x-confirm="Queda en la bitácora." data-confirm-title="¿Quitar las {{ $load->hours }} h de {{ $load->source }}?" data-confirm-button="Quitar" data-confirm-tone="error" spinner="removeLoad('{{ $load->id }}')" :data-testid="'remove-load-'.$loop->index" aria-label="Quitar las horas de {{ $load->source }}" />
                                @endif
                            </span>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-neutral" data-testid="loads-empty">No tiene carga académica en otra modalidad en {{ $record->term->name }}.</p>
                    @endforelse

                    <footer class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5 text-sm text-neutral">
                        <span>En otra modalidad: <span class="font-mono font-semibold text-base-content">{{ $loads->sum('hours') }} h</span></span>
                        <span>Se restan de lo que se le puede asignar. Horas base: <span class="font-mono font-semibold text-base-content">{{ $record->baseHours }} h</span></span>
                    </footer>
                @endif
            </section>
        @elseif ($tab === 'historial')
            @php($groupCount = $history->sum(fn ($term) => $term['groups']->count()))
            @php($evaluated = $history->filter(fn ($term) => $term['evaluations']->isNotEmpty()))
            <div class="grid items-start gap-5 desktop:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-hist" data-testid="history">
                    <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-base-300 px-5 py-3">
                        <h2 id="h-hist" class="text-lg font-bold">Historial <span class="font-mono text-neutral">{{ $groupCount }}</span></h2>
                        <span class="text-sm text-neutral">Grupos por periodo, del más reciente al más antiguo</span>
                    </header>

                    @forelse ($history->filter(fn ($term) => $term['groups']->isNotEmpty()) as $term)
                        <div wire:key="history-{{ $term['code'] }}" class="border-b border-base-300 last:border-b-0">
                            <div class="flex flex-wrap items-baseline gap-x-3 bg-base-200 px-5 py-2">
                                <span class="font-mono text-neutral">{{ $term['code'] }}</span>
                                <span class="font-semibold">{{ $term['label'] }}</span>
                                <span class="text-sm text-neutral">{{ $term['groups']->count() }} {{ $term['groups']->count() === 1 ? 'grupo' : 'grupos' }}</span>
                            </div>
                            @foreach ($term['groups'] as $group)
                                <div class="grid grid-cols-[5rem_minmax(0,1fr)_3.5rem_3.5rem] items-baseline gap-x-3 px-5 py-2 max-md:grid-cols-[minmax(0,1fr)_auto_auto]">
                                    <span class="font-mono text-neutral max-md:col-span-3">{{ $group->subject?->code }}</span>
                                    <span class="min-w-0">{{ $group->subject?->name }}</span>
                                    <span class="font-mono font-semibold tabular-nums"><span class="text-neutral md:hidden">Grupo </span>{{ $group->group_code }}</span>
                                    <span class="font-mono tabular-nums text-neutral">{{ $group->campus?->code ?? '—' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-neutral" data-testid="history-empty">Todavía no tiene grupos importados del SII.</p>
                    @endforelse
                </section>

                <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-evals" data-testid="evaluations">
                    <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-base-300 px-5 py-3">
                        <h2 id="h-evals" class="text-lg font-bold">Evaluaciones <span class="font-mono text-neutral">{{ $evaluated->count() }}</span></h2>
                        <span class="text-sm text-neutral">Escala de 1 a 5</span>
                    </header>

                    @forelse ($evaluated as $term)
                        @php($scores = $term['evaluations']->mapWithKeys(fn ($evaluation) => [$evaluation->kind->value => $evaluation->score]))
                        <div wire:key="evaluation-{{ $term['code'] }}" class="grid grid-cols-2 gap-x-4 gap-y-1 border-b border-base-300 px-5 py-3 last:border-b-0">
                            <span class="col-span-2 font-semibold">{{ $term['label'] }} <span class="font-mono font-normal text-neutral">{{ $term['code'] }}</span></span>
                            @foreach (\App\Modules\Faculty\Enums\EvaluationKind::cases() as $evalKind)
                                <span class="flex flex-col gap-0.5">
                                    <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $evalKind->label() }}</span>
                                    @if (isset($scores[$evalKind->value]))
                                        <span class="flex items-center gap-2"><span class="font-mono font-semibold tabular-nums">{{ $scores[$evalKind->value] }}</span><progress class="progress progress-primary w-16" value="{{ $scores[$evalKind->value] }}" max="5" aria-hidden="true"></progress></span>
                                    @else
                                        <span class="text-neutral">Sin evaluar</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-neutral" data-testid="evaluations-empty">Todavía no tiene evaluaciones.</p>
                    @endforelse
                </section>
            </div>
        @elseif ($tab === 'carga')
            @include('faculty::teachers.load-tab', ['weekly' => $weekly])
        @elseif ($tab === 'disponibilidad')
            @include('faculty::teachers.availability-tab', ['teacher' => $record->teacher, 'term' => $record->term, 'availability' => $availability, 'editReason' => $availabilityReason, 'back' => $this->validationReturnParams()])
        @else
            <div class="grid items-start gap-5 desktop:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <section class="@container overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-datos">
                    <header class="flex items-center justify-between gap-2 border-b border-base-300 px-5 py-3">
                        <h2 id="h-datos" class="text-lg font-bold">Datos</h2>
                    </header>
                    <dl class="divide-y divide-base-300">
                        @foreach ([
                            ['RFC', '<span class="font-mono">'.e($record->teacher->rfc).'</span>'],
                            ['Nombre', e($record->teacher->name)],
                            ['Correo institucional', $record->teacher->institutional_email ? e($record->teacher->institutional_email) : '<span class="text-neutral">Sin correo institucional</span>'],
                            ['Correo personal', $record->teacher->email ? e($record->teacher->email) : '<span class="text-neutral">Sin correo personal</span>'],
                            ['Grado', $record->teacher->degree ? e($record->teacher->degree->label()) : '<span class="text-neutral">Sin grado</span>'],
                            ['Fecha de ingreso', $record->teacher->hired_on ? e($record->teacher->hired_on->format('d/m/Y')) : '<span class="text-neutral">Sin fecha</span>'],
                        ] as [$term, $value])
                            <div class="grid gap-x-4 gap-y-1 px-5 py-2.5 @lg:grid-cols-[minmax(0,10rem)_minmax(0,1fr)]">
                                <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $term }}</dt>
                                <dd class="min-w-0 break-words">{!! $value !!}</dd>
                            </div>
                        @endforeach
                        <div class="grid gap-x-4 gap-y-1 px-5 py-2.5 @lg:grid-cols-[minmax(0,10rem)_minmax(0,1fr)] @lg:items-center">
                            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Usuario</dt>
                            <dd class="flex flex-wrap items-center gap-2">
                                @if ($record->user && $panelDeactivated)
                                    <span class="font-mono">{{ $record->user->username }}</span>
                                    <x-core::status-badge tone="neutral" label="Cuenta dada de baja por el admin" data-testid="user-deactivated" />
                                @elseif ($record->user)
                                    <span class="font-mono">{{ $record->user->username }}</span>
                                    <x-core::status-badge :tone="$record->user->is_active ? 'success' : 'neutral'" :label="$record->user->is_active ? 'Activo' : 'Inactivo'" />
                                    <x-core::status-badge :tone="$record->access->tone()" :label="$record->access->label()" data-testid="user-access" />
                                @else
                                    <span class="text-neutral">Sin usuario</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-ads" data-testid="adscriptions">
                    <header class="flex items-center justify-between gap-2 border-b border-base-300 px-5 py-3">
                        <h2 id="h-ads" class="text-lg font-bold">Asignaciones <span class="font-mono text-neutral">{{ $record->adscriptions->count() }}</span></h2>
                    </header>

                    @foreach ($record->adscriptions as $adscription)
                        {{-- El nombre arriba y las acciones debajo: «Quitar de <Departamento>» lleva el nombre completo (D10) y no cabe a un lado. --}}
                        <div wire:key="adscription-{{ $adscription->id }}" class="space-y-1 border-b border-base-300 px-5 py-3 last:border-b-0">
                            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-mono text-neutral">{{ $adscription->department?->code }}</span> <span class="font-semibold">{{ $adscription->department?->name }}</span>
                                @if ($adscription->is_primary)
                                    <x-core::status-badge tone="primary" label="Principal" />
                                @endif
                            </div>
                            <div class="-ms-3 flex flex-wrap items-center gap-1">
                                @unless ($adscription->is_primary)
                                    @if ($primaryReasons[$adscription->id] === null)
                                        <x-button label="Hacer principal" class="btn-ghost btn-sm" wire:click="setPrimary('{{ $adscription->id }}')" spinner="setPrimary('{{ $adscription->id }}')" :data-testid="'set-primary-'.$adscription->id" />
                                    @else
                                        <span class="tooltip tooltip-right" data-tip="{{ $primaryReasons[$adscription->id] }}">
                                            <x-button label="Hacer principal" class="btn-ghost btn-sm" disabled :data-testid="'set-primary-'.$adscription->id" aria-label="Hacer principal: {{ $primaryReasons[$adscription->id] }}" />
                                        </span>
                                    @endif
                                @endunless

                                @if ($removal[$adscription->id] === null)
                                    <x-button label="Quitar de {{ $adscription->department?->name }}" icon="o-trash" class="btn-ghost btn-sm h-auto min-h-8 shrink whitespace-normal py-1 text-start text-error" wire:click="confirmRemoveAdscription('{{ $adscription->id }}')" spinner="confirmRemoveAdscription('{{ $adscription->id }}')" :data-testid="'remove-adscription-'.$adscription->id" />
                                @else
                                    <span class="tooltip tooltip-right" data-tip="{{ $removal[$adscription->id] }}">
                                        <x-button label="Quitar de {{ $adscription->department?->name }}" icon="o-trash" class="btn-ghost btn-sm h-auto min-h-8 shrink whitespace-normal py-1 text-start" disabled :data-testid="'remove-adscription-'.$adscription->id" aria-label="Quitar de {{ $adscription->department?->name }}: {{ $removal[$adscription->id] }}" />
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    @if ($departures->isNotEmpty())
                        <footer class="border-t border-base-300 bg-base-200/40 px-5 py-3" data-testid="departures">
                            <h3 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Salidas de departamento</h3>
                            <ul class="mt-1.5 space-y-1.5 text-sm">
                                @foreach ($departures as $departure)
                                    <li wire:key="departure-{{ $loop->index }}">
                                        <span class="font-medium">{{ $departure['department'] ?? 'Un departamento borrado' }}</span>
                                        <span class="text-neutral">· {{ $departure['at']->format('d/m/Y') }}{{ $departure['by'] ? ' · '.$departure['by'] : '' }}{{ $departure['deactivated'] ? ' · Quedó dado de baja' : '' }}</span>
                                        <span class="block">{{ $departure['reason'] ?? 'Sin motivo' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </footer>
                    @endif
                </section>
            </div>

            @if ($canSeeHeadships)
                <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-jef" data-testid="headships">
                    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 px-5 py-3">
                        <h2 id="h-jef" class="text-lg font-bold">Jefaturas <span class="font-mono text-neutral">{{ $headships->count() }}</span></h2>
                        @if ($canAppoint && $record->teacher->is_active)
                            <x-button label="Nombrar jefatura" icon="o-plus" class="btn-outline btn-sm" wire:click="createHeadship" spinner="createHeadship" data-testid="add-headship" />
                        @elseif ($canAppoint)
                            <span class="tooltip tooltip-left" data-tip="Un docente inactivo no se nombra: reactívalo primero.">
                                <x-button label="Nombrar jefatura" icon="o-plus" class="btn-outline btn-sm" disabled data-testid="add-headship" aria-label="Nombrar jefatura: un docente inactivo no se nombra" />
                            </span>
                        @endif
                    </header>

                    @forelse ($headships as $row)
                        @php($head = $row['assignment'])
                        @php($ended = $row['status'] === \App\Modules\Core\Enums\AssignmentStatus::Ended)
                        <div wire:key="headship-{{ $head->id }}" @class(['grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 border-b border-base-300 px-5 py-3 last:border-b-0 md:grid-cols-[minmax(0,1fr)_8rem_8rem_9rem_auto]', 'text-neutral' => $ended])>
                            <div class="min-w-0">
                                <span @class(['font-semibold', 'font-normal' => $ended])>{{ $head->role->label() }}</span>
                                <span class="block text-sm text-neutral">{{ $head->orgUnit?->name }}{{ $head->campus ? ' · '.$head->campus->name : ' · Todo el Tec' }}</span>
                            </div>
                            <span class="max-md:col-span-2"><span class="text-neutral md:hidden">Desde </span>{{ $head->valid_from->format('d/m/Y') }}</span>
                            <span class="max-md:col-span-2"><span class="text-neutral md:hidden">Hasta </span>{{ $head->valid_to?->format('d/m/Y') ?? 'Sin fecha' }}</span>
                            <span class="max-md:col-span-2">
                                <x-core::status-badge
                                    :tone="$row['status']->tone()->value"
                                    :label="$row['status']->label()"
                                />
                            </span>
                            <span class="flex justify-end max-md:col-span-2 max-md:justify-start max-md:-ms-3">
                                @if (! $ended && $canEndHeadships)
                                    <x-button label="Terminar" icon="o-stop-circle" class="btn-ghost btn-sm text-error" wire:click="confirmEndHeadship('{{ $head->id }}')" spinner="confirmEndHeadship('{{ $head->id }}')" :data-testid="'end-headship-'.$head->id" aria-label="Terminar la jefatura de {{ $head->orgUnit?->name }}" />
                                @endif
                            </span>
                        </div>
                    @empty
                        <p class="px-5 py-6 text-center text-neutral" data-testid="headships-empty">No tiene jefaturas.</p>
                    @endforelse
                </section>
            @endif
        @endif
    </div>

    @if ($canAppoint)
        <x-modal wire:model="showHeadshipModal" title="Nombrar jefatura" :subtitle="$record->teacher->name" box-class="max-w-2xl" separator>
            <x-form wire:submit="saveHeadship" no-separator>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-select label="Cargo" name="headKind" wire:model.live="headKind" :options="$headKinds" placeholder="Elige el cargo" />
                    <x-select label="Departamento" name="headDepartmentId" wire:model="headDepartmentId" :options="$departmentOptions" placeholder="Elige el departamento" />
                    @if ($headKind === \App\Modules\Core\Enums\Role::JefeProyectoDocente->value)
                        <x-select label="Modalidad" name="headModalityId" wire:model.live="headModalityId" :options="$modalityOptions" placeholder="Elige la modalidad" />
                        @if ($campusOptions !== [])
                            <x-select label="Campus (opcional)" name="headCampusId" wire:model="headCampusId" :options="$campusOptions" placeholder="Todos los campus" />
                        @endif
                    @elseif ($headKind !== '')
                        <p class="self-end pb-2 text-sm text-neutral md:col-span-2">El jefe de departamento es de todo el Tec: no lleva campus.</p>
                    @endif
                    <x-input label="Desde" name="headFrom" wire:model="headFrom" type="date" />
                    <x-input label="Hasta (si tiene)" name="headTo" wire:model="headTo" type="date" hint="Vacío es sin fecha de fin." />
                </div>
                @unless ($record->user)
                    <p class="text-sm text-neutral">Todavía no tiene usuario: se le crea, sin acceso, antes de nombrarlo. Después dale acceso desde «Acciones».</p>
                @endunless

                <x-slot:actions>
                    <x-button label="Cancelar" @click="$wire.showHeadshipModal = false" />
                    <x-button label="Nombrar" type="submit" icon="o-check" class="btn-primary" spinner="saveHeadship" data-testid="save-headship" />
                </x-slot:actions>
            </x-form>
        </x-modal>
    @endif

    @if ($canEndHeadships)
        <x-modal wire:model="showEndHeadshipModal" title="Terminar jefatura" box-class="max-w-lg" separator>
            @if ($endingHeadship)
                <p>¿Terminar la jefatura de <b>{{ $endingHeadship->role->label() }}</b> de <b>{{ $record->teacher->name }}</b>? Deja de ejercerse después del último día. Si empieza hoy o después y el último día es anterior a su inicio, se quita.</p>
                <x-input label="Último día" name="endLastDay" wire:model="endLastDay" type="date" class="mt-3" />
            @endif

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showEndHeadshipModal = false" />
                <x-button label="Terminar jefatura" icon="o-stop-circle" class="btn-error" wire:click="endHeadship" spinner="endHeadship" data-testid="confirm-end-headship" />
            </x-slot:actions>
        </x-modal>
    @endif

    <x-modal wire:model="showDataModal" title="Editar datos" box-class="max-w-2xl" separator>
        <x-form wire:submit="saveData" no-separator>
            <div class="grid gap-4 md:grid-cols-6">
                <div class="md:col-span-2">
                    <x-input label="RFC" value="{{ $record->teacher->rfc }}" disabled hint="No se cambia: es su usuario." />
                </div>
                <div class="md:col-span-4">
                    <x-input label="Nombre" name="name" wire:model="name" />
                </div>
                <div class="md:col-span-3">
                    @include('faculty::teachers.institutional-email-field', ['reason' => $institutionalEmailReason, 'domain' => $institutionalDomain])
                </div>
                <div class="md:col-span-3">
                    <x-input label="Correo personal" name="email" wire:model="email" type="email" />
                </div>
                <div class="md:col-span-3">
                    <x-select label="Grado" name="degree" wire:model="degree" :options="$degrees" placeholder="Sin grado" />
                </div>
                <div class="md:col-span-3">
                    <x-input label="Fecha de ingreso" name="hired_on" wire:model="hired_on" type="date" />
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showDataModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="saveData" />
            </x-slot:actions>
        </x-form>
    </x-modal>

    <x-modal wire:model="showPositionModal" :title="$editingPositionId === null ? 'Agregar plaza' : 'Editar plaza'" :subtitle="$record->teacher->name" box-class="max-w-2xl" separator>
        <x-form wire:submit="savePosition" no-separator>
            <div class="grid gap-4 md:grid-cols-2">
                <x-select label="Tipo de plaza" name="kind" wire:model.live="kind" :options="$kinds" />
                <x-input
                    label="Horas base"
                    name="base_hours"
                    wire:model="base_hours"
                    type="number"
                    inputmode="numeric"
                    min="1"
                    max="40"
                    class="font-mono"
                    :readonly="\App\Modules\Faculty\Enums\PositionKind::tryFrom($kind)?->fixedHours() !== null"
                    :hint="\App\Modules\Faculty\Enums\PositionKind::tryFrom($kind)?->fixedHours() !== null ? 'Las fija el tipo de plaza.' : 'De 1 a 40.'"
                />
                <x-input label="Desde" name="valid_from" wire:model.live="valid_from" type="date" hint="Vacío es sin fecha de inicio." />
                <x-input label="Hasta (si tiene)" name="valid_to" wire:model.live="valid_to" type="date" hint="Vacío es sin fecha de fin." />
            </div>

            @if ($positionCounts !== false && $positionCounts !== null && $record->term)
                <p @class(['flex items-center gap-1.5 text-sm', 'text-success' => $positionCounts, 'text-neutral' => ! $positionCounts]) data-testid="position-counts">
                    <x-icon :name="$positionCounts ? 'o-check-circle' : 'o-minus-circle'" class="h-4 w-4" aria-hidden="true" />
                    {{ $positionCounts ? 'Cuenta en '.$record->term->name : 'No cuenta en '.$record->term->name }}
                </p>
            @endif

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showPositionModal = false" />
                <x-button label="Guardar plaza" type="submit" icon="o-check" class="btn-primary" spinner="savePosition" data-testid="save-position" />
            </x-slot:actions>
        </x-form>
    </x-modal>

    <x-modal wire:model="showRemoveAdscriptionModal" :title="$removing ? 'Quitar de '.$removing->department?->name : 'Quitar de un departamento'" box-class="max-w-lg" separator>
        @if ($removing)
            <p>{{ $removingWarning }}</p>
        @endif

        <x-slot:actions>
            <x-button label="Cancelar" @click="$wire.showRemoveAdscriptionModal = false" />
            <x-button label="Quitar" icon="o-trash" class="btn-error" wire:click="removeAdscription" spinner="removeAdscription" data-testid="confirm-remove-adscription" />
        </x-slot:actions>
    </x-modal>

    <x-modal wire:model="showRemovePositionModal" title="Quitar plaza" box-class="max-w-lg" separator>
        @if ($removingPosition)
            <p>¿Quitar la plaza de <b>{{ $removingPosition->kind->label() }}</b> de {{ $removingPosition->base_hours }} h de <b>{{ $record->teacher->name }}</b>? Dejan de contar sus horas base. Queda en la bitácora.</p>
        @endif

        <x-slot:actions>
            <x-button label="Cancelar" @click="$wire.showRemovePositionModal = false" />
            <x-button label="Quitar plaza" icon="o-trash" class="btn-error" wire:click="removePosition" spinner="removePosition" data-testid="confirm-remove-position" />
        </x-slot:actions>
    </x-modal>
    <x-modal wire:model="showQualificationModal" :title="$editingQualificationId === null ? 'Agregar materia al perfil' : 'Editar materia del perfil'" :subtitle="$record->teacher->name" box-class="max-w-2xl" separator>
        <x-form wire:submit="saveQualification" no-separator>
            <div class="grid gap-4">
                @if ($editingQualificationId === null)
                    <x-input label="Buscar materia" wire:model.live.debounce.300ms="qualSearch" placeholder="Clave o nombre" icon="o-magnifying-glass" hint="Solo las de los departamentos que administras." />
                    <fieldset class="max-h-56 overflow-y-auto rounded-box border border-base-300" data-testid="subject-options">
                        <legend class="sr-only">Materia</legend>
                        @forelse ($subjectOptions as $option)
                            <label wire:key="option-{{ $option->id }}" class="flex cursor-pointer items-start gap-3 border-b border-base-300 px-3 py-2 last:border-b-0 has-[:checked]:bg-base-200">
                                <input type="radio" class="radio radio-sm mt-0.5" wire:model="qualSubjectId" value="{{ $option->id }}" />
                                <span class="min-w-0"><span class="font-mono text-neutral">{{ $option->code }}</span> {{ $option->name }}<span class="block text-sm text-neutral">{{ $option->department?->name }}</span></span>
                            </label>
                        @empty
                            <p class="px-3 py-4 text-center text-neutral">Ninguna materia coincide.</p>
                        @endforelse
                    </fieldset>
                    @error('qualSubjectId')<p class="text-sm text-error">{{ $message }}</p>@enderror
                @endif
                <x-select label="Nivel" wire:model="qualLevel" :options="array_map(fn ($level) => ['id' => $level->value, 'name' => $level->label()], \App\Modules\Faculty\Enums\QualificationLevel::cases())" hint="Preferente pesa más que apto al sugerir docentes." />
                <x-textarea label="Notas (opcional)" wire:model="qualNotes" rows="2" />
            </div>

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showQualificationModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="saveQualification" data-testid="save-qualification" />
            </x-slot:actions>
        </x-form>
    </x-modal>

    <x-modal wire:model="showLoadModal" :title="$editingLoadId === null ? 'Agregar carga en otra modalidad' : 'Editar carga en otra modalidad'" :subtitle="$record->term?->name" box-class="max-w-xl" separator>
        <x-form wire:submit="saveLoad" no-separator>
            <div class="grid gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <x-input label="Origen" name="loadSource" wire:model="loadSource" placeholder="Por ejemplo, TecNM Virtual" />
                </div>
                <x-input label="Horas" name="loadHours" wire:model="loadHours" type="number" inputmode="numeric" min="1" max="40" class="font-mono" hint="De 1 a 40." />
                <div class="md:col-span-3">
                    <x-textarea label="Notas (opcional)" wire:model="loadNotes" rows="2" />
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showLoadModal = false" />
                <x-button label="Guardar horas" type="submit" icon="o-check" class="btn-primary" spinner="saveLoad" data-testid="save-load" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
