{{--
    El drawer de un registro de la lista (catalog-validation::review.list): como el panel de la retícula del plan,
    a la derecha y a pantalla completa en celular. Encabezado con la clave, el estado y el nombre; «Datos» con lo que
    ata el registro (clave, departamento, carrera, edificio; CV-S3) como texto, nunca como campos deshabilitados, y los
    campos que el jefe corrige (CV1, CV2); «Estado»; y «Observaciones» del registro con «Marcar como resuelta» y el
    formulario para poner una (CV4). Los docentes no se editan aquí: solo sus observaciones y el enlace a su ficha.

    `$card` es el de la revisión (review.php) y `$editing`, el registro con sus observaciones, lo bloqueado y sus datos
    en texto. Sin permiso para corregir, los datos van como lista de texto legible, con las observaciones primero y el
    motivo una sola vez, en la alerta; `close()` es del x-drawer.
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
                <span class="font-mono text-[15px] font-semibold">{{ $record->code ?? $record->rfc }}</span>
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

        @if ($locked)
            @include('catalog-validation::review.drawer-flags')
        @endif

        <section class="space-y-3" aria-labelledby="drawer-data">
            <h3 id="drawer-data" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Datos</h3>

            @php($facts = [...array_map(fn (array $field): array => ['mono' => false, ...$field], $editing['locked']), ...($locked ? $editing['facts'] : [])])
            <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-5 gap-y-2 text-sm" data-testid="record-facts">
                @foreach ($facts as $fact)
                    <dt class="text-neutral">{{ $fact['label'] }}</dt>
                    <dd @class(['min-w-0 break-words text-base-content', 'font-mono tabular-nums' => $fact['mono']])>{{ $fact['value'] ?? '—' }}</dd>
                @endforeach
            </dl>

            @if ($isTeacher)
                <p class="text-sm"><a href="{{ route('faculty.teachers.show', $record->id) }}" wire:navigate class="font-medium text-primary underline underline-offset-4" data-testid="teacher-record-link">Abrir la ficha de {{ $record->name }}</a></p>
            @elseif (! $locked)
                <form id="review-record-form" wire:submit="save" class="space-y-3">
                    <x-input label="Nombre" name="name" wire:model="name" />

                    @if ($catalog === \App\Modules\CatalogValidation\Enums\Catalog::Subjects)
                        <div class="grid grid-cols-3 gap-3">
                            <x-input label="Teoría" name="theory_hours" wire:model="theory_hours" type="number" min="0" inputmode="numeric" class="font-mono" />
                            <x-input label="Práctica" name="practice_hours" wire:model="practice_hours" type="number" min="0" inputmode="numeric" class="font-mono" />
                            <x-input label="Créditos" name="credits" wire:model="credits" type="number" min="0" inputmode="numeric" class="font-mono" />
                        </div>
                        <x-select label="Tipo" name="kind" wire:model="kind" :options="$kinds" placeholder="Sin tipo" placeholder-value="" />
                        <x-toggle label="Requiere laboratorio" name="needs_lab" wire:model="needs_lab" />
                    @elseif ($catalog === \App\Modules\CatalogValidation\Enums\Catalog::StudyPlans)
                        <div class="grid grid-cols-2 gap-3">
                            <x-input label="Versión" name="version" wire:model="version" />
                            <x-input label="Vigente desde" name="valid_from" wire:model="valid_from" type="date" />
                            <x-input label="Semestres" name="semesters_count" wire:model="semesters_count" type="number" min="1" inputmode="numeric" class="font-mono" />
                            <x-input label="Créditos" name="total_credits" wire:model="total_credits" type="number" min="0" inputmode="numeric" class="font-mono" />
                        </div>
                    @elseif ($catalog === \App\Modules\CatalogValidation\Enums\Catalog::Rooms)
                        <div class="grid grid-cols-2 gap-3">
                            <x-select label="Tipo" name="type" wire:model="type" :options="$roomTypes" />
                            <x-input label="Capacidad" name="capacity" wire:model="capacity" type="number" min="1" inputmode="numeric" class="font-mono" />
                        </div>
                        <x-input label="Características" name="features" wire:model="features" hint="Separadas por comas" />
                    @endif
                </form>
            @endif
        </section>

        @if (! $isTeacher && ! $locked)
            <section class="space-y-2.5" aria-labelledby="drawer-state">
                <h3 id="drawer-state" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Estado</h3>
                @if ($card['canToggle'])
                    <x-toggle label="Activo en el catálogo" name="is_active" wire:model="is_active" form="review-record-form" />
                @else
                    <p class="text-sm"><span class="text-neutral">Activo en el catálogo</span> <span class="font-semibold">{{ $record->is_active ? 'Sí' : 'No' }}</span></p>
                    @if ($card['toggleReason'] !== null)
                        <p class="text-xs text-neutral">{{ $card['toggleReason'] }}</p>
                    @endif
                @endif
                @error('is_active') <p class="text-sm text-error">{{ $message }}</p> @enderror
            </section>
        @endif

        @unless ($locked)
            @include('catalog-validation::review.drawer-flags')
        @endunless
    </div>

    <footer class="sticky bottom-0 flex items-center justify-between gap-3 border-t border-base-300 bg-base-100 px-5 py-3">
        <div class="ms-auto flex gap-2">
            <x-button label="{{ $card['canEdit'] ? 'Cancelar' : 'Cerrar' }}" @click="close()" />
            @if ($card['canEdit'])
                <x-button label="Guardar" type="submit" form="review-record-form" icon="o-check" class="btn-primary" spinner="save" data-testid="save-record" />
            @endif
        </div>
    </footer>
</div>
