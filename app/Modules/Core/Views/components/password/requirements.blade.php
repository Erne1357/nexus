{{-- Los requisitos de la contraseña nueva, con su estado como texto e icono además del color (DESIGN.md, "El color nunca
va solo"). Los revisa `nexusPassword` (resources/js/app.js) al escribir; sin JavaScript se ven todos como pendientes y
los valida el servidor. --}}
@props(['requirements'])

<ul aria-label="Requisitos de la contraseña" class="grid grid-cols-2 gap-x-3 gap-y-1.5 max-[360px]:grid-cols-1">
    @foreach ($requirements as $key => $label)
        <li data-requirement="{{ $key }}" class="flex items-center gap-[7px] text-[13.5px] text-neutral" x-bind:class="met.{{ $key }} && 'text-success'">
            <x-icon name="o-check" class="h-4 w-4 flex-none" x-show="met.{{ $key }}" x-cloak />
            <span x-show="! met.{{ $key }}" aria-hidden="true" class="grid h-4 w-4 flex-none place-items-center"><span class="h-[11px] w-[11px] rounded-full border-[1.5px] border-current"></span></span>
            <span>{{ $label }}</span>
            <span class="sr-only" x-text="met.{{ $key }} ? 'cumplido' : 'pendiente'">pendiente</span>
        </li>
    @endforeach
</ul>
<p class="sr-only" aria-live="polite" x-text="`${count} de ${total} requisitos cumplidos`"></p>
