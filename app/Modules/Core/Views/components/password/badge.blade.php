{{--
    La credencial de las pantallas de contraseña (tarea 1.30; DESIGN.md, "Pantallas de contraseña"). Nace provisional:
    la cabecera en gris polvo, la liebre en un cuadro apagado, la marca punteada y la etiqueta «Provisional · n de N».
    Con cada requisito que se cumple (`$requirements`, que revisa `nexusPassword` en resources/js/app.js) se imprime de
    izquierda a derecha la cabecera oficial (azul espacio, el cuadro rojo con la liebre y la marca llena) y la franja
    roja; con todos, queda oficial. Con `printed` ya está impresa y no revisa nada (el acuse, pedir el enlace); con `void` se
    queda provisional, con la etiqueta «Sin validez» (el enlace que ya no sirve).
    `$credential` es la ficha de quien la usa (CredentialQuery o solo el usuario); `$heading` es el h1 de la página. El
    slot `receipt` es el talón que cuelga debajo (core::password.receipt). Sin JavaScript se queda provisional y el
    formulario se manda como siempre.
--}}
@props(['credential' => null, 'requirements' => [], 'printed' => false, 'void' => false, 'heading', 'lead' => null, 'receipt' => null])

@php($settle = 'transition-[clip-path,scale] duration-[550ms] ease-open motion-reduce:transition-none')

<div
    @unless ($printed || $void)
        x-data="nexusPassword({ rules: @js(array_keys($requirements)) })"
        x-bind:style="{ '--printed': printed }"
    @endunless
    style="--printed: {{ $printed ? 1 : 0 }}"
    data-password-badge
    {{ $attributes->class('relative') }}
>
    <article
        aria-labelledby="password-badge-title"
        @class([
            '@container relative z-[1] flex flex-col overflow-hidden bg-base-100 shadow-[0_18px_44px_rgb(9_45_72/0.22),0_2px_6px_rgb(9_45_72/0.12)]',
            'rounded-[12px]' => $receipt === null,
            'rounded-t-[12px]' => $receipt !== null,
        ])
    >
        <header class="relative overflow-hidden bg-secondary">
            <span aria-hidden="true" class="absolute top-[9px] left-1/2 z-20 -ml-[29px] h-[9px] w-[58px] rounded-full bg-base-100"></span>

            {{-- Provisional --}}
            <div aria-hidden="true" class="flex items-center gap-4 px-[22px] pt-7 pb-[18px] max-sm:px-4">
                <span class="grid aspect-[9/8] w-[clamp(62px,15.5cqw,80px)] flex-none place-items-center bg-neutral/25">
                    <span class="block h-[78%] w-[64%] bg-white mask-(--hare) mask-contain mask-center mask-no-repeat"></span>
                </span>
                <svg viewBox="-52 -52 2873 835" class="block h-auto w-[min(52cqw,300px)] overflow-visible" focusable="false">
                    <use href="#nexus-marca-letras" fill="none" class="stroke-secondary-muted" stroke-width="20" stroke-dasharray="38 26" stroke-linejoin="round" />
                </svg>
            </div>

            @if ($void)
                <span data-provisional class="absolute top-[9px] right-3 rounded border-[1.5px] border-dashed border-secondary-muted px-2 py-0.5 text-[11.5px] font-bold tracking-[0.09em] text-secondary-muted uppercase">Sin validez</span>
            @elseif (! $printed)
                <span x-show="! ready" data-provisional class="absolute top-[9px] right-3 rounded border-[1.5px] border-dashed border-secondary-muted px-2 py-0.5 text-[11.5px] font-bold tracking-[0.09em] text-secondary-muted uppercase">
                    <span class="max-sm:hidden">Provisional · </span><span x-text="count">0</span> de {{ count($requirements) }}
                </span>
            @endif

            {{-- Oficial: se imprime de izquierda a derecha --}}
            <div aria-hidden="true" @class(['absolute inset-0 flex items-center gap-4 bg-primary px-[22px] pt-7 pb-[18px] [clip-path:inset(0_calc((1_-_var(--printed))*100%)_0_0)] max-sm:px-4', $settle])>
                <span class="grid aspect-[9/8] w-[clamp(62px,15.5cqw,80px)] flex-none place-items-center bg-accent">
                    <span class="block h-[78%] w-[64%] bg-white mask-(--hare) mask-contain mask-center mask-no-repeat"></span>
                </span>
                <x-core::login.mark class="w-[min(52cqw,300px)]" />
            </div>
        </header>
        <div aria-hidden="true" class="relative h-[3px] flex-none bg-secondary">
            <span @class(['absolute inset-0 origin-left scale-x-(--printed) bg-accent', $settle])></span>
        </div>

        <div class="flex flex-col gap-4 px-[22px] pt-[18px] pb-[22px] max-sm:px-4">
            @if ($credential)
                <div class="grid grid-cols-[50px_minmax(0,1fr)] items-center gap-3.5">
                    <div aria-hidden="true" class="grid aspect-[4/5] place-items-center rounded-lg bg-secondary text-[19px] font-bold tracking-[-0.02em] text-secondary-content">
                        @if (($credential['initials'] ?? '') !== '')
                            {{ $credential['initials'] }}
                        @else
                            <span class="block h-[70%] w-[70%] bg-base-100 mask-(--hare) mask-contain mask-center mask-no-repeat"></span>
                        @endif
                    </div>
                    <dl class="grid min-w-0 grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] gap-x-3.5 gap-y-1">
                        @if (($credential['name'] ?? '') !== '')
                            <div class="col-span-2 min-w-0">
                                <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Nombre</dt>
                                <dd class="truncate text-base font-bold">{{ $credential['name'] }}</dd>
                            </div>
                        @endif
                        <div class="min-w-0">
                            <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Usuario</dt>
                            <dd class="truncate font-mono text-[14.5px]">{{ $credential['username'] }}</dd>
                        </div>
                        @if (($credential['term'] ?? null) !== null)
                            <div class="min-w-0">
                                <dt class="text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">Periodo</dt>
                                <dd class="truncate text-[14.5px]">{{ $credential['term'] }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif

            <div>
                <h1 id="password-badge-title" class="text-[21px] leading-tight font-bold tracking-[-0.01em] text-balance">{{ $heading }}</h1>
                @if ($lead)
                    <p class="mt-1 text-[14.5px] text-pretty text-neutral">{{ $lead }}</p>
                @endif
            </div>

            {{ $slot }}
        </div>
    </article>

    {{ $receipt }}
</div>
