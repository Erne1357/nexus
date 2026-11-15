{{--
    Las insignias de origen de un permiso (core::pages.permissions): «Todo» (el admin), «Fábrica» (el rol de fábrica del
    puesto), el nombre de otro rol, o «Suelto». No son insignias de estado: dicen de dónde le viene el permiso. Espera
    `$chips` (los de chips() del componente).
--}}
<span class="flex min-w-0 flex-wrap justify-end gap-1">
    @foreach ($chips as $chip)
        <span
            title="{{ $chip['title'] }}"
            @class([
                'inline-flex h-5.5 min-w-0 max-w-full items-center gap-1 whitespace-nowrap rounded-selector border px-1.5 text-xs font-medium',
                'border-base-300 bg-base-100 text-neutral' => $chip['kind'] === 'admin',
                'border-base-300 bg-base-100 text-primary' => $chip['kind'] === 'factory',
                'border-base-300 bg-secondary/40 text-primary' => $chip['kind'] === 'role',
                'border-info/40 bg-info/10 text-info' => $chip['kind'] === 'loose',
            ])
        >
            <x-icon :name="$chip['icon']" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span class="truncate">{{ $chip['label'] }}</span>
        </span>
    @endforeach
</span>
