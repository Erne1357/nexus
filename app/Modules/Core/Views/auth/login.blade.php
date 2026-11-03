{{--
    Pantalla de acceso G (tarea 1.17; DESIGN.md, "Pantalla de acceso"). A la derecha, los logos del TecNM y del ITCJ,
    la marca Nexus y el formulario; a la izquierda, los avisos del Tec sobre su foto (LG-01) y el botón de atajos (LG-02).
    En celular el formulario va primero y los avisos debajo. El movimiento vive en resources/js/login.js (nexusLogin).
    $notices y $shortcuts vienen de LoginScreenQuery (FortifyServiceProvider).
--}}
@php($fallbackPhoto = Vite::asset('resources/images/login/edificio-itcj.webp'))

<x-core::layouts.guest title="Iniciar sesión" bare>
    @push('head')
        @vite('resources/js/login.js')
    @endpush

    <div
        x-data="nexusLogin({ total: {{ count($notices) }} })"
        class="grid min-h-svh lg:grid-cols-[minmax(0,1fr)_clamp(460px,42vw,720px)]"
    >
        {{-- Entrada --}}
        <main
            class="order-1 mx-auto flex w-full max-w-[560px] flex-col bg-base-100 px-4 pt-6 pb-7 lg:order-2 lg:min-h-svh lg:max-w-none lg:px-[clamp(28px,3.4vw,56px)] lg:pt-[clamp(20px,3vh,32px)] lg:pb-[clamp(28px,5vh,56px)]"
        >
            <div data-login-logos class="flex items-center justify-between gap-5">
                <span
                    role="img"
                    aria-label="Tecnológico Nacional de México"
                    class="block aspect-[469/200] h-[58px] bg-primary [mask:var(--logo)_center/contain_no-repeat] lg:h-[clamp(55px,7.9vh,77px)]"
                    style="--logo: url('{{ Vite::asset('resources/images/login/tecnm.webp') }}')"
                ></span>
                <span
                    role="img"
                    aria-label="Instituto Tecnológico de Ciudad Juárez"
                    class="block aspect-[465/220] h-[66px] bg-accent [mask:var(--logo)_center/contain_no-repeat] lg:h-[clamp(61px,8.8vh,84px)]"
                    style="--logo: url('{{ Vite::asset('resources/images/login/itcj.webp') }}')"
                ></span>
            </div>

            <h1 class="mt-3 mb-2 flex justify-center lg:my-auto lg:py-[clamp(14px,2.6vh,30px)]">
                <span class="sr-only">Nexus</span>
                <x-core::login.mark data-login-mark class="w-[min(76vw,300px)] lg:w-[clamp(270px,calc(22vh+170px),430px)]" />
            </h1>

            {{-- El formulario --}}
            <section x-show="view === 'login'" data-login-view="login" aria-label="Iniciar sesión" class="mt-5 lg:mt-[clamp(20px,3.6vh,36px)]">
                <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4">
                    @csrf

                    <x-input
                        label="Usuario"
                        name="username"
                        :value="old('username')"
                        error-field="username"
                        placeholder="Control o empleado"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        autofocus
                        required
                    />

                    <x-password
                        label="Contraseña"
                        name="password"
                        error-field="password"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        right
                        required
                    />

                    <div class="flex flex-wrap items-center justify-between gap-x-3.5 gap-y-2">
                        <button
                            type="button"
                            class="link text-[14.5px] underline-offset-3"
                            x-on:click="forgotOpen = ! forgotOpen"
                            x-bind:aria-expanded="forgotOpen"
                            aria-controls="login-forgot"
                        >¿Olvidaste tu contraseña?</button>
                        <x-checkbox label="Recordarme" name="remember" value="1" :checked="(bool) old('remember')" />
                    </div>
                    <p id="login-forgot" x-show="forgotOpen" x-cloak class="-mt-1.5 text-[14.5px] text-neutral">
                        Pídele al administrador del sistema que la restablezca.
                    </p>

                    <x-button label="Entrar" type="submit" class="btn-accent mt-7 h-[50px] text-[16.5px]" />
                </form>
            </section>

            {{-- Los atajos (LG-02): caen en el lugar del formulario --}}
            @if ($shortcuts !== [])
                <section x-show="view === 'shortcuts'" x-cloak data-login-view="shortcuts" aria-labelledby="login-shortcuts-title" class="mt-5 lg:mt-[clamp(20px,3.6vh,36px)]">
                    <h2 id="login-shortcuts-title" tabindex="-1" data-drop class="text-[clamp(21px,1.5vw,25px)] font-bold outline-none">Atajos</h2>
                    <ul class="mt-4 border-t border-base-300">
                        @foreach ($shortcuts as $shortcut)
                            <li data-drop class="border-b border-base-300">
                                <a
                                    href="{{ $shortcut['href'] }}"
                                    @if ($shortcut['download']) download @else target="_blank" rel="noopener" @endif
                                    class="flex items-center gap-3.5 rounded-box px-2 py-3.5 hover:bg-base-200"
                                >
                                    <span class="grid size-[42px] shrink-0 place-items-center rounded-box bg-base-200 text-primary">
                                        <x-icon :name="$shortcut['download'] ? 'o-arrow-down-tray' : 'o-arrow-top-right-on-square'" class="h-5 w-5" />
                                    </span>
                                    <span class="flex min-w-0 flex-1 flex-col">
                                        <span class="font-bold">{{ $shortcut['title'] }}</span>
                                        @if ($shortcut['description'])
                                            <span class="text-sm text-neutral">{{ $shortcut['description'] }}</span>
                                        @endif
                                    </span>
                                    @unless ($shortcut['download'])
                                        <span class="sr-only">(se abre en otra pestaña)</span>
                                    @endunless
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <button type="button" data-drop class="btn btn-ghost -ml-2 mt-5 gap-2 font-normal" x-on:click="closeShortcuts">
                        <x-icon name="o-arrow-left" class="h-[18px] w-[18px]" />Iniciar sesión
                    </button>
                </section>
            @endif

            <div class="hidden lg:mt-auto lg:block"></div>
        </main>

        {{-- Avisos (LG-01) sobre la foto; sin avisos, la foto del edificio sola --}}
        <h2 class="order-2 mx-auto mt-2 mb-2.5 w-full max-w-[560px] px-4 text-[19px] font-bold lg:hidden">Avisos</h2>
        <section
            aria-roledescription="carrusel"
            aria-label="Avisos"
            data-login-photos
            class="@container relative order-3 mx-4 mb-16 overflow-hidden rounded-[14px] border border-base-300 bg-base-100 shadow-[0_2px_4px_rgb(0_0_0/0.08),0_18px_40px_-18px_rgb(0_0_0/0.42)] sm:mx-auto sm:w-[calc(100%-32px)] sm:max-w-[560px] lg:sticky lg:top-0 lg:order-1 lg:m-0 lg:h-svh lg:w-auto lg:max-w-none lg:rounded-none lg:border-0 lg:shadow-none"
            x-on:mouseenter="hold(true)"
            x-on:mouseleave="hold(false)"
            x-on:focusin="hold(true)"
            x-on:focusout="hold($el.contains($event.relatedTarget))"
        >
            <div class="grid lg:absolute lg:inset-0 lg:block" aria-live="off" x-ref="slides">
                @forelse ($notices as $index => $notice)
                    <article
                        x-show="current === {{ $index }}"
                        @if ($index > 0) x-cloak @endif
                        x-transition.opacity.duration.700ms
                        role="group"
                        aria-roledescription="aviso"
                        aria-label="{{ $index + 1 }} de {{ count($notices) }}"
                        class="col-start-1 row-start-1 flex flex-col lg:absolute lg:inset-0"
                    >
                        @if ($notice['image_url'])
                            <div class="aspect-[16/11] bg-cover bg-center lg:absolute lg:inset-0 lg:aspect-auto" style="background-image: url('{{ $notice['image_url'] }}')" aria-hidden="true"></div>
                        @else
                            <div class="relative aspect-[16/11] overflow-hidden bg-primary lg:absolute lg:inset-0 lg:aspect-auto" aria-hidden="true">
                                <x-core::login.blade class="absolute top-[10%] -right-[12%] w-[118%] -rotate-[8deg]" />
                            </div>
                        @endif
                        <div
                            @class([
                                'px-5 pt-4 pb-4 lg:absolute lg:top-[clamp(24px,3.4vw,56px)] lg:right-[clamp(24px,3.4vw,56px)] lg:max-w-[calc(100%-2*clamp(24px,3.4vw,56px))] lg:rounded-[12px] lg:px-[clamp(16px,1.6vw,24px)] lg:py-[clamp(12px,1.1vw,16px)] lg:shadow-[0_2px_4px_rgb(0_0_0/0.08),0_18px_40px_-18px_rgb(0_0_0/0.42)] lg:backdrop-blur-md',
                                'lg:bg-[rgb(236_241_246/0.5)]' => $notice['image_url'],
                                'lg:bg-white/12 lg:text-white' => ! $notice['image_url'],
                            ])
                        >
                            <h3 class="text-[clamp(22px,6vw,28px)] leading-[1.12] font-bold tracking-[-0.015em] text-balance lg:text-[clamp(19px,1.6vw,26px)]">
                                @if ($notice['link_url'])
                                    <a href="{{ $notice['link_url'] }}" target="_blank" rel="noopener" class="hover:underline">{{ $notice['title'] }}<span class="sr-only"> (se abre en otra pestaña)</span></a>
                                @else
                                    {{ $notice['title'] }}
                                @endif
                            </h3>
                        </div>
                    </article>
                @empty
                    <div class="aspect-[16/11] bg-cover bg-center lg:absolute lg:inset-0 lg:aspect-auto" style="background-image: url('{{ $fallbackPhoto }}')" aria-hidden="true"></div>
                @endforelse
            </div>

            @if (count($notices) > 1)
                <div
                    class="mb-3.5 ml-3 inline-flex items-center gap-0.5 rounded-full px-2.5 py-1 lg:absolute lg:top-[calc(clamp(24px,3.4vw,56px)+clamp(60px,4.6vw,70px))] lg:right-[clamp(24px,3.4vw,56px)] lg:z-10 lg:m-0 lg:bg-[rgb(236_241_246/0.5)] lg:shadow-[0_2px_4px_rgb(0_0_0/0.08),0_18px_40px_-18px_rgb(0_0_0/0.42)] lg:backdrop-blur-md"
                >
                    @foreach ($notices as $index => $notice)
                        <button
                            type="button"
                            class="group grid h-9 place-items-center transition-[width] duration-300"
                            x-bind:class="current === {{ $index }} ? 'w-[46px]' : 'w-6'"
                            x-bind:aria-current="current === {{ $index }}"
                            aria-label="Aviso {{ $index + 1 }} de {{ count($notices) }}"
                            x-on:click="go({{ $index }})"
                        >
                            <i
                                class="block h-2 overflow-hidden rounded-full transition-[width,background-color] duration-300"
                                x-bind:class="current === {{ $index }} ? 'w-[34px] bg-[#c9d4de]' : 'w-2 bg-[#8c8a8b] group-hover:bg-primary'"
                            ><span data-progress class="block h-full w-full origin-left scale-x-0 bg-primary"></span></i>
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($shortcuts !== [])
                <button
                    type="button"
                    x-ref="shortcutsButton"
                    x-on:click="openShortcuts"
                    class="absolute top-[calc(100cqw*11/16-60px)] left-3 z-10 flex min-h-12 items-center gap-3 rounded-[12px] bg-white/90 px-3.5 py-2 text-primary shadow-[0_2px_4px_rgb(0_0_0/0.08),0_18px_40px_-18px_rgb(0_0_0/0.42)] backdrop-blur-md transition hover:-translate-y-px hover:bg-white lg:top-auto lg:bottom-[max(24px,calc(clamp(48px,17vh,170px)-58px))] lg:left-[clamp(24px,3.4vw,56px)] lg:min-h-[52px]"
                >
                    <x-icon name="o-squares-2x2" class="h-5 w-5" />
                    <span class="font-bold">Atajos</span>
                    <x-icon name="o-chevron-right" class="h-[18px] w-[18px]" />
                </button>
            @endif
        </section>
    </div>
</x-core::layouts.guest>
