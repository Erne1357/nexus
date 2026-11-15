{{--
    Pestaña «Roles» (core::pages.permissions), con la anatomía de catálogo (DESIGN.md): la barra con «Nombre del rol», el
    filtro «Tipo» (de fábrica o propios) y «Nuevo rol»; la tabla con el rol y su descripción, cuántos permisos trae, qué
    puestos lo tienen y su estado («Sigue al código», «N nuevos de fábrica», «Editado», «Activo» o «Inactivo», CF-02 y
    CF-S2); «Editar» visible y lo demás en «Acciones» (core::permissions.role-actions). Debajo de 768 px, renglones
    apilados. Espera `$roleRows` y `$kinds`.
--}}
<div>
    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input wire:model.live.debounce.300ms="rolesSearch" placeholder="Nombre del rol" aria-label="Buscar rol" icon="o-magnifying-glass" clearable />
        </x-slot:search>

        <x-select wire:model.live="rolesKind" aria-label="Tipo" :options="$kinds" placeholder="Tipo" />

        <x-slot:action>
            <x-button label="Nuevo rol" icon="o-plus" class="btn-primary" wire:click="openNewRole" spinner="openNewRole" :disabled="! $canManage" />
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <div class="overflow-x-auto max-md:hidden">
            <table class="table [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40">
                <thead>
                    <tr>
                        <th>Rol</th>
                        <th class="text-end">Permisos</th>
                        <th class="max-[1200px]:hidden">Lo tienen</th>
                        <th>Estado</th>
                        <th><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($roleRows as $role)
                        <tr wire:key="role-row-{{ $role['id'] }}" data-testid="role-row-{{ $role['name'] }}" @class(['text-neutral' => ! $role['is_active']])>
                            <td class="min-w-52 max-w-md">
                                <span @class(['block', 'font-semibold' => $role['is_active']])>{{ $role['name'] }}</span>
                                @if ($role['description'] !== null)
                                    <span class="block text-[12.5px] text-neutral">{{ $role['description'] }}</span>
                                @endif
                            </td>
                            <td class="text-end font-mono text-[15px] tabular-nums">{{ $role['count'] }}</td>
                            <td class="max-[1200px]:hidden">
                                @include('core::permissions.role-positions', ['role' => $role])
                            </td>
                            <td><x-core::status-badge :tone="$role['badge']['tone']" :label="$role['badge']['label']" :icon="$role['badge']['icon']" /></td>
                            <td>@include('core::permissions.role-actions', ['role' => $role, 'inTable' => true])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="role-cards">
            @foreach ($roleRows as $role)
                <li wire:key="role-card-{{ $role['id'] }}" @class(['space-y-1.5 px-4 py-3', 'text-neutral' => ! $role['is_active']])>
                    <div class="flex items-start gap-2.5">
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $role['is_active']])>{{ $role['name'] }}</span>
                        <x-core::status-badge :tone="$role['badge']['tone']" :label="$role['badge']['label']" :icon="$role['badge']['icon']" />
                    </div>
                    <p class="text-[12.5px] text-neutral">{{ $role['count'] }} {{ $role['count'] === 1 ? 'permiso' : 'permisos' }}@if ($role['description'] !== null) · {{ $role['description'] }}@endif</p>
                    @include('core::permissions.role-positions', ['role' => $role])
                    @include('core::permissions.role-actions', ['role' => $role, 'inTable' => false])
                </li>
            @endforeach
        </ul>

        @if ($roleRows === [])
            <p class="flex items-center gap-2 px-4 py-6 text-sm text-neutral"><x-icon name="o-rectangle-stack" class="h-5 w-5" aria-hidden="true" />Ningún rol coincide con la búsqueda.</p>
        @else
            <div class="border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                Roles <span class="font-mono">1–{{ count($roleRows) }}</span> de <span class="font-mono">{{ count($roleRows) }}</span>
            </div>
        @endif
    </div>
</div>
