{{--
    La banda pública (DESIGN.md, «Consulta publicada»): la del shell sin buscador ni menú. La liebre en el cuadro rojo
    es la marca y lleva a la consulta; luego «Nexus» y la ruta «Horarios». A la derecha, los selectores de Campus y
    Periodo (cambiar uno recarga la consulta con ese contexto) y el acceso. Bajo 768 px mide 56 px: la liebre de 56,
    sin la ruta, y los selectores compactos. Bajo 480 px «Nexus» queda solo para lectores de pantalla, para que los
    selectores quepan completos, y bajo 380 px los selectores bajan a un segundo renglón de la banda, a lo ancho.
    Debajo, la franja roja de 3 px.
--}}
@php
    $selectClasses = 'select h-10 w-auto min-w-0 max-w-full border-band-content/25 bg-band-content/8 text-band-content text-sm [&>option]:bg-base-100 [&>option]:text-base-content max-md:h-11 max-md:max-w-36 max-md:truncate max-md:ps-2 max-md:pe-8 max-md:text-[13px]';
@endphp
<div class="sticky top-0 z-30 text-sm/[1.45]">
    <header class="flex h-16 items-center gap-[18px] bg-band pe-5 text-band-content max-desktop:gap-3.5 max-md:h-auto max-md:min-h-14 max-md:gap-2 max-md:pe-2 max-[379px]:flex-wrap max-[379px]:pb-2">
        <a
            href="{{ route('published.index') }}"
            wire:navigate
            data-testid="hare"
            aria-label="Nexus, inicio de Horarios"
            class="grid h-16 w-[72px] flex-none place-items-center bg-accent focus-visible:outline-2 focus-visible:-outline-offset-7 focus-visible:outline-accent-content max-md:h-14 max-md:w-14"
        >
            <span
                aria-hidden="true"
                style="--hare: url('{{ Vite::asset('resources/images/liebre-512.png') }}')"
                class="block size-[46px] bg-accent-content mask-(--hare) mask-contain mask-center mask-no-repeat max-md:size-[38px]"
            ></span>
        </a>

        <span class="text-xl font-bold tracking-[-0.01em] max-md:text-lg max-[479px]:sr-only">Nexus</span>

        <nav aria-label="Ruta" class="flex min-w-0 items-center gap-2 text-[15px] whitespace-nowrap text-band-muted max-md:hidden">
            <span aria-current="page" class="truncate font-medium text-band-content">Horarios</span>
        </nav>

        <span class="flex-1"></span>

        @if ($terms !== [])
            <div class="flex min-w-0 items-center gap-2 max-md:gap-1.5 max-[379px]:order-last max-[379px]:basis-full max-[379px]:ps-2 max-[379px]:[&>select]:max-w-none max-[379px]:[&>select]:flex-1">
                <select wire:model.live="campus" aria-label="Campus" data-testid="published-campus" class="{{ $selectClasses }}">
                    @foreach ($campuses as $option)
                        <option value="{{ $option['id'] }}" @selected($option['id'] === $campus)>{{ $option['name'] }}</option>
                    @endforeach
                </select>
                <select wire:model.live="periodo" aria-label="Periodo" data-testid="published-term" class="{{ $selectClasses }}">
                    @foreach ($terms as $option)
                        <option value="{{ $option['id'] }}" @selected($option['id'] === $periodo)>{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @auth
            <div class="flex min-w-0 items-center gap-3 max-md:gap-1">
                <span class="truncate font-bold max-md:sr-only" data-testid="published-user">{{ auth()->user()->name }}</span>
                <a href="{{ route('home') }}" data-testid="published-home" class="btn btn-sm h-10 border-band-content/25 bg-transparent whitespace-nowrap text-band-content shadow-none hover:bg-band-content/10 max-md:size-11 max-md:px-0">
                    <x-icon name="o-arrow-right-end-on-rectangle" class="h-[18px] w-[18px]" />
                    <span class="max-md:sr-only">Ir a Nexus</span>
                </a>
            </div>
        @else
            <a href="{{ route('login') }}" data-testid="published-login" class="btn btn-sm h-10 border-band-content/25 bg-transparent whitespace-nowrap text-band-content shadow-none hover:bg-band-content/10 max-md:size-11 max-md:px-0">
                <x-icon name="o-user" class="h-[18px] w-[18px]" />
                <span class="max-md:sr-only">Iniciar sesión</span>
            </a>
        @endauth
    </header>

    <div aria-hidden="true" class="h-[3px] bg-accent"></div>
</div>
