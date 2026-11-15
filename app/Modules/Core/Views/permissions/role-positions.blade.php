{{--
    Los puestos que tienen un rol, como chips (core::permissions.roles-tab); tachados si el rol está desactivado, porque
    sigue asignado pero no aporta (CF-S2). Espera `$role` (un renglón de roleRow()).
--}}
@if ($role['positions'] === [])
    <span class="text-sm text-neutral">Ninguno</span>
@else
    <ul class="flex flex-wrap gap-1">
        @foreach ($role['positions'] as $position)
            <li @class(['rounded-selector border border-base-300 bg-secondary/40 px-1.5 py-0.5 text-[12.5px]', 'text-neutral line-through' => ! $role['is_active']])>{{ $position }}</li>
        @endforeach
    </ul>
@endif
