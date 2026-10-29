{{--
    Layout de la aplicación para toda página MFC (config/livewire.php, component_layout). Con sesión,
    el shell de DESIGN.md: la banda con la liebre, la ruta y la credencial (core::components.band), el
    menú que se transforma (core::components.navigation) y el contenido a su derecha. Sin sesión, solo
    el contenido. Los datos salen de los componentes de Core, no de consultas aquí.

    <body> lleva el estado del shell, que cambia el componente Alpine nexusShell (resources/js/app.js):
    data-menu="closed|open". También declara la geometría por ancho, que la banda, el menú y el
    contenido leen: --shell-top (banda y franja), --menu-w (el menú cerrado), --panel-w (el menú
    abierto) y --panel-clip (la cortina recogida). Escritorio desde 1200 px, tableta desde 768 px.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    @auth
        <body
            x-data="nexusShell"
            x-on:keydown.window="onKeydown($event)"
            data-menu="closed"
            class="min-h-screen bg-base-200 font-sans text-base-content antialiased [--menu-w:288px] [--panel-clip:inset(0_calc(100%_-_var(--menu-w))_0_0)] [--panel-w:min(1480px,calc(100vw_-_80px))] [--shell-top:67px] max-desktop:[--menu-w:64px] max-desktop:[--panel-w:calc(100vw_-_48px)] max-md:[--menu-w:0px] max-md:[--panel-clip:inset(0_0_100%_0)] max-md:[--panel-w:100vw] max-md:[--shell-top:59px]"
        >
            <livewire:core::components.band :title="$title ?? null" />

            <livewire:core::components.navigation />

            <main
                id="nexus-content"
                class="ms-(--menu-w) min-h-[calc(100vh_-_var(--shell-top))] max-w-[calc(1680px_+_4rem)] border-s border-base-300 px-8 pt-6.5 pb-10 max-desktop:px-6 max-desktop:pt-6 max-md:border-s-0 max-md:px-4 max-md:pt-[18px] max-md:pb-8"
            >
                {{ $slot }}
            </main>

            <x-toast />

            @livewireScripts
        </body>
    @else
        <body class="min-h-screen bg-base-200 font-sans antialiased">
            <main class="p-5">
                {{ $slot }}
            </main>

            <x-toast />

            @livewireScripts
        </body>
    @endauth
</html>
