{{--
    Las pestañas de la bandeja del departamento (tarea 8.8, maqueta B «Pestaña Paralelos»; DESIGN.md, «Paralelos»):
    «Bandeja» (academic-load::pages.inbox) y «Paralelos» (la pantalla de Paralelos de Scheduling). Son dos pantallas con la misma
    modalidad, el mismo departamento y el mismo campus en la URL, así que son enlaces y no un tablist: la de la pantalla
    lleva `aria-current="page"`. La bandeja solo conoce Paralelos por el nombre de su ruta (Route::has): AcademicLoad no
    depende de Scheduling.

    `$tabs`: ['inbox' => url, 'parallels' => url o null]; sin la de Paralelos no se pinta nada. `$active`: 'inbox' o
    'parallels'. `$parallelsCount` (opcional): las materias por armar, que solo sabe la pestaña de Paralelos.
--}}
@if (($tabs['parallels'] ?? null) !== null)
    <nav aria-label="Bandeja del departamento" class="tabs tabs-border flex-nowrap overflow-x-auto overflow-y-hidden border-b border-base-300 [scrollbar-width:none]" data-testid="inbox-tabs">
        @foreach (['inbox' => ['Bandeja', 'o-inbox-arrow-down'], 'parallels' => ['Paralelos', 'o-arrows-pointing-in']] as $key => [$label, $icon])
            <a href="{{ $tabs[$key] }}" wire:navigate @if ($active === $key) aria-current="page" @endif data-testid="tab-{{ $key }}"
                @class(['tab -mb-px h-11 shrink-0 gap-2 px-3', 'tab-active font-bold' => $active === $key, 'text-neutral! hover:text-base-content!' => $active !== $key])>
                <x-icon :name="$icon" class="h-4 w-4" aria-hidden="true" />{{ $label }}
                @if ($key === 'parallels' && ($parallelsCount ?? null) !== null)
                    <span class="font-mono text-[12.5px] font-normal text-neutral" data-testid="tab-parallels-count">{{ $parallelsCount }} por armar</span>
                @endif
            </a>
        @endforeach
    </nav>
@endif
