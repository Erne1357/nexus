{{--
    El drawer de un registro de la lista (catalog-validation::review.list): como el panel de la retícula del plan,
    a la derecha y a pantalla completa en celular. Encabezado con la clave, el estado y el nombre; «Datos» con lo que
    ata el registro (clave, departamento, carrera, edificio) visible pero deshabilitado (CV-S3) y los campos que el
    jefe corrige (CV1, CV2); «Estado»; y «Observaciones» del registro con «Marcar como resuelta» y el formulario para
    poner una (CV4). Los docentes no se editan aquí: solo sus observaciones y el enlace a su ficha.

    `$card` es el de la revisión (review.php) y `$editing`, el registro con sus observaciones y lo bloqueado. Sin
    permiso para corregir, los campos se muestran deshabilitados con el motivo; `close()` es del x-drawer.
--}}
@php($review = $card['review'])
@php($record = $editing['record'])
@php($catalog = $review->catalog)
@php($isTeacher = $catalog === \App\Modules\CatalogValidation\Enums\Catalog::Teachers)
@php($locked = ! $card['canEdit'])
<div class="flex min-h-dvh flex-col" data-testid="record-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-[15px] font-semibold">{{ $record->code ?? $record->employee_number }}</span>
                <x-core::status-badge :tone="$record->is_active ? 'success' : 'neutral'" :label="$record->is_active ? 'Activo' : 'Inactivo'" />
            </div>
            <h2 id="review-drawer-title" class="mt-0.5 text-xl font-bold leading-tight">{{ $record->name }}</h2>
            <p class="mt-1 text-sm text-neutral">{{ $catalog->label() }} · {{ $term->name }} <span class="font-mono">{{ $term->code }}</span></p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-6 px-5 pt-4 pb-8">
        @foreach (['status', 'record'] as $key)
            @error($key)
                <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm" :title="$message" />
            @enderror
        @endforeach

        @if ($locked && $card['editReason'] !== null)
            <x-alert icon="o-lock-closed" class="alert-soft text-sm" :title="$card['editReason']" data-testid="read-only-reason" />
        @endif

        <section class="space-y-3" aria-labelledby="drawer-data">
            <h3 id="drawer-data" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Datos</h3>

            @foreach ($editing['locked'] as $field)
                <x-input :label="$field['label']" :value="$field['value']" icon="o-lock-closed" disabled readonly />
            @endforeach

            @if ($isTeacher)
                <p class="text-sm"><a href="{{ route('faculty.teachers.show', $record->id) }}" wire:navigate class="font-medium text-primary underline underline-offset-4" data-testid="teacher-record-link">Abrir la ficha de {{ $record->name }}</a>
                    <span class="text-neutral">: los datos, las plazas y la disponibilidad se corrigen ahí.</span></p>
            @else
                <form id="review-record-form" wire:submit="save" class="space-y-3">
                    <x-input label="Nombre" name="name" wire:model="name" :disabled="$locked" />

                    @if ($catalog === \App\Modules\CatalogValidation\Enums\Catalog::Subjects)
                        <div class="grid grid-cols-3 gap-3">
                            <x-input label="Teoría" name="theory_hours" wire:model="theory_hours" type="number" min="0" inputmode="numeric" class="font-mono" :disabled="$locked" />
                            <x-input label="Práctica" name="practice_hours" wire:model="practice_hours" type="number" min="0" inputmode="numeric" class="font-mono" :disabled="$locked" />
                            <x-input label="Créditos" name="credits" wire:model="credits" type="number" min="0" inputmode="numeric" class="font-mono" :disabled="$locked" />
                        </div>
                        <x-select label="Tipo" name="kind" wire:model="kind" :options="$kinds" placeholder="Sin tipo" placeholder-value="" :disabled="$locked" />
                        <x-toggle label="Requiere laboratorio" name="needs_lab" wire:model="needs_lab" :disabled="$locked" />
                    @elseif ($catalog === \App\Modules\CatalogValidation\Enums\Catalog::StudyPlans)
                        <div class="grid grid-cols-2 gap-3">
                            <x-input label="Versión" name="version" wire:model="version" :disabled="$locked" />
                            <x-input label="Vigente desde" name="valid_from" wire:model="valid_from" type="date" :disabled="$locked" />
                            <x-input label="Semestres" name="semesters_count" wire:model="semesters_count" type="number" min="1" inputmode="numeric" class="font-mono" :disabled="$locked" />
                            <x-input label="Créditos" name="total_credits" wire:model="total_credits" type="number" min="0" inputmode="numeric" class="font-mono" :disabled="$locked" />
                        </div>
                    @elseif ($catalog === \App\Modules\CatalogValidation\Enums\Catalog::Rooms)
                        <div class="grid grid-cols-2 gap-3">
                            <x-select label="Tipo" name="type" wire:model="type" :options="$roomTypes" :disabled="$locked" />
                            <x-input label="Capacidad" name="capacity" wire:model="capacity" type="number" min="1" inputmode="numeric" class="font-mono" :disabled="$locked" />
                        </div>
                        <x-input label="Características" name="features" wire:model="features" hint="Separadas por comas" :disabled="$locked" />
                    @endif
                </form>
            @endif
        </section>

        @unless ($isTeacher)
            <section class="space-y-2.5" aria-labelledby="drawer-state">
                <h3 id="drawer-state" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Estado</h3>
                <x-toggle label="Activo en el catálogo" name="is_active" wire:model="is_active" form="review-record-form" :disabled="! $card['canToggle']" />
                @if (! $card['canToggle'] && $card['toggleReason'] !== null)
                    <p class="text-xs text-neutral">{{ $card['toggleReason'] }}</p>
                @endif
                @error('is_active') <p class="text-sm text-error">{{ $message }}</p> @enderror
            </section>
        @endunless

        <section class="space-y-3" aria-labelledby="drawer-flags" data-testid="flags-section">
            <h3 id="drawer-flags" class="flex items-center gap-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                Observaciones
                <span class="font-mono tracking-normal text-base-content">{{ $editing['flags']->whereNull('resolved_at')->count() }} abiertas</span>
            </h3>

            @forelse ($editing['flags'] as $item)
                <article wire:key="flag-{{ $item->id }}" @class(['space-y-1.5 rounded-box border p-3 text-sm', 'border-warning/50 bg-warning/10' => $item->resolved_at === null, 'border-base-300 text-neutral' => $item->resolved_at !== null])>
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <x-core::status-badge :tone="$item->resolved_at === null ? 'warning' : 'neutral'" icon="o-chat-bubble-left-ellipsis" :label="$item->flag->label()" />
                        <span>{{ $item->flagger?->name }} · <span class="tabular-nums">{{ $item->created_at->timezone(config('app.timezone'))->translatedFormat('j M Y') }}</span></span>
                    </p>
                    @if ($item->comment)
                        <p>{{ $item->comment }}</p>
                    @endif
                    @if ($item->resolved_at !== null)
                        <p class="text-xs">Resuelta por {{ $item->resolver?->name }} · <span class="tabular-nums">{{ $item->resolved_at->timezone(config('app.timezone'))->translatedFormat('j M Y') }}</span></p>
                    @elseif ($card['canResolve'])
                        <x-button label="Marcar como resuelta" icon="o-check" class="btn-outline btn-sm" wire:click="resolveFlag('{{ $item->id }}')" spinner="resolveFlag('{{ $item->id }}')" data-testid="resolve-flag" />
                    @endif
                </article>
            @empty
                <p class="text-sm text-neutral">Este registro no tiene observaciones.</p>
            @endforelse

            @if ($card['canFlag'])
                <form wire:submit="addFlag" class="space-y-3 border-t border-dashed border-base-300 pt-4" data-testid="flag-form">
                    <x-select label="Tipo" name="flag" wire:model="flag" :options="$flagOptions" placeholder="Elige el tipo" placeholder-value="" />
                    <x-textarea label="Comentario" name="comment" wire:model="comment" rows="3" hint="Obligatorio en «Otra»" />
                    <x-button label="Agregar observación" type="submit" icon="o-chat-bubble-left-ellipsis" class="btn-outline btn-sm" spinner="addFlag" data-testid="add-flag" />
                </form>
            @elseif ($card['flagReason'] !== null)
                <p class="text-sm text-neutral">{{ $card['flagReason'] }}</p>
            @endif
        </section>
    </div>

    <footer class="sticky bottom-0 flex items-center justify-between gap-3 border-t border-base-300 bg-base-100 px-5 py-3">
        <p class="text-xs text-neutral">Cada cambio queda en la bitácora.</p>
        <div class="flex gap-2">
            <x-button label="{{ $card['canEdit'] ? 'Cancelar' : 'Cerrar' }}" @click="close()" />
            @if ($card['canEdit'])
                <x-button label="Guardar" type="submit" form="review-record-form" icon="o-check" class="btn-primary" spinner="save" data-testid="save-record" />
            @endif
        </div>
    </footer>
</div>
