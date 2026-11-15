{{--
    «Puestos y permisos» (1.22-B, maqueta B «por puesto»): el encabezado, las pestañas «Puestos», «Roles» y «¿Quién
    puede…?» (`tabs tabs-border`, como Personas y puestos) y los tres drawers: «Agregar rol», «Agregar permiso suelto» y
    «Editar rol» (ADR-0023). Cada pestaña y cada drawer van en su parcial de core::permissions.
--}}
<div>
    <x-header title="Puestos y permisos" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Qué puede hacer cada puesto: sus roles y sus permisos sueltos.
        </x-slot:subtitle>
    </x-header>

    <div role="tablist" aria-label="Vistas de puestos y permisos" class="tabs tabs-border mb-5" data-testid="permissions-tabs">
        @foreach (['' => ['Puestos', $positionCount], 'roles' => ['Roles', $setCount], 'quien' => ['¿Quién puede…?', null]] as $value => [$label, $count])
            <button
                type="button"
                role="tab"
                wire:click="$set('tab', '{{ $value }}')"
                @class(['tab gap-2 max-md:min-h-11', 'tab-active font-semibold' => $view === $value])
                aria-selected="{{ $view === $value ? 'true' : 'false' }}"
                data-testid="tab-{{ $value === '' ? 'puestos' : $value }}"
            >
                {{ $label }}
                @if ($count !== null)
                    <span class="font-mono text-[12.5px] font-normal text-neutral">{{ $count }}</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($view === 'roles')
        @include('core::permissions.roles-tab')
    @elseif ($view === 'quien')
        @include('core::permissions.who-tab')
    @else
        @include('core::permissions.positions-tab')
    @endif

    <x-drawer wire:model.live="showAddRole" right close-on-escape class="w-full max-w-lg p-0!" role="dialog" aria-modal="true" aria-labelledby="add-role-title" data-testid="add-role-drawer">
        @if ($addRole !== null)
            @include('core::permissions.add-role-drawer')
        @endif
    </x-drawer>

    <x-drawer wire:model.live="showAddLoose" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="add-loose-title" data-testid="add-loose-drawer">
        @if ($addLoose !== null)
            @include('core::permissions.add-loose-drawer')
        @endif
    </x-drawer>

    <x-drawer wire:model.live="showEditor" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="role-editor-title" data-testid="role-editor-drawer">
        @if ($editor !== null)
            @include('core::permissions.role-editor')
        @endif
    </x-drawer>
</div>
