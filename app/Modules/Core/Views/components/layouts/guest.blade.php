@props(['title' => null, 'bare' => false])

{{-- Layout mínimo para las pantallas de acceso (login y cambio de contraseña). El layout de la aplicación es resources/views/layouts/app.blade.php.
     Con `bare`, la pantalla ocupa todo el ancho y va siempre en claro (el login G, tarea 1.17); sin él, el contenido va centrado. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($bare) data-theme="nexus" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @stack('head')

        @livewireStyles
    </head>
    <body @class(['min-h-screen font-sans antialiased', 'bg-base-100' => $bare, 'bg-base-200' => ! $bare])>
        @if ($bare)
            {{ $slot }}
        @else
            <main class="flex min-h-screen items-center justify-center p-4">
                {{ $slot }}
            </main>
        @endif

        @livewireScripts
    </body>
</html>
