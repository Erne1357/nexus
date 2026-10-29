@props(['title' => null])

{{-- Layout mínimo para las pantallas de acceso (login y cambio de contraseña). El layout de la aplicación es resources/views/layouts/app.blade.php. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="min-h-screen bg-base-200 font-sans antialiased">
        <main class="flex min-h-screen items-center justify-center p-4">
            {{ $slot }}
        </main>

        @livewireScripts
    </body>
</html>
