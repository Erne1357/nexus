@php
    $spaces = ['aula' => 'Aula', 'sala_computo' => 'Sala', 'laboratorio' => 'Lab.'];
    $roomTypes = ['aula' => 'Aula', 'sala_computo' => 'Sala', 'laboratorio' => 'Lab.'];
    $stepNames = [1 => 'Alcance', 2 => 'CSV', 3 => 'Tipo de espacio de las materias', 4 => 'Tipo de los salones', 5 => 'Horario de turnos', 6 => 'Vista previa del orden', 7 => 'Publicar'];
    $days = [1 => 'L', 2 => 'M', 3 => 'Mi', 4 => 'J', 5 => 'V', 6 => 'S'];
@endphp

<div>
    <x-header :title="'Orden de selección'" separator progress-indicator use-h1>
        <x-slot:subtitle>
            @if ($selection)
                <span class="flex flex-wrap items-center gap-x-2">
                    <span class="font-mono font-semibold text-base-content">{{ $selection->department->code }}</span>
                    <span>{{ $selection->department->name }}</span><span aria-hidden="true">·</span>
                    <span>{{ $selection->modality->name }}</span><span aria-hidden="true">·</span>
                    <span>{{ $selection->term->name }}</span>
                </span>
            @else
                Reparte la carga de un departamento por turnos, en el orden de su CSV de criterios.
            @endif
        </x-slot:subtitle>
        <x-slot:actions>
            @if ($selection)
                <x-badge :value="$selection->status->label()" class="badge-outline" data-testid="selection-status" />
            @endif
        </x-slot:actions>
    </x-header>

    @if ($errors->has('publish'))
        <x-alert :title="$errors->first('publish')" icon="o-exclamation-triangle" class="alert-warning mb-4" data-testid="publish-error" />
    @endif

    @if ($selection)
        <div role="tablist" class="tabs tabs-border mb-4">
            <button type="button" role="tab" wire:click="setTab('preparar')" @class(['tab', 'tab-active' => $tab === 'preparar'])>Preparar</button>
            <button type="button" role="tab" wire:click="setTab('monitor')" @class(['tab', 'tab-active' => $tab === 'monitor'])>Monitor</button>
        </div>
    @endif

    @if (! $selection || $tab === 'preparar')
        @if ($selection && ! $draft)
            <x-alert title="Esta selección ya no es un borrador." description="Sigue su avance en el monitor." icon="o-information-circle" class="alert-info" />
        @else
            <x-steps wire:model="step" steps-color="step-primary" stepper-classes="w-full mb-6">
                @foreach ($stepNames as $n => $name)
                    <x-step :step="$n" :text="$name" />
                @endforeach
            </x-steps>

            <x-card shadow>
                {{-- 1. Alcance --}}
                @if ($step === 1)
                    <h2 class="text-xl font-bold"><span class="font-mono text-neutral">1.</span> Alcance</h2>
                    <p class="mb-4 text-sm text-neutral">Qué carga se reparte y con qué docentes.</p>
                    <div class="grid gap-4 md:grid-cols-3">
                        <x-select label="Periodo" wire:model="termId" :options="$terms" option-label="name" :disabled="$selection !== null" />
                        <x-select label="Modalidad" wire:model="modalityId" :options="$modalities" :disabled="$selection !== null" />
                        <x-select label="Departamento" wire:model="departmentId" :options="$departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->code.' · '.$d->name])" placeholder="Elige el departamento" :disabled="$selection !== null" />
                    </div>

                    @if ($selection)
                        <h3 class="mt-6 font-semibold">Cargas que entran</h3>
                        <ul class="mt-2 divide-y divide-base-300 rounded-box border border-base-300" data-testid="selection-loads">
                            @foreach ($loads as $load)
                                <li class="flex items-center justify-between gap-3 px-4 py-2">
                                    <span><b>{{ $load->department->code }}</b> <span class="text-neutral">· {{ $load->department->name }} · {{ $load->status->label() }}</span></span>
                                    <x-button label="Quitar" icon="o-x-mark" class="btn-ghost btn-sm" wire:click="removeLoad('{{ $load->id }}')" spinner />
                                </li>
                            @endforeach
                        </ul>
                        <dl class="mt-4 grid grid-cols-3 overflow-hidden rounded-box border border-base-300 text-center">
                            <div class="p-3"><dt class="text-xs text-neutral">Grupos</dt><dd class="text-xl font-bold">{{ $facts['groups'] }}</dd></div>
                            <div class="p-3"><dt class="text-xs text-neutral">Horas a la semana</dt><dd class="text-xl font-bold">{{ $facts['hours'] }}</dd></div>
                            <div class="p-3"><dt class="text-xs text-neutral">Docentes del departamento</dt><dd class="text-xl font-bold">{{ $facts['teachers'] }}</dd></div>
                        </dl>
                    @endif
                @endif

                {{-- 2. CSV --}}
                @if ($step === 2)
                    <h2 class="text-xl font-bold"><span class="font-mono text-neutral">2.</span> CSV</h2>
                    <p class="mb-4 text-sm text-neutral">Los criterios de cada docente. Cada fila se liga a un docente de Nexus por su nombre.</p>
                    <form wire:submit="importCsv" class="flex flex-wrap items-end gap-3">
                        <div class="grow md:max-w-md"><x-file label="Archivo" wire:model="csvFile" accept=".csv,.txt" hint="Punto y coma; la primera fila es el encabezado." /></div>
                        <x-button label="{{ $csvRows->isEmpty() ? 'Importar' : 'Cambiar archivo' }}" type="submit" icon="o-arrow-up-tray" class="btn-primary" spinner="importCsv" />
                    </form>
                    <p class="mt-2 font-mono text-xs text-neutral">Nombre completo; horas; auto ev; cumpl asig; C1; C2; antigüedad</p>
                    <p class="text-xs text-neutral">Horas 40 = TC, 30 = ¾, 20 = ½ y otra cifra = Asignatura. El archivo no se guarda.</p>

                    @foreach ($csvErrors as $error)
                        <x-alert :title="'Fila '.$error['line'].': '.$error['message']" icon="o-exclamation-triangle" class="alert-warning mt-2" />
                    @endforeach

                    @if ($csvRows->isNotEmpty())
                        @php
                            $pending = $csvRows->whereNull('teacher_id');
                            $visible = match ($csvFilter) { 'pendientes' => $pending, 'resueltas' => $csvRows->whereNotNull('teacher_id'), default => $csvRows };
                        @endphp
                        <div class="join mt-4" role="group" aria-label="Filas">
                            <button type="button" wire:click="$set('csvFilter', 'todas')" @class(['btn btn-sm join-item', 'btn-neutral' => $csvFilter === 'todas'])>Todas {{ $csvRows->count() }}</button>
                            <button type="button" wire:click="$set('csvFilter', 'pendientes')" @class(['btn btn-sm join-item', 'btn-neutral' => $csvFilter === 'pendientes'])>Por resolver {{ $pending->count() }}</button>
                            <button type="button" wire:click="$set('csvFilter', 'resueltas')" @class(['btn btn-sm join-item', 'btn-neutral' => $csvFilter === 'resueltas'])>Resueltas {{ $csvRows->count() - $pending->count() }}</button>
                        </div>
                        <div class="mt-3 overflow-x-auto rounded-box border border-base-300">
                            <table class="table table-sm">
                                <thead><tr><th>Fila</th><th>En el CSV</th><th>Docente en Nexus</th><th class="text-right">Horas</th><th>Autoev.</th><th>Cumpl.</th><th>Parc. 1</th><th>Parc. 2</th><th class="text-right">Antig.</th></tr></thead>
                                <tbody>
                                    @foreach ($visible as $row)
                                        <tr wire:key="csv-{{ $row->id }}" @class(['bg-warning/10' => $row->teacher_id === null])>
                                            <td class="font-mono">{{ $row->csv_line }}</td>
                                            <td class="font-mono text-xs">{{ $row->csv_name }}</td>
                                            <td>
                                                @if ($row->teacher_id === null)
                                                    @if (($candidates[$row->id] ?? '') !== '')
                                                        <span class="text-xs font-semibold text-warning" data-testid="row-similar">Parecido: confirma</span>
                                                    @else
                                                        <span class="text-xs font-semibold text-error" data-testid="row-none">Sin coincidencia</span>
                                                    @endif
                                                    <div class="mt-1 flex items-center gap-2">
                                                        <x-select wire:model.live="picks.{{ $row->id }}" :options="$poolTeachers" placeholder="Elige al docente…" class="select-sm" aria-label="Docente para la fila {{ $row->csv_line }}" />
                                                        <x-button label="Descartar" class="btn-ghost btn-xs" wire:click="resolve('{{ $row->id }}', null)" />
                                                    </div>
                                                @else
                                                    <span class="text-xs font-semibold text-success">{{ ($confirmed[$row->id] ?? false) ? 'Confirmado' : 'Coincide' }}</span>
                                                    <span class="ml-1">{{ $row->teacher->name }}</span>
                                                @endif
                                            </td>
                                            <td class="text-right font-mono">{{ $row->base_hours }} → {{ [0 => 'TC', 1 => '¾', 2 => '½'][$row->plaza_tier] ?? 'Asignatura' }}</td>
                                            @foreach (['auto_eval', 'prior_compliance', 'partials_1', 'partials_2'] as $criterion)
                                                <td><x-icon :name="$row->{$criterion} ? 'o-check-circle' : 'o-x-circle'" @class(['h-4 w-4', 'text-success' => $row->{$criterion}, 'text-error' => ! $row->{$criterion}]) /></td>
                                            @endforeach
                                            <td class="text-right font-mono">{{ $row->seniority_years }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($appended->isNotEmpty())
                            <div class="mt-4 rounded-box border border-dashed border-base-300 p-3">
                                <p class="text-xs font-semibold uppercase text-neutral">De Sistemas y no vienen en el CSV · {{ $appended->count() }}</p>
                                <p class="text-xs text-neutral">Van al final del orden, por su antigüedad en Nexus.</p>
                                <p class="mt-1 flex flex-wrap gap-x-4 font-semibold">@foreach ($appended as $row)<span>{{ $row->teacher->name }}</span>@endforeach</p>
                            </div>
                        @endif
                    @endif
                @endif

                {{-- 3. Tipo de espacio --}}
                @if ($step === 3)
                    <h2 class="text-xl font-bold"><span class="font-mono text-neutral">3.</span> Tipo de espacio de las materias</h2>
                    <p class="mb-4 text-sm text-neutral">Qué salón puede elegir el docente depende del tipo de la materia. Confirma cada una; queda para los periodos siguientes.</p>
                    <div class="overflow-x-auto rounded-box border border-base-300">
                        <table class="table table-sm">
                            <thead><tr><th>Clave</th><th>Materia</th><th class="text-right">Grupos</th><th>Espacio</th></tr></thead>
                            <tbody>
                                @foreach ($subjects as $subject)
                                    <tr wire:key="subj-{{ $subject->id }}">
                                        <td class="font-mono font-semibold">{{ $subject->code }}</td>
                                        <td>{{ $subject->name }}</td>
                                        <td class="text-right font-mono">{{ $groupCounts[$subject->id] ?? 0 }}</td>
                                        <td>
                                            <div class="join" role="group" aria-label="Tipo de espacio de {{ $subject->code }}">
                                                @foreach ($spaces as $value => $label)
                                                    <button type="button" wire:click="setSpace('{{ $subject->id }}', '{{ $value }}')" aria-pressed="{{ $subject->required_space?->value === $value ? 'true' : 'false' }}" @class(['btn btn-sm join-item', 'btn-neutral' => $subject->required_space?->value === $value])>{{ $label === 'Sala' ? 'Sala' : ($label === 'Lab.' ? 'Lab.' : 'Aula') }}</button>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- 4. Tipo de los salones --}}
                @if ($step === 4)
                    <h2 class="text-xl font-bold"><span class="font-mono text-neutral">4.</span> Tipo de los salones</h2>
                    <p class="mb-4 text-sm text-neutral">Los salones que se ofrecen: los del departamento y los sin dueño del campus de los grupos.</p>
                    <ul class="mb-4 list-disc pl-5 text-sm text-neutral">
                        <li>Le cabe el grupo y le sobran 10 lugares o menos; si ninguno queda, se ofrece el más chico libre que le cabe.</li>
                        <li>Los laboratorios son solo para materias de laboratorio.</li>
                        <li>Las salas se apartan para las materias de sala.</li>
                    </ul>
                    @foreach (['Del departamento' => $ownRooms, 'Sin dueño' => $freeRooms] as $title => $rooms)
                        <h3 class="mt-4 font-semibold">{{ $title }} <span class="font-mono text-neutral">{{ $rooms->count() }}</span></h3>
                        <div class="mt-2 overflow-x-auto rounded-box border border-base-300">
                            <table class="table table-sm">
                                <thead><tr><th>Salón</th><th>Edificio</th><th class="text-right">Lugares</th><th>Tipo</th></tr></thead>
                                <tbody>
                                    @forelse ($rooms as $room)
                                        <tr wire:key="room-{{ $room->id }}">
                                            <td class="font-mono font-semibold">{{ $room->code }}</td>
                                            <td>{{ $room->building?->code }}</td>
                                            <td class="text-right font-mono">{{ $room->capacity }}</td>
                                            <td>
                                                <div class="join" role="group" aria-label="Tipo del salón {{ $room->code }}">
                                                    @foreach ($roomTypes as $value => $label)
                                                        <button type="button" wire:click="setRoomType('{{ $room->id }}', '{{ $value }}')" aria-pressed="{{ $room->type->value === $value ? 'true' : 'false' }}" @class(['btn btn-sm join-item', 'btn-neutral' => $room->type->value === $value])>{{ $label }}</button>
                                                    @endforeach
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-neutral">Sin salones.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                @endif

                {{-- 5. Horario de turnos --}}
                @if ($step === 5)
                    <h2 class="text-xl font-bold"><span class="font-mono text-neutral">5.</span> Horario de turnos</h2>
                    <p class="mb-4 text-sm text-neutral">Cuándo elige cada quien. Los turnos duran {{ $selection->slot_minutes ?? 30 }} minutos y no se recorren si alguien termina antes.</p>
                    <div class="grid gap-6 lg:grid-cols-[minmax(0,22rem)_1fr]">
                        <div class="space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <x-input label="Empieza" type="date" wire:model.live.debounce.500ms="startsOn" />
                                <x-input label="A las" type="time" wire:model.live.debounce.500ms="startsAt" />
                                <x-input label="Cada día, desde" type="time" wire:model.live.debounce.500ms="windowStarts" />
                                <x-input label="Hasta" type="time" wire:model.live.debounce.500ms="windowEnds" />
                            </div>
                            @error('weekdays')<p class="text-sm text-error">{{ $message }}</p>@enderror
                            @error('window_starts')<p class="text-sm text-error">{{ $message }}</p>@enderror
                            <div>
                                <span class="label-text mb-1 block text-sm font-semibold">Días</span>
                                <div class="join" role="group" aria-label="Días">
                                    @foreach ($days as $n => $label)
                                        <button type="button" wire:click="toggleDay({{ $n }})" aria-pressed="{{ in_array($n, $weekdays, true) ? 'true' : 'false' }}" @class(['btn btn-sm join-item', 'btn-neutral' => in_array($n, $weekdays, true)])>{{ $label }}</button>
                                    @endforeach
                                </div>
                            </div>
                            @if ($slotPreview)
                                <x-alert icon="o-calendar-days" class="alert-info" data-testid="slot-summary">
                                    <span><b>{{ $slotPreview['turns'] }} turnos para {{ $slotPreview['people'] }} docentes</b> ({{ $slotPreview['ties'] }} {{ $slotPreview['ties'] === 1 ? 'empate' : 'empates' }}). El último es el {{ $slotPreview['last'] }}@if ($slotPreview['extra']); la ronda extra empieza el {{ $slotPreview['extra'] }}@endif.</span>
                                </x-alert>
                            @endif
                        </div>
                        @if ($slotPreview)
                            <div class="overflow-x-auto rounded-box border border-base-300">
                                <table class="table table-sm text-center">
                                    <thead><tr><th></th>@foreach ($slotPreview['days'] as $day)<th>{{ $day['label'] }}</th>@endforeach</tr></thead>
                                    <tbody>
                                        @foreach ($slotPreview['times'] as $time)
                                            <tr>
                                                <th class="font-mono">{{ $time }}</th>
                                                @foreach ($slotPreview['days'] as $day)
                                                    @php
                                                        $positions = $slotPreview['cells'][$day['key'].' '.$time] ?? [];
                                                    @endphp
                                                    <td class="font-mono {{ $positions ? 'bg-info/10 font-semibold' : '' }}">{{ implode(' · ', $positions) }}@if (count($positions) > 1) <span class="font-sans text-xs text-info">empate</span>@endif</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                <p class="p-2 text-xs text-neutral">Cada número es un lugar del orden.</p>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- 6. Vista previa del orden --}}
                @if ($step === 6)
                    <h2 class="text-xl font-bold"><span class="font-mono text-neutral">6.</span> Vista previa del orden</h2>
                    <p class="mb-4 text-sm text-neutral">Así queda el orden con el CSV y la antigüedad. Revísalo antes de publicar.</p>
                    @php
                        $priorEntries = $entries->whereNotNull('prior_choice_at');
                    @endphp
                    @if ($priorEntries->isNotEmpty())
                        <h3 class="mt-4 text-xs font-semibold uppercase text-neutral">Elección previa · {{ $priorEntries->count() }}</h3>
                        <div class="mt-1 overflow-x-auto rounded-box border border-base-300" data-testid="preview-prior">
                            <table class="table table-sm">
                                <tbody>
                                    @foreach ($priorEntries as $entry)
                                        <tr wire:key="prior-{{ $entry->id }}">
                                            <td class="w-10 font-mono">—</td>
                                            <td class="font-semibold">{{ $entry->teacher?->name ?? $entry->csv_name }}</td>
                                            <td class="text-xs text-neutral">Ya trae sus grupos escritos en la relación de materias</td>
                                            <td class="text-right font-mono text-xs">{{ ($priorHours[$entry->teacher_id] ?? 0) }} h · sin turno</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @php
                        $queue = $entries->whereNull('prior_choice_at');
                        $withPost = $queue->filter(fn ($entry) => $entry->administrative_post !== null);
                    @endphp
                    @if ($withPost->isNotEmpty())
                        <h3 class="mt-4 text-xs font-semibold uppercase text-neutral">Puesto administrativo · {{ $withPost->count() }}</h3>
                        <p class="text-xs text-neutral">Eligen primero (SEL-07); dentro de este grupo, el orden de siempre.</p>
                        <div class="mt-1 overflow-x-auto rounded-box border border-base-300" data-testid="preview-admin">
                            <table class="table table-sm">
                                <tbody>
                                    @foreach ($withPost as $entry)
                                        <tr wire:key="admin-{{ $entry->id }}">
                                            <td class="w-10 font-mono">{{ $entry->position }}</td>
                                            <td class="font-semibold">{{ $entry->teacher?->name ?? $entry->csv_name }} <x-core::status-badge tone="info" label="Puesto administrativo" class="badge-sm" /></td>
                                            <td class="text-xs text-neutral">{{ $entry->administrative_post }}@if ($entry->source === \App\Modules\Staffing\Enums\SelectionEntrySource::Csv) · {{ [0 => 'TC', 1 => '¾', 2 => '½'][$entry->plaza_tier] ?? 'Asignatura' }}@if ($entry->failed_criteria > 0) · {{ $entry->failed_criteria }} {{ $entry->failed_criteria === 1 ? 'criterio fallido' : 'criterios fallidos' }}@endif @endif</td>
                                            <td class="text-right font-mono text-xs">{{ $entry->slot_starts_at?->locale('es')->isoFormat('ddd D, H:mm') ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @foreach ($queue->reject(fn ($entry) => $entry->administrative_post !== null)->groupBy('tier') as $tier => $group)
                        <h3 class="mt-4 text-xs font-semibold uppercase text-neutral">Escalón {{ $tier + 1 }} · {{ $group->count() }}</h3>
                        <div class="mt-1 overflow-x-auto rounded-box border border-base-300">
                            <table class="table table-sm">
                                <tbody>
                                    @foreach ($group as $entry)
                                        <tr wire:key="order-{{ $entry->id }}">
                                            <td class="w-10 font-mono">{{ $entry->position }}</td>
                                            <td class="font-semibold">{{ $entry->teacher?->name ?? $entry->csv_name }}</td>
                                            <td class="text-xs text-neutral">{{ [0 => 'TC', 1 => '¾', 2 => '½'][$entry->plaza_tier] ?? 'Asignatura' }}@if ($entry->failed_criteria > 0) · {{ $entry->failed_criteria }} {{ $entry->failed_criteria === 1 ? 'criterio fallido' : 'criterios fallidos' }}@endif</td>
                                            <td class="text-right font-mono text-xs">{{ $entry->slot_starts_at?->locale('es')->isoFormat('ddd D, H:mm') ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                @endif

                {{-- 7. Publicar --}}
                @if ($step === 7)
                    <h2 class="text-xl font-bold"><span class="font-mono text-neutral">7.</span> Publicar</h2>
                    <p class="mb-4 text-sm text-neutral">Revisa y publica. Desde que lo publicas, los docentes ven el orden y su turno.</p>
                    @php
                        $checks = [
                            ['Alcance', $facts['groups'].' grupos, '.$facts['hours'].' h', $loads->isNotEmpty()],
                            ['CSV', $csvRows->count().' filas'.($unresolved > 0 ? ", {$unresolved} sin docente" : ', todas ligadas a un docente').'; '.$appended->count().' al final', $csvRows->isNotEmpty() && $unresolved === 0],
                            ['Tipo de espacio', $subjects->count().' materias'.($subjects->whereNull('required_space')->count() > 0 ? ', '.$subjects->whereNull('required_space')->count().' sin revisar' : ''), $subjects->whereNull('required_space')->isEmpty()],
                            ['Salones', $ownRooms->count().' del departamento y '.$freeRooms->count().' sin dueño', true],
                            ['Horario de turnos', $selection->starts_at ? $selection->starts_at->locale('es')->isoFormat('dddd D [de] MMMM, H:mm') : 'sin fecha de inicio', $selection->starts_at?->isFuture() ?? false],
                        ];
                    @endphp
                    <ul class="space-y-2" data-testid="publish-checklist">
                        @foreach ($checks as [$title, $detail, $ok])
                            <li class="flex items-start gap-2">
                                <x-icon :name="$ok ? 'o-check-circle' : 'o-exclamation-circle'" @class(['mt-0.5 h-5 w-5', 'text-success' => $ok, 'text-warning' => ! $ok]) />
                                <span><b>{{ $title }}</b> <span class="text-neutral">· {{ $detail }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                    <x-alert title="Al publicar" description="Los docentes ven el orden completo y su turno en «Oferta», y les llega el aviso a la campana. Los turnos quedan fijos. La jefatura puede asignar a mano en cualquier momento." icon="o-information-circle" class="alert-info mt-4" />
                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-button label="Publicar el orden" icon="o-paper-airplane" class="btn-primary" wire:click="$set('showPublish', true)" />
                        <x-button label="Cancelar la selección" class="btn-ghost" wire:click="$set('showCancel', true)" />
                    </div>
                @endif

                <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-base-300 pt-4">
                    @if ($step > 1)
                        <x-button label="Anterior" icon="o-arrow-left" class="btn-ghost" wire:click="goTo({{ $step - 1 }})" />
                    @else
                        <span></span>
                    @endif
                    @if ($step < 7)
                        <div class="flex flex-wrap items-center gap-3">
                            @foreach ($blockers as $why)
                                <p class="text-sm font-semibold text-warning" data-testid="step-blocker"><x-icon name="o-clock" class="h-4 w-4" /> {{ $why }}</p>
                            @endforeach
                            @if (! $selection)
                                <x-button label="Crear borrador" icon-right="o-arrow-right" class="btn-primary" wire:click="start" spinner="start" />
                            @else
                                <x-button :label="'Siguiente: '.mb_strtolower($stepNames[$step + 1])" icon-right="o-arrow-right" class="btn-primary" wire:click="next" :disabled="$blockers !== []" />
                            @endif
                        </div>
                    @endif
                </div>
            </x-card>

            <x-modal wire:model="showPublish" title="¿Publicar el orden?" box-class="max-w-lg">
                <p>Los docentes verán su turno y las cargas pasan a asignación. Los turnos quedan fijos.</p>
                <x-slot:actions>
                    <x-button label="Volver" wire:click="$set('showPublish', false)" />
                    <x-button label="Publicar" class="btn-primary" wire:click="publish" spinner="publish" />
                </x-slot:actions>
            </x-modal>
        @endif
    @endif

    {{-- Monitor --}}
    @if ($selection && $tab === 'monitor')
        @if ($monitor === null)
            <x-alert title="Todavía no hay nada que seguir." description="El monitor aparece al publicar el orden." icon="o-information-circle" class="alert-info" />
        @else
            <div wire:poll.10s data-testid="selection-monitor">
                <section class="grid grid-cols-2 overflow-hidden rounded-box border border-base-300 bg-base-100 lg:grid-cols-4">
                    <div class="p-4"><p class="text-xs text-neutral">Turno</p><p class="text-2xl font-bold">{{ $monitor['current']?->position ?? '—' }}<small class="text-sm font-normal text-neutral"> de {{ $monitor['total'] }}</small></p></div>
                    <div class="p-4"><p class="text-xs text-neutral">Terminaron</p><p class="text-2xl font-bold">{{ $monitor['done'] }}<small class="text-sm font-normal text-neutral"> de {{ $monitor['total'] }}</small></p></div>
                    <div class="p-4"><p class="text-xs text-neutral">Grupos con docente</p><p class="text-2xl font-bold">{{ $monitor['groups_taken'] }}<small class="text-sm font-normal text-neutral"> de {{ $monitor['groups_total'] }}</small></p><p class="text-xs text-neutral">{{ $monitor['hours_taken'] }} de {{ $monitor['hours_total'] }} h</p></div>
                    <div class="p-4"><p class="text-xs text-neutral">Esperando</p><p class="text-2xl font-bold">{{ $monitor['waiting'] }}</p></div>
                </section>

                @if ($cancellable)
                    <section class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-box border border-base-300 bg-base-100 p-4" data-testid="monitor-cancel">
                        <p class="text-sm">
                            <b>Empieza el {{ $selection->starts_at->locale('es')->isoFormat('dddd D [de] MMMM [a las] H:mm') }}.</b>
                            <span class="text-neutral">Hasta entonces puedes cancelarla; después ya no.</span>
                        </p>
                        <x-button label="Cancelar la selección" icon="o-x-circle" class="btn-ghost text-error" wire:click="$set('showCancel', true)" />
                    </section>
                @endif

                @if ($monitor['current'])
                    <section class="mt-4 rounded-box border border-info/40 bg-info/10 p-4" data-testid="monitor-current">
                        <p class="text-xs font-semibold uppercase text-neutral">Ahora</p>
                        <p class="text-lg font-bold">{{ $monitor['current_name'] }}</p>
                        <p class="text-sm text-neutral">Lugar {{ $monitor['current']->position }} · turno hasta las <span class="font-mono">{{ ($monitor['current']->slot_ends_at ?? $monitor['current']->extra_slot_ends_at)?->format('H:i') }}</span></p>
                    </section>
                @endif

                @php
                    $accessLabels = ['access' => 'Con acceso', 'invited' => 'Invitado', 'pending' => 'Pendiente', 'no_email' => 'Sin correo'];
                @endphp
                <x-card title="Acceso a Nexus" shadow class="mt-4" data-testid="monitor-access">
                    <p class="text-sm text-neutral">Al publicar se invita a quien no tiene acceso: a su correo institucional si su departamento validó el catálogo de docentes y, si no, al personal.</p>
                    <dl class="mt-3 grid grid-cols-2 overflow-hidden rounded-box border border-base-300 text-center sm:grid-cols-4">
                        @foreach ($accessLabels as $state => $label)
                            <div class="p-3"><dt class="text-xs text-neutral">{{ $label }}</dt><dd @class(['text-xl font-bold', 'text-warning' => in_array($state, ['pending', 'no_email'], true) && $monitor['access']['counts'][$state] > 0])>{{ $monitor['access']['counts'][$state] }}</dd></div>
                        @endforeach
                    </dl>
                    @if ($monitor['access']['missing'] !== [])
                        <ul class="mt-3 divide-y divide-base-300 rounded-box border border-base-300">
                            @foreach ($monitor['access']['missing'] as $row)
                                <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-2" wire:key="access-{{ $loop->index }}">
                                    <b>{{ $row['name'] }}</b>
                                    <span class="flex flex-wrap items-center gap-x-3 text-sm text-neutral">
                                        {{ $row['state'] === 'no_email' ? $row['reason'] : 'Sin acceso: '.mb_lcfirst($row['reason']) }}
                                        @if ($row['resendable'])
                                            <x-button label="Reenviar invitación" icon="o-paper-airplane" class="btn-ghost btn-sm" wire:click="resendInvitation('{{ $row['teacher_id'] }}')" spinner="resendInvitation('{{ $row['teacher_id'] }}')" data-testid="monitor-resend-{{ $row['teacher_id'] }}" />
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>

                @if ($monitor['prior'] !== [])
                    <x-card title="Elección previa" shadow class="mt-4" data-testid="monitor-prior">
                        <p class="text-sm text-neutral">Ya traen sus grupos escritos en la relación de materias: no tienen turno y su carga quedó cerrada al publicar. Quien quedó arriba de la banda de su base debe confirmar sus horas con la casilla; hasta entonces su HC-08 sigue como choque. Si necesitan más grupos, los asigna el jefe o el JPD.</p>
                        <ul class="mt-3 divide-y divide-base-300 rounded-box border border-base-300">
                            @foreach ($monitor['prior'] as $row)
                                <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-2" wire:key="prior-{{ $row['entry']->id }}">
                                    <b>{{ $row['name'] }}</b>
                                    @if ($row['confirmation'] === 'pending')
                                        <x-core::status-badge tone="warning" icon="o-exclamation-triangle" label="Falta confirmar sus horas" class="badge-sm" data-testid="monitor-prior-pending" />
                                    @elseif ($row['confirmation'] === 'confirmed')
                                        <x-core::status-badge tone="success" icon="o-check-circle" label="Confirmó sus horas" class="badge-sm" data-testid="monitor-prior-confirmed" />
                                    @endif
                                    <span class="text-sm text-neutral"><span class="font-mono">{{ $row['hours'] }}</span> h · <span class="font-mono text-xs">{{ $row['groups'] === [] ? '—' : implode(', ', $row['groups']) }}</span></span>
                                </li>
                            @endforeach
                        </ul>
                    </x-card>
                @endif

                <x-card title="Avance por docente" shadow class="mt-4">
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead><tr><th>#</th><th>Docente</th><th>Turno</th><th>Estado</th><th>Horas</th><th>Grupos</th><th>Acceso</th></tr></thead>
                            <tbody>
                                @foreach ($monitor['rows'] as $row)
                                    <tr wire:key="mon-{{ $row['entry']->id }}" @class(['bg-info/10' => $row['current']])>
                                        <td class="font-mono">{{ $row['entry']->position }}</td>
                                        <td class="font-semibold">{{ $row['name'] }}@if ($row['admin_post'] !== null) <x-core::status-badge tone="info" label="Puesto administrativo" class="badge-sm" title="{{ $row['admin_post'] }}" data-testid="monitor-admin-post" />@endif</td>
                                        <td class="whitespace-nowrap font-mono text-xs">{{ $row['entry']->slot_starts_at?->locale('es')->isoFormat('ddd D, H:mm') }}</td>
                                        <td>{{ $row['current'] ? 'En turno' : $row['entry']->status->label() }}</td>
                                        <td><span class="font-mono">{{ $row['hours'] }}</span> <span class="text-neutral">de {{ $row['entry']->base_hours }} h</span></td>
                                        <td class="font-mono text-xs">{{ $row['groups'] === [] ? '—' : implode(', ', $row['groups']) }}</td>
                                        <td @class(['text-xs', 'font-semibold text-warning' => in_array($row['access'], ['pending', 'no_email'], true)])>{{ $accessLabels[$row['access']] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-card>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <x-card title="Ronda extra" shadow data-testid="monitor-extra">
                        @forelse ($monitor['extra'] as $row)
                            <p><b>{{ $row['name'] }}</b> <span class="text-sm text-neutral">terminó con {{ $row['hours'] }} h de {{ $row['entry']->base_hours }}</span></p>
                        @empty
                            <p class="text-sm text-neutral">Nadie pasó a la ronda extra.</p>
                        @endforelse
                    </x-card>
                    <x-card title="Sin resolver" shadow data-testid="monitor-unresolved">
                        @forelse ($monitor['unresolved'] as $row)
                            <p><b>{{ $row['name'] }}</b> <span class="text-sm text-neutral">pasó la ronda extra sin completar: lo resuelve la jefatura.</span></p>
                        @empty
                            <p class="text-sm text-neutral">Nadie.</p>
                        @endforelse
                    </x-card>
                </div>
            </div>
        @endif
    @endif

    @if ($cancellable)
        <x-modal wire:model="showCancel" title="¿Cancelar la selección?" box-class="max-w-lg">
            @if ($draft)
                <p>Se descarta el borrador y no se avisa a nadie. Puedes abrir otra después.</p>
            @else
                <p>Los turnos ya no corren. Los docentes de la cola reciben el aviso por la campana y por correo.</p>
                <p class="mt-2 text-sm text-neutral">Las cargas que ya pasaron a asignación se quedan así. Puedes preparar y publicar otra selección después.</p>
            @endif
            <x-slot:actions>
                <x-button label="Volver" wire:click="$set('showCancel', false)" />
                <x-button label="Cancelar la selección" class="btn-error" wire:click="cancel" spinner="cancel" />
            </x-slot:actions>
        </x-modal>
    @endif
</div>
