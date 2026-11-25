{{--
    Ficha del alumno (DESIGN.md, "Retícula del alumno"): encabezado con sus datos y cifras, y dos pestañas. La
    retícula va en columnas por semestre, como la del SII, con las piezas de Core (core::semester-grid): una sola
    cuadrícula con mosaicos de tamaño fijo, el tronco común arriba y una banda por especialidad; en celular los
    semestres se apilan. Cada materia lleva el relleno, el borde y el icono de su estado, y el estado en texto para
    lectores de pantalla. Arriba va la leyenda de estados; debajo, la de cadenas y la barra de selección.

    Debajo de la retícula, las materias de su plan que esperan semestre (D18), en el orden del SII: clave, nombre,
    créditos y si las acreditó, bajo «Por validar» o, en una retícula histórica, «Sin semestre en el SII». Un plan sin
    ningún semestre no pinta la cuadrícula ni su leyenda: solo esa lista.
--}}
<div class="space-y-5">
    <a href="{{ route('students.index') }}" wire:navigate class="inline-flex items-center gap-1.5 font-medium text-primary">
        <x-icon name="o-chevron-left" class="w-4 h-4" />Alumnos
    </a>

    <div class="flex items-start gap-4">
        @if ($photoUrl !== null)
            <img src="{{ $photoUrl }}" alt="Foto de {{ $record->student->name }}" class="aspect-3/4 w-14 shrink-0 rounded-md border border-base-300 bg-base-200 object-cover object-top" data-testid="student-header-photo" />
        @endif
        <div>
        <h1 class="flex flex-wrap items-center gap-3 text-[1.625rem] font-bold leading-tight md:text-3xl">
            {{ $record->student->name }}
            <span class="font-mono text-xl font-semibold text-neutral tabular-nums">{{ $record->student->control_number }}</span>
            @include('students::status-badge', ['status' => $record->student->status])
        </h1>
        <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral">
            <span>Carrera <span class="font-medium text-base-content"><span class="font-mono">{{ $record->student->career->code }}</span> · {{ $record->student->career->name }}</span></span>
            <span>Plan <span class="font-mono font-medium text-base-content">{{ $record->student->studyPlan->code }}</span></span>
            <span>Campus <span class="font-medium text-base-content">{{ $record->student->campus->name }}</span></span>
            <span>Semestre <span class="font-mono font-medium text-base-content">{{ $record->student->current_semester ?? '—' }}</span></span>
        </p>
        </div>
    </div>

    <div class="grid grid-cols-2 overflow-hidden rounded-box border border-base-300 bg-base-100 desktop:grid-cols-4">
        @foreach ([
            ['Créditos aprobados', $record->creditsApproved, $record->totalCredits],
            ['Materias acreditadas', $record->subjectsApproved, $record->subjectsInGrid],
            ['Cursando', $record->taking, null],
            ['Puede seleccionar', $record->selectable, null],
        ] as [$label, $value, $of])
            <div class="flex flex-col gap-1.5 border-base-300 px-5 py-3.5 [&:nth-child(n+2)]:border-l max-desktop:[&:nth-child(3)]:border-l-0 max-desktop:[&:nth-child(n+3)]:border-t">
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</span>
                <span class="font-mono text-2xl font-semibold tabular-nums">{{ $value }}@if ($of !== null) <span class="text-base font-normal text-neutral">de {{ $of }}</span>@endif</span>
                @if ($loop->first && $of > 0)
                    <progress class="progress progress-success h-1.5" value="{{ $value }}" max="{{ $of }}" aria-label="Avance en créditos"></progress>
                @endif
            </div>
        @endforeach
    </div>

    <div role="tablist" class="tabs tabs-border">
        <button type="button" role="tab" @class(['tab', 'tab-active font-bold' => $tab === 'reticula']) aria-selected="{{ $tab === 'reticula' ? 'true' : 'false' }}" wire:click="$set('tab', 'reticula')">Retícula</button>
        <button type="button" role="tab" @class(['tab', 'tab-active font-bold' => $tab === 'kardex']) aria-selected="{{ $tab === 'kardex' ? 'true' : 'false' }}" wire:click="$set('tab', 'kardex')" data-testid="tab-kardex">Kárdex</button>
        <button type="button" role="tab" @class(['tab', 'tab-active font-bold' => $tab === 'credencial']) aria-selected="{{ $tab === 'credencial' ? 'true' : 'false' }}" wire:click="$set('tab', 'credencial')" data-testid="tab-credencial">Credencial</button>
    </div>

    @if ($tab === 'credencial' && $credential !== null)
        {{-- CO-10 (maqueta B): una cara a la vez con el interruptor Frente/Reverso; al lado, la foto con «Cambiar foto» y el historial de solicitudes. --}}
        <div class="grid items-start gap-6 desktop:grid-cols-[minmax(0,1fr)_20rem]" data-testid="credential-tab">
            <x-students::credential-card :credential="$credential" mode="staff" />

            <div class="space-y-5">
                <section class="space-y-3 rounded-box border border-base-300 bg-base-100 p-4" aria-labelledby="credential-photo-title">
                    <h2 id="credential-photo-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Foto de la credencial</h2>
                    @if ($photoUrl !== null)
                        <img src="{{ $photoUrl }}" alt="Foto de {{ $record->student->name }}" class="aspect-3/4 w-32 rounded-box border border-base-300 bg-base-200 object-cover object-top" data-testid="credential-side-photo" />
                    @else
                        <p class="text-sm text-neutral" data-testid="credential-no-photo">Todavía no tiene foto.</p>
                    @endif
                    @if ($canManagePhoto)
                        <x-button :label="$photoUrl === null ? 'Subir foto' : 'Cambiar foto'" icon="o-arrow-up-tray" class="btn-sm" wire:click="openPhoto" spinner="openPhoto" data-testid="photo-open" />
                    @endif
                </section>

                <section class="space-y-2 rounded-box border border-base-300 bg-base-100 p-4" aria-labelledby="credential-history-title" data-testid="credential-history">
                    <h2 id="credential-history-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Solicitudes de credencial</h2>
                    <x-students::credential-requests-history :requests="$requests" />
                </section>
            </div>
        </div>

        @if ($canManagePhoto)
            <x-drawer wire:model.live="showPhoto" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="photo-drawer-title" data-testid="photo-drawer">
                @if ($showPhoto)
                    <form wire:submit="savePhoto" class="flex h-dvh flex-col">
                        <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
                            <div class="min-w-0">
                                <h2 id="photo-drawer-title" class="text-xl font-bold">{{ $photoUrl === null ? 'Subir foto' : 'Cambiar foto' }}</h2>
                                <p class="text-sm text-neutral">{{ $record->student->name }} · <span class="font-mono tabular-nums">{{ $record->student->control_number }}</span></p>
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto max-md:size-11" wire:click="closePhoto" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
                        </header>
                        <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
                            <x-file label="Foto nueva" name="photo" wire:model="photo" accept="image/jpeg,image/png,image/webp" hint="jpg, png o webp de hasta 10 MB. La foto anterior se conserva." data-testid="photo-input" />
                            @error('photo')
                                <p class="text-sm text-error" data-testid="photo-error">{{ $message }}</p>
                            @enderror
                            <div class="grid grid-cols-2 gap-4">
                                @if ($photoUrl !== null)
                                    <figure class="space-y-1.5">
                                        <img src="{{ $photoUrl }}" alt="Foto actual" class="aspect-3/4 w-full rounded-box border border-base-300 bg-base-200 object-cover object-top" />
                                        <figcaption class="text-xs text-neutral">Actual</figcaption>
                                    </figure>
                                @endif
                                @if ($preview !== null)
                                    <figure class="space-y-1.5" data-testid="photo-preview">
                                        <img src="{{ $preview }}" alt="Vista previa del recorte" class="aspect-3/4 w-full rounded-box border border-base-300 bg-base-200 object-cover object-top" />
                                        <figcaption class="text-xs text-neutral">Así se verá: recorte 3:4, centrado y pegado arriba.</figcaption>
                                    </figure>
                                @endif
                            </div>
                        </div>
                        <footer class="flex flex-wrap justify-end gap-2 border-t border-base-300 px-5 py-3">
                            <x-button label="Cancelar" wire:click="closePhoto" />
                            <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="savePhoto" data-testid="photo-save" />
                        </footer>
                    </form>
                @endif
            </x-drawer>
        @endif
    @elseif ($tab !== 'kardex')
        <x-students::reticula :record="$record" />
    @else
        <x-students::kardex :record="$record" />
    @endif
</div>
