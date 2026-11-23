{{-- El talón que cuelga bajo la credencial (tarea 1.30; DESIGN.md, "Pantallas de contraseña"): unido por una perforación,
se desprende y gira (0.7 s). Con `$receipt` (PasswordReceiptQuery) es el acuse del cambio: el folio de la bitácora, la
fecha y la hora, nunca la contraseña. Sin él, lleva `$title` y el slot (la solicitud del enlace por correo). `$notch` es
el color del fondo, que asoma por las muescas. --}}
@props(['receipt' => null, 'title' => 'Acuse del cambio', 'notch' => 'bg-base-100'])

<section
    aria-labelledby="password-receipt-title"
    data-password-receipt
    class="relative -mt-px rounded-b-[12px] bg-base-100 px-[22px] pt-[18px] pb-[18px] shadow-[0_18px_44px_rgb(9_45_72/0.22),0_2px_6px_rgb(9_45_72/0.12)] max-sm:px-4 motion-safe:animate-nexus-tear motion-reduce:translate-x-[10px] motion-reduce:translate-y-6 motion-reduce:rotate-3"
>
    <span aria-hidden="true" class="absolute inset-x-3.5 top-0 border-t-2 border-dashed border-base-300"></span>
    <span aria-hidden="true" @class(['absolute -top-[9px] -left-[9px] h-[18px] w-[18px] rounded-full', $notch])></span>
    <span aria-hidden="true" @class(['absolute -top-[9px] -right-[9px] h-[18px] w-[18px] rounded-full', $notch])></span>

    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h2 id="password-receipt-title" class="text-[11.5px] font-bold tracking-[0.09em] text-neutral uppercase">{{ $title }}</h2>
        @if ($receipt)
            <p class="font-mono text-[13.5px] font-semibold">Folio {{ $receipt['folio'] }}</p>
        @endif
    </div>
    @if ($receipt)
        <p class="mt-2 text-[14.5px]">{{ $receipt['at'] }}</p>
        <p class="mt-1 text-[13.5px] text-pretty text-neutral">Quedó en la bitácora a tu nombre. El acuse no lleva tu contraseña.</p>
    @else
        {{ $slot }}
    @endif
</section>
