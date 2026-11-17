{{--
    El panel de un paquete de primer semestre (academic-load::pages.proposal, dentro de su x-drawer; tarea 5.6b, opción C):
    su nombre (opcional: sin él se llama «Paquete 3»), las materias de 1.º con casillas y, «si hace falta», las de 2.º, y el
    aviso de que la composición es de la carrera. Al pie, «Quitar paquete» (con el diálogo de Nexus, `x-confirm`; nunca uno
    del navegador, ADR-0023), «Cancelar» y «Guardar». Quien no arma la composición, o con la propuesta enviada o aceptada, lo
    ve de solo lectura: el nombre, las materias y cuántos abre cada campus como texto, y «Cerrar».

    `$openPackage` es el paquete de ProposalPackages (null si es nuevo o si ya no está); `close()` es del x-drawer.
--}}
@php
    $isNew = $packageId === null;
    $label = 'text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral';
    $defaultName = $isNew ? $packages->nextLabel : ($openPackage === null ? '' : 'Paquete '.preg_replace('/\D/', '', $openPackage['package']->code));
    $title = $isNew ? 'Nuevo paquete' : ($openPackage['label'] ?? 'Paquete');
    $codes = array_values(array_filter(array_map(fn ($column) => $column['campus']?->code, $columns)));
    $last = array_pop($codes);
    $where = match (true) {
        $last === null => 'cambian sus grupos',
        $codes === [] => "cambian los grupos de {$last}",
        default => 'cambian los grupos '.implode(', ', array_map(fn ($code) => "de {$code}", $codes))." y de {$last}",
    };
    $readonly = ! $packagesManageable;
@endphp
<div class="flex min-h-dvh flex-col" data-testid="package-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="package-drawer-title" class="text-xl font-bold leading-tight">{{ $title }}</h2>
            <p class="mt-1 text-neutral">Paquete de primer semestre · <span class="font-mono">{{ $overview->proposal->career->code }}</span> · {{ $overview->proposal->modality->name }} · {{ $overview->proposal->term->name }}</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    @if (! $isNew && $openPackage === null)
        <div class="flex-1 px-5 py-6 text-neutral" data-testid="package-gone">Este paquete ya no está en la propuesta.</div>
        <div class="sticky bottom-0 flex justify-end border-t border-base-300 bg-base-100 px-5 py-3">
            <x-button label="Cerrar" class="btn-ghost" @click="close()" />
        </div>
    @elseif ($readonly)
        <div class="flex-1 space-y-4 px-5 pt-4 pb-8" data-testid="package-readonly">
            @if ($generalLock)
                <x-alert icon="o-lock-closed" class="alert-warning alert-soft text-sm" :title="$generalLock->message('')" />
            @endif
            <div>
                <p class="{{ $label }}">Materias</p>
                <ul class="mt-1.5 divide-y divide-base-300">
                    @foreach ($openPackage['subjects'] ?? [] as $subject)
                        <li class="flex items-baseline gap-3 py-2">
                            <span class="w-[5.6rem] shrink-0 font-mono text-[13px] font-semibold">{{ $subject->subject->code }}</span>
                            <span class="min-w-0 flex-1">{{ $subject->subject->name }}</span>
                            @if ($subject->semester !== 1)
                                <span class="shrink-0 text-xs text-neutral">{{ $subject->semester }}.º semestre</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
            <dl class="space-y-2">
                @foreach ($columns as $key => $column)
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="flex items-center gap-2 text-sm"><x-academic-load::campus-tag :campus="$column['campus']" /> Cuántos abre {{ $column['campus']?->code ?? 'la modalidad' }}</dt>
                        <dd class="font-mono text-lg font-semibold tabular-nums">{{ ($openPackage['counts'][$key] ?? null)?->count ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
        <div class="sticky bottom-0 flex justify-end border-t border-base-300 bg-base-100 px-5 py-3">
            <x-button label="Cerrar" class="btn-ghost" @click="close()" />
        </div>
    @else
        <x-form wire:submit="savePackage" class="flex flex-1 flex-col gap-0!" no-separator>
            <div class="flex-1 space-y-5 px-5 pt-4 pb-6">
                <x-input label="Nombre" wire:model="packageName" maxlength="{{ \App\Modules\AcademicLoad\Rules\ProposalPackageRules::MAX_NAME_LENGTH }}" :placeholder="$defaultName"
                    :hint="'Opcional. Sin nombre se llama «'.$defaultName.'».'" data-testid="package-name" />

                @foreach ([['Materias de 1.º', $packages->firstSemester, 'first'], ['De 2.º, si hace falta', $packages->secondSemester, 'second']] as [$legend, $options, $group])
                    @if ($options !== [])
                        <fieldset aria-describedby="{{ $errors->has('packageSubjects') ? 'package-subjects-error' : '' }}" data-testid="package-subjects-{{ $group }}">
                            <legend class="{{ $label }} mb-1.5">{{ $legend }}</legend>
                            <div class="flex flex-col">
                                @foreach ($options as $subject)
                                    <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-field px-2 py-1.5 hover:bg-base-200 md:min-h-10" wire:key="package-option-{{ $subject->id() }}">
                                        <input type="checkbox" class="checkbox checkbox-sm" wire:model="packageSubjects" value="{{ $subject->id() }}" data-testid="package-subject-{{ $subject->subject->code }}" />
                                        <span class="w-[5.6rem] shrink-0 font-mono text-[13px] font-semibold">{{ $subject->subject->code }}</span>
                                        <span class="min-w-0 flex-1">{{ $subject->subject->name }}</span>
                                        @if ($subject->subject->needs_lab)
                                            <span class="shrink-0 text-neutral" title="Requiere laboratorio"><x-icon name="o-beaker" class="h-4 w-4" aria-hidden="true" /><span class="sr-only">Requiere laboratorio</span></span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif
                @endforeach

                @error('packageSubjects')
                    <p id="package-subjects-error" class="flex items-start gap-1.5 text-sm text-error" data-testid="package-subjects-error"><x-icon name="o-exclamation-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ $message }}</p>
                @enderror

                @error('package')
                    <x-alert icon="o-exclamation-triangle" class="alert-error alert-soft text-sm" :title="$message" data-testid="package-error" />
                @enderror

                <x-alert icon="o-information-circle" class="alert-info alert-soft text-sm" :title="'Las materias del paquete son de la carrera: si las cambias, '.$where.'.'" data-testid="package-shared" />
            </div>

            <div class="sticky bottom-0 flex flex-wrap items-center gap-2 border-t border-base-300 bg-base-100 px-5 py-3">
                @unless ($isNew)
                    <x-button label="Quitar paquete" icon="o-trash" class="btn-ghost text-error max-md:min-h-11" wire:click="deletePackage" spinner="deletePackage"
                        x-confirm="Sus materias dejan de abrir grupos por este paquete en todos los campus; los grupos sueltos se quedan." :data-confirm-title="'¿Quitar '.$title.'?'" data-confirm-button="Quitar paquete" data-confirm-tone="error"
                        data-testid="package-delete" />
                @endunless
                <span class="flex-1"></span>
                <x-button label="Cancelar" class="btn-ghost max-md:min-h-11" @click="close()" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary max-md:min-h-11" spinner="savePackage" data-testid="package-save" />
            </div>
        </x-form>
    @endif
</div>
