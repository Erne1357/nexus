{{--
    Layout de la aplicación para toda página MFC (config/livewire.php, component_layout). Con sesión:
    topbar con el selector de campus y periodo, el tema y el menú de usuario; barra lateral con la
    navegación por rol (colapsable en móvil). Sin sesión, solo el contenido. Los datos salen de los
    componentes de Core (core::components.*), no de consultas aquí.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="min-h-screen bg-base-200 font-sans antialiased">
        @auth
            <x-nav sticky full-width>
                <x-slot:brand class="gap-3">
                    <label for="main-drawer" class="cursor-pointer lg:hidden" aria-label="Abrir el menú">
                        <x-icon name="o-bars-3" />
                    </label>

                    <a href="{{ route('home') }}" wire:navigate class="text-lg font-semibold text-primary max-sm:hidden">
                        {{ config('app.name') }}
                    </a>
                </x-slot:brand>

                <x-slot:actions>
                    <livewire:core::components.context-switcher />

                    <x-theme-toggle
                        light-theme="nexus"
                        dark-theme="nexus-dark"
                        class="btn btn-circle btn-ghost"
                        title="Cambiar entre tema claro y oscuro"
                        data-testid="theme-toggle"
                    />

                    <x-dropdown right>
                        <x-slot:trigger>
                            <x-button :label="auth()->user()->name" icon="o-user-circle" class="btn-ghost" responsive />
                        </x-slot:trigger>

                        <x-menu-item title="Cambiar contraseña" icon="o-key" :link="route('user-password.edit')" no-wire-navigate />

                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf

                                <button type="submit" class="flex w-full items-center gap-2">
                                    <x-icon name="o-arrow-right-start-on-rectangle" />
                                    Cerrar sesión
                                </button>
                            </form>
                        </li>
                    </x-dropdown>
                </x-slot:actions>
            </x-nav>

            <x-main with-nav full-width>
                <x-slot:sidebar drawer="main-drawer" class="border-e border-base-content/10 bg-base-100">
                    <livewire:core::components.navigation />
                </x-slot:sidebar>

                <x-slot:content>
                    {{ $slot }}
                </x-slot:content>
            </x-main>
        @else
            <main class="p-5">
                {{ $slot }}
            </main>
        @endauth

        <x-toast />

        @livewireScripts
    </body>
</html>
