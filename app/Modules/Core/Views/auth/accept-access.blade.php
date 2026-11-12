{{--
    Activar el acceso con la invitación (AC-08). Con `$action`, el enlace sirve: muestra el usuario y el botón, que
    manda el formulario al mismo enlace firmado. Sin él, el enlace no sirve y la página lo dice sin decir por qué.
--}}
<x-core::layouts.guest title="Activar tu acceso">
    <x-card :title="$action ? 'Activa tu acceso a Nexus' : 'Este enlace ya no sirve'" shadow separator class="w-full max-w-sm">
        @if ($action)
            <p class="mb-4">Tu usuario es <span class="font-mono font-semibold">{{ $username }}</span>. Al activarlo entras a Nexus y lo primero será elegir tu propia contraseña.</p>

            <form method="POST" action="{{ $action }}">
                @csrf
                <x-button label="Activar mi acceso" type="submit" icon="o-check" class="btn-primary w-full" />
            </form>
        @else
            <p class="mb-4">Puede que haya vencido, que ya se haya usado o que te hayan mandado uno más nuevo. Pide a tu jefe de departamento que te reenvíe la invitación.</p>

            <x-button label="Ir a iniciar sesión" :link="route('login')" class="btn-outline w-full" />
        @endif
    </x-card>
</x-core::layouts.guest>
