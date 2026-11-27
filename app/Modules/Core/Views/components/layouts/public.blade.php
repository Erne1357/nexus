@props(['title' => null, 'trail' => null])

{{--
    Layout de las páginas públicas de Core (el aviso de privacidad, tarea 1.65): se leen sin sesión, así que no llevan
    el shell ni el menú. La banda es la de la consulta publicada (DESIGN.md, «Consulta publicada») sin selectores: la
    liebre en su cuadro rojo, «Nexus» y la ruta (`trail`), y a la derecha «Iniciar sesión» o, con sesión, el nombre e
    «Ir a Nexus»; debajo, la franja roja de 3 px. El tema es el del sistema. Al pie, «Instituto Tecnológico de Ciudad
    Juárez».
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-base-200 font-sans text-base-content antialiased">
        <div class="sticky top-0 z-30 text-sm/[1.45]">
            <header class="flex h-16 items-center gap-[18px] bg-band pe-5 text-band-content max-desktop:gap-3.5 max-md:h-14 max-md:gap-2 max-md:pe-2">
                <a
                    href="{{ route('home') }}"
                    aria-label="Nexus, ir al inicio"
                    class="grid h-16 w-[72px] flex-none place-items-center bg-accent focus-visible:outline-2 focus-visible:-outline-offset-7 focus-visible:outline-accent-content max-md:h-14 max-md:w-14"
                >
                    <span
                        aria-hidden="true"
                        style="--hare: url('{{ Vite::asset('resources/images/liebre-512.png') }}')"
                        class="block size-[46px] bg-accent-content mask-(--hare) mask-contain mask-center mask-no-repeat max-md:size-[38px]"
                    ></span>
                </a>

                <span class="text-xl font-bold tracking-[-0.01em] max-md:text-lg">Nexus</span>

                @if ($trail)
                    <nav aria-label="Ruta" class="flex min-w-0 items-center gap-2 text-[15px] whitespace-nowrap text-band-muted max-md:hidden">
                        <span aria-current="page" class="truncate font-medium text-band-content">{{ $trail }}</span>
                    </nav>
                @endif

                <span class="flex-1"></span>

                @auth
                    <div class="flex min-w-0 items-center gap-3 max-md:gap-1">
                        <span class="truncate font-bold max-md:sr-only">{{ auth()->user()->name }}</span>
                        <a href="{{ route('home') }}" class="btn btn-sm h-10 border-band-content/25 bg-transparent whitespace-nowrap text-band-content shadow-none hover:bg-band-content/10 max-md:size-11 max-md:px-0">
                            <x-icon name="o-arrow-right-end-on-rectangle" class="h-[18px] w-[18px]" />
                            <span class="max-md:sr-only">Ir a Nexus</span>
                        </a>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="btn btn-sm h-10 border-band-content/25 bg-transparent whitespace-nowrap text-band-content shadow-none hover:bg-band-content/10 max-md:size-11 max-md:px-0">
                        <x-icon name="o-user" class="h-[18px] w-[18px]" />
                        <span class="max-md:sr-only">Iniciar sesión</span>
                    </a>
                @endauth
            </header>

            <div aria-hidden="true" class="h-[3px] bg-accent"></div>
        </div>

        <main id="nexus-content" class="mx-auto min-h-[calc(100vh_-_200px)] max-w-[calc(1680px_+_4rem)] px-8 pt-6.5 pb-12 max-desktop:px-6 max-desktop:pt-6 max-md:px-4 max-md:pt-[18px] max-md:pb-8">
            {{ $slot }}
        </main>

        <footer class="mx-auto max-w-[calc(1680px_+_4rem)] border-t border-base-300 px-8 py-5 text-sm text-neutral max-desktop:px-6 max-md:px-4">
            Instituto Tecnológico de Ciudad Juárez
        </footer>
    </body>
</html>
