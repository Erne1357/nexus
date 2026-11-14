{{-- La forma de todo correo de Nexus: la banda con la marca, el cuerpo en una tarjeta blanca y, al pie, quién lo manda.
Lo publicó la tarea 1.21 desde Laravel para darle la marca Nexus/ITCJ y el tono de tú. --}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ config('app.name') }}, el sistema académico del Instituto Tecnológico de Ciudad Juárez.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
