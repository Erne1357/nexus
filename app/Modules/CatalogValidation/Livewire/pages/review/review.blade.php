{{--
    Validación de catálogos (DESIGN.md, "Patrones de pantalla previstos": variante C, tablero de tarjetas, decidida
    por el usuario el 2 de noviembre de 2026). Encabezado y, en su renglón, periodo y departamento (las revisiones no son
    de un campus, decisión 6); los errores
    de las Actions (vetos, revisión validada); y el tablero (catalog-validation::review.board) o, con `?catalogo=`,
    la lista de un catálogo (catalog-validation::review.list) con su drawer, ambos bajo una fila de pestañas (Tablero y un
    catálogo por pestaña, con su estado; decidido por el usuario el 2 de noviembre de 2026 con datos masivos). El modal de confirmación de Validar y
    Reabrir es de la página: un modal dentro de una tarjeta quedaría encerrado en ella.
--}}
<div>
    <x-header title="Validación de catálogos" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-4!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Cada periodo el jefe de departamento revisa y valida los cinco catálogos de su departamento; con todos validados, la carga académica puede salir de borrador.
        </x-slot:subtitle>
    </x-header>

    @if ($current === null || $departments->count() > 1)
    <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" data-testid="review-context">
        @if ($term && $current === null)
            <p><span class="text-neutral">Periodo</span> <span class="font-semibold">{{ $term->name }}</span> <span class="font-mono">{{ $term->code }}</span></p>
        @endif
        @if ($departments->count() > 1)
            <div class="w-full md:w-72">
                <x-select
                    wire:model.live="department"
                    aria-label="Departamento"
                    :options="$departments->map(fn ($option) => ['id' => $option->id, 'name' => $option->code.' · '.$option->name])->all()"
                    placeholder="Elige un departamento"
                    placeholder-value=""
                />
            </div>
        @elseif ($department)
            <p><span class="text-neutral">Depto.</span> <span class="font-semibold">{{ $department->name }}</span></p>
        @endif
    </div>
    @endif

    @foreach (['status', 'record', 'records'] as $key)
        @error($key)
            <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft mb-4 text-sm" :title="$message" data-testid="review-error" />
        @enderror
    @endforeach

    @if ($empty !== null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="review-empty">
            <x-icon name="o-clipboard-document-check" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
            <p class="font-semibold">
                @switch ($empty)
                    @case ('department') Elige un departamento para ver su validación. @break
                    @case ('term') No hay un periodo activo. @break
                    @default Este periodo no tiene revisiones de catálogo. @break
                @endswitch
            </p>
        </div>
    @else
        <div role="tablist" aria-label="Catálogos" class="tabs tabs-border mb-4 flex-nowrap overflow-x-auto" data-testid="review-tabs">
            <a role="tab" href="{{ route('catalog-validation.review', ['department' => $department->id]) }}" wire:click.prevent="$set('catalog', '')" aria-selected="{{ $current === null ? 'true' : 'false' }}" data-testid="tab-board" @class(['tab shrink-0 gap-2', 'tab-active font-bold' => $current === null])>
                <x-icon name="o-squares-2x2" class="h-4 w-4" aria-hidden="true" />Tablero
            </a>
            @foreach ($tabs as $tab)
                @php($tabCatalog = $tab['catalog'])
                @php([$tabIcon, $tabTone] = match ($tab['status']) {
                    \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated => ['o-check-circle', 'text-success'],
                    \App\Modules\CatalogValidation\Enums\ReviewStatus::InReview => ['o-clock', 'text-info'],
                    default => ['o-minus-circle', 'text-warning'],
                })
                <a wire:key="tab-{{ $tabCatalog->value }}" role="tab" href="{{ route('catalog-validation.review', ['department' => $department->id, 'catalogo' => $tabCatalog->value]) }}" wire:click.prevent="$set('catalog', '{{ $tabCatalog->value }}')" aria-selected="{{ $current !== null && $current['review']->catalog === $tabCatalog ? 'true' : 'false' }}" data-testid="tab-{{ $tabCatalog->value }}" @class(['tab shrink-0 gap-2', 'tab-active font-bold' => $current !== null && $current['review']->catalog === $tabCatalog])>
                    {{ $tabCatalog->label() }}
                    <span class="font-mono text-xs font-normal text-neutral tabular-nums" title="Revisados" data-testid="tab-reviewed-{{ $tabCatalog->value }}">{{ $tab['reviewed']['reviewed'] }}/{{ $tab['reviewed']['total'] }}<span class="sr-only"> revisados</span></span>
                    <x-icon :name="$tabIcon" :class="'h-4 w-4 '.$tabTone" aria-hidden="true" />
                    <span class="sr-only">{{ $tab['status']->label() }}</span>
                </a>
            @endforeach
        </div>

        @if ($current === null)
            @include('catalog-validation::review.board')
        @else
            @include('catalog-validation::review.list')
        @endif
    @endif

    <x-modal wire:model="showConfirm" :title="$confirming && $confirmAction === 'reopen' ? 'Reabrir la revisión' : 'Validar la revisión'" box-class="max-w-lg" separator>
        @if ($confirming)
            @php($confirmReview = $confirming['review'])
            <div class="space-y-3 text-sm" data-testid="confirm-body">
                @if ($confirmAction === 'reopen')
                    <p>Vas a reabrir <strong>{{ $confirmReview->title }}</strong>: queda en revisión, sin quién ni cuándo se validó, y podrás corregir y observar de nuevo.</p>
                    <p class="text-neutral">Hasta que la valides otra vez, la carga académica del departamento no podrá salir de borrador.</p>
                @else
                    <p>Vas a validar <strong>{{ $confirmReview->title }}</strong>: <span class="font-mono">{{ $confirming['summary']['active'] }}</span> registros activos y <span class="font-mono">{{ $confirming['summary']['inactive'] }}</span> inactivos.</p>
                    @if ($confirming['summary']['open_flags'] > 0)
                        <p>Quedan <span class="font-mono">{{ $confirming['summary']['open_flags'] }}</span> observaciones abiertas; no impiden validar.</p>
                    @endif
                    @if ($missingInvitations['email'] > 0)
                        <p data-testid="without-institutional-email"><span class="font-mono">{{ $missingInvitations['email'] }}</span> {{ $missingInvitations['email'] === 1 ? 'docente activo sin correo institucional no recibirá su acceso.' : 'docentes activos sin correo institucional no recibirán su acceso.' }} Captúralo en su ficha; al guardarlo le llega la invitación.</p>
                    @endif
                    @if ($missingInvitations['user'] > 0)
                        <p data-testid="without-user"><span class="font-mono">{{ $missingInvitations['user'] }}</span> {{ $missingInvitations['user'] === 1 ? 'docente activo no recibirá su invitación porque no tiene un usuario activo o tiene otro puesto' : 'docentes activos no recibirán su invitación porque no tienen un usuario activo o tienen otro puesto' }}: les da acceso el administrador.</p>
                    @endif
                    <p class="text-neutral">Se guarda la foto de los registros activos: el próximo periodo se cuentan las altas y las bajas contra ella.</p>
                @endif
            </div>
        @endif

        <x-slot:actions>
            <x-button label="Cancelar" wire:click="cancelConfirm" />
            <x-button :label="$confirmAction === 'reopen' ? 'Reabrir' : 'Validar'" :icon="$confirmAction === 'reopen' ? 'o-arrow-uturn-left' : 'o-check'" class="btn-primary" wire:click="confirmed" spinner="confirmed" data-testid="confirm-accept" />
        </x-slot:actions>
    </x-modal>
</div>
