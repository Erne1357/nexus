{{--
    El cajón de un grupo de «Carga actual» (tarea 1.61, SEL-18; maqueta 1-carga-b, variante B: `x-drawer` a la derecha,
    hasta 576 px y nunca más ancho que la ventana). Encabezado con «CLAVE · grupo X · CARRERA · Sem. N», la materia y los
    choques duros vigentes (de solo lectura); dos pestañas:
    - «Sesiones y salones»: una tabla con día, horas, tipo y salón; por sesión «Cambiar» (día, inicio y fin con lo que
      responde el validador), «Salón» (la sección `scheduling::components.session-room` tal cual) y «Quitar»; «Agregar
      sesión». Sin permiso o con la carga que no lo acepta, de solo lectura con su motivo.
    - «Docente»: el docente actual con «Quitar», los primeros lugares del ranking con «Asignar» y «Buscar otro docente»
      (`staffing::components.teacher-search`). Fuera del primer lugar pide el motivo (HC-16).
    Todo sale de las Queries y Actions del componente; aquí solo se pinta.
--}}
<div>
    <x-drawer wire:model.live="show" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="load-drawer-title" data-testid="load-drawer">
        @if ($show && $group)
            <div class="flex min-h-dvh flex-col" data-testid="load-drawer-body">
                <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                    <div class="min-w-0 flex-1">
                        <h2 id="load-drawer-title" class="text-xl leading-tight font-bold">
                            <span class="font-mono">{{ $group->subject->code }}</span> · grupo {{ $group->code }}
                            <span class="text-base font-normal text-neutral">· {{ $group->ownerCareer?->code }} · Sem. {{ $group->semester }}@if ($group->campus) · {{ $group->campus->code }}@endif</span>
                        </h2>
                        <p class="mt-1 text-neutral">{{ $group->subject->name }}</p>
                    </div>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar" />
                </header>

                @if ($conflicts !== [])
                    <div role="alert" class="mx-5 mt-4 grid gap-1 rounded-field border border-error/40 bg-error/10 px-3 py-2 text-sm" data-testid="load-drawer-conflicts">
                        @foreach ($conflicts as $conflict)
                            <p class="flex items-start gap-2"><x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0 text-error" aria-hidden="true" /><span class="min-w-0 break-words"><b class="font-mono text-error">{{ $conflict['rule'] }}</b> · {{ $conflict['message'] }}</span></p>
                        @endforeach
                    </div>
                @endif

                <div role="tablist" class="mt-3 flex border-b border-base-300 px-4" aria-label="Secciones del grupo">
                    @foreach (['sesiones' => 'Sesiones y salones', 'docente' => 'Docente'] as $key => $label)
                        <button type="button" role="tab" wire:click="setTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" data-testid="tab-{{ $key }}"
                            @class(['-mb-px border-b-2 px-3.5 py-2.5 font-medium max-md:min-h-11', 'border-primary font-semibold text-base-content' => $tab === $key, 'border-transparent text-neutral' => $tab !== $key])>{{ $label }}</button>
                    @endforeach
                </div>

                <div class="flex-1 space-y-4 px-5 pt-4 pb-6">
                    @if ($tab === 'sesiones')
                        @unless ($canEdit)
                            <div role="note" class="alert alert-soft flex items-start gap-2 text-sm" data-testid="sessions-reason">
                                <x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ $editReason }}
                            </div>
                        @endunless
                        @error('session')
                            <div role="alert" class="alert alert-error alert-soft flex items-start gap-2 text-sm" data-testid="session-error">
                                <x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ $message }}
                            </div>
                        @enderror

                        @if ($rows === [])
                            <p class="rounded-box border border-dashed border-base-300 px-3 py-4 text-center text-sm text-neutral" data-testid="sessions-empty">El grupo no tiene sesiones.</p>
                        @endif

                        <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300" data-testid="sessions-list">
                            @foreach ($rows as $row)
                                @php($panel = $panels[$row['id']] ?? null)
                                <li class="grid gap-2 px-3 py-2.5" data-session="{{ $row['id'] }}" wire:key="session-{{ $row['id'] }}">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                        <b class="w-24">{{ $row['day'] }}</b>
                                        <span class="font-mono">{{ $row['range'] }}</span>
                                        <span class="text-sm text-neutral">{{ $row['kind'] }}</span>
                                        @if ($row['room'])
                                            <span class="font-mono text-sm">{{ $row['room'] }}</span>
                                        @elseif (! $row['virtual'])
                                            <span class="rounded border border-dashed border-warning px-1.5 py-0.5 text-xs">Sin salón</span>
                                        @endif
                                        <span class="ms-auto flex gap-1">
                                            @if ($canEdit)
                                                <x-button label="Cambiar" class="btn-ghost btn-sm max-md:h-11" wire:click="edit('{{ $row['id'] }}')" data-testid="edit-session" />
                                            @endif
                                            @unless ($row['virtual'])
                                                <x-button label="Salón…" class="btn-ghost btn-sm max-md:h-11" wire:click="toggleRoom('{{ $row['id'] }}')" data-testid="room-session" />
                                            @endunless
                                            @if ($canEdit)
                                                <x-button icon="o-trash" class="btn-ghost btn-sm text-error max-md:h-11" wire:click="removeSession('{{ $row['id'] }}')" aria-label="Quitar la sesión del {{ $row['day'] }}" data-testid="remove-session"
                                                    x-confirm="Se quita completa, con su salón." data-confirm-title="¿Quitar la sesión del {{ $row['day'] }} {{ $row['range'] }}?" data-confirm-button="Quitar" data-confirm-tone="error" />
                                            @endif
                                        </span>
                                    </div>
                                    @if ($panel)
                                        @foreach ([...$panel['hard'], ...$panel['soft']] as $violation)
                                            <p class="flex items-start gap-2 text-sm"><x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-warning" aria-hidden="true" /><span class="min-w-0 break-words"><b class="font-mono">{{ $violation['rule'] }}</b> · {{ $violation['message'] }}</span></p>
                                        @endforeach
                                    @endif

                                    @if ($editing === $row['id'])
                                        @include('staffing::current-load.time-form', ['submit' => 'saveSession', 'label' => 'Guardar horario'])
                                    @endif
                                    @if ($roomFor === $row['id'])
                                        <div class="rounded-box border border-base-300 bg-base-100 p-3" data-testid="room-panel">
                                            <livewire:scheduling::components.session-room :session-id="$row['id']" wire:key="room-{{ $row['id'] }}-{{ $row['updated_at'] }}" />
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        @if ($canEdit)
                            @if ($adding)
                                <div class="rounded-box border border-base-300 p-3">
                                    @include('staffing::current-load.time-form', ['submit' => 'addSession', 'label' => 'Agregar'])
                                </div>
                            @else
                                <x-button label="Agregar sesión" icon="o-plus" class="btn-ghost btn-sm max-md:h-11" wire:click="startAdding" data-testid="add-session" />
                            @endif
                        @endif
                    @else
                        <section class="grid gap-2" aria-label="Docente actual">
                            <h3 class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Docente actual</h3>
                            @unless ($canAssign)
                                <div role="note" class="alert alert-soft flex items-start gap-2 text-sm" data-testid="teacher-reason">
                                    <x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ $assignReason }}
                                </div>
                            @endunless
                            <div class="flex flex-wrap items-center gap-3 rounded-box border border-base-300 bg-base-200 px-3 py-2.5" data-testid="current-teacher">
                                @if ($group->teacher)
                                    <span class="font-semibold">{{ $group->teacher->name }}</span>
                                    @if ($canAssign)
                                        <x-button label="Quitar" icon="o-x-mark" class="btn-ghost btn-sm ms-auto" wire:click="askUnassign" data-testid="unassign" />
                                    @endif
                                @else
                                    <span class="text-neutral">Sin docente</span>
                                @endif
                            </div>
                            @if ($unassigning)
                                <div class="grid gap-2 rounded-box border border-base-300 p-3" data-testid="unassign-form">
                                    <x-textarea label="Motivo para quitar al docente" wire:model="unassignReason" rows="2" />
                                    @error('unassign') <p class="text-sm text-error" role="alert">{{ $message }}</p> @enderror
                                    <div class="flex gap-2"><x-button label="Quitar docente" class="btn-error btn-sm" wire:click="unassign" spinner="unassign" /><x-button label="Cancelar" class="btn-ghost btn-sm" wire:click="cancelUnassign" /></div>
                                </div>
                            @endif
                        </section>

                        @if ($pending)
                            <div class="grid gap-2 rounded-box border border-warning/50 bg-warning/10 p-3" data-testid="reason-form">
                                <p class="text-sm"><b>{{ $pending['name'] }}</b> {{ $pending['position'] === null ? 'no está en el ranking' : "ocupa el lugar {$pending['position']} de {$pending['size']}" }}. Escribe por qué no eliges al primer lugar (HC-16).</p>
                                <x-textarea label="Motivo" wire:model="reason" rows="2" data-testid="reason-input" />
                                @error('assignment') <p class="text-sm text-error" role="alert">{{ $message }}</p> @enderror
                                <div class="flex gap-2"><x-button label="Asignar con motivo" class="btn-primary btn-sm" wire:click="confirmAssign" spinner="confirmAssign" data-testid="confirm-assign" /><x-button label="Cancelar" class="btn-ghost btn-sm" wire:click="cancelReason" /></div>
                            </div>
                        @endif

                        @if ($canRank)
                            <section class="grid gap-2" aria-label="Ranking del grupo">
                                <h3 class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Ranking del grupo</h3>
                                @if ($top === [])
                                    <p class="rounded-box border border-dashed border-base-300 px-3 py-4 text-center text-sm text-neutral" data-testid="ranking-empty">Sin ranking: busca a un docente abajo.</p>
                                @else
                                    <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300" data-testid="ranking-list">
                                        @foreach ($top as $row)
                                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2" wire:key="rank-{{ $row->teacherId }}" data-teacher="{{ $row->teacherId }}">
                                                <span class="w-6 font-mono tabular-nums text-neutral">{{ $row->rank }}</span>
                                                <span class="min-w-0 flex-1"><span class="font-medium">{{ $row->teacherName }}</span> <span class="text-sm text-neutral">{{ $row->positions }}</span></span>
                                                <span class="font-mono text-sm tabular-nums">{{ number_format($row->score, 1) }}</span>
                                                <x-button label="Asignar" class="btn-primary btn-sm max-md:h-11" wire:click="assign('{{ $row->teacherId }}')" :disabled="! $canAssign" data-testid="assign-ranked" />
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </section>
                        @endif

                        @if ($canAssign)
                            <livewire:staffing::components.teacher-search :group-id="$group->id" :assignable="$canAssign" wire:key="search-{{ $group->id }}" />
                        @endif
                    @endif
                </div>
            </div>
        @endif
    </x-drawer>
</div>
