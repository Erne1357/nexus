{{--
    Pantalla de acceso con credencial (tarea 1.23, sobre el login G de la 1.17; DESIGN.md, "Pantalla de acceso"). A la
    derecha, los logos en placas (core::login.plates) y la credencial que cuelga de su cinta: la cabecera azul con la
    liebre y la marca, la ficha vacía de quien entra y el formulario; su reverso son los atajos (LG-02). A la izquierda,
    los avisos del Tec sobre su foto (LG-01) y el botón de atajos, que voltea la credencial. En celular la credencial va
    primero y los avisos debajo.

    El movimiento y el envío viven en resources/js/login.js (nexusLogin): el formulario se manda pidiendo JSON, la ficha
    se llena con la respuesta (LoginResponse) y la credencial se vuelve la banda antes de ir a la página. Sin
    JavaScript el formulario entra como siempre. $notices y $shortcuts vienen de LoginScreenQuery (FortifyServiceProvider).
--}}
@php
    $fallbackPhoto = Vite::asset('resources/images/login/edificio-itcj.webp');
    $hare = Vite::asset('resources/images/liebre-512.png');
    $hasShortcuts = $shortcuts !== [];
@endphp

<x-core::layouts.guest title="Iniciar sesión" bare>
    @push('head')
        @vite('resources/js/login.js')
    @endpush

    <div
        x-data="nexusLogin({ total: {{ count($notices) }} })"
        class="grid min-h-svh lg:grid-cols-[minmax(0,1fr)_clamp(460px,42vw,720px)]"
        style="--hare: url('{{ $hare }}')"
    >
        {{-- La credencial --}}
        <main
            class="order-1 mx-auto flex w-full max-w-[560px] flex-col bg-base-100 px-4 pt-6 pb-2 lg:order-2 lg:min-h-svh lg:max-w-none lg:px-[clamp(28px,3.4vw,56px)] lg:pt-[clamp(20px,3vh,32px)] lg:pb-[clamp(28px,5vh,56px)]"
        >
            <x-core::login.plates />

            <div class="flex flex-1 items-center justify-center pt-[26px] lg:pt-[clamp(28px,4.5vh,48px)]">
                <div x-ref="badge" data-login-badge class="relative w-full max-w-[460px] perspective-[1600px] lg:w-[min(clamp(400px,29vw,560px),100%)] lg:max-w-none">
                    {{-- La cinta sube hasta arriba de la pantalla; el broche la une a la credencial --}}
                    <span x-ref="strap" aria-hidden="true" class="absolute bottom-[calc(100%+8px)] left-1/2 -ml-1.5 h-screen w-3 bg-primary"></span>
                    <span x-ref="clip" aria-hidden="true" class="absolute -top-3.5 left-1/2 z-10 -ml-[15px] h-7 w-[30px]">
                        <span class="absolute inset-x-0 top-0 h-3.5 rounded-sm bg-neutral"></span>
                        <span class="absolute top-2.5 left-1/2 -ml-1.5 h-[18px] w-3 rounded-b-[3px] bg-neutral"></span>
                    </span>

                    <div x-ref="turn" class="grid transform-3d" x-bind:class="flipped && 'rotate-y-180'">
                        {{-- Frente: la ficha y el formulario --}}
                        <article
                            x-ref="front"
                            x-bind:inert="flipped"
                            aria-labelledby="login-badge-title"
                            class="@container col-start-1 row-start-1 flex flex-col overflow-hidden rounded-[12px] bg-base-100 shadow-[0_18px_44px_rgb(9_45_72/0.22),0_2px_6px_rgb(9_45_72/0.12)] backface-hidden"
                        >
                            <x-core::login.badge-header front />

                            <div class="flex flex-1 flex-col gap-[18px] px-[22px] pt-5 pb-6">
                                {{-- La ficha: vacía hasta que la persona entra --}}
                                <div
                                    x-ref="card"
                                    x-bind:aria-hidden="remembered ? 'false' : 'true'"
                                    aria-hidden="true"
                                    data-testid="login-person"
                                    x-bind:class="remembered ? 'grid-cols-[112px_minmax(0,1fr)] items-center' : 'grid-cols-[70px_minmax(0,1fr)] items-start'"
                                    class="grid grid-cols-[70px_minmax(0,1fr)] items-start gap-4"
                                >
                                    <div x-ref="photo" x-bind:class="remembered ? 'aspect-[3/4]' : 'aspect-[4/5]'" class="relative grid aspect-[4/5] place-items-center overflow-hidden rounded-lg border border-base-300 bg-base-200">
                                        <img x-show="credential.photo" x-cloak x-bind:src="credential.photo || null" alt="" data-testid="login-photo" class="absolute inset-0 size-full object-cover object-top" />
                                        <span data-ghost x-bind:class="revealed && 'opacity-0'" class="absolute inset-[16%_9%_10%] bg-secondary mask-(--hare) mask-contain mask-center mask-no-repeat"></span>
                                        <span data-value x-show="!credential.photo" x-text="credential.initials" x-bind:class="revealed ? 'opacity-100' : 'opacity-0'" class="relative text-[26px] font-bold tracking-[-0.02em] text-primary"></span>
                                    </div>
                                    <dl class="grid grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)] gap-x-3.5 gap-y-[7px]">
                                        @foreach ([
                                            ['label' => 'Nombre', 'key' => 'name', 'ghost' => 'w-[84%]', 'wide' => true, 'value' => 'text-[16.5px] font-bold', 'ref' => 'name'],
                                            ['label' => 'Puesto', 'key' => 'role', 'ghost' => 'w-[64%]', 'wide' => true, 'value' => '', 'ref' => 'role'],
                                            ['label' => 'Usuario', 'key' => 'username', 'ghost' => 'w-[70%]', 'wide' => false, 'value' => 'font-mono', 'ref' => null],
                                            ['label' => 'Periodo', 'key' => 'term', 'ghost' => 'w-[72%]', 'wide' => false, 'value' => '', 'ref' => null],
                                        ] as $field)
                                            <div @class(['min-w-0', 'col-span-2' => $field['wide']]) @if ($field['key'] === 'term') x-show="!remembered" @endif>
                                                <dt class="text-[11.5px] font-medium tracking-[.09em] text-neutral uppercase">{{ $field['label'] }}</dt>
                                                <dd class="relative mt-px min-h-[1.5em] text-[15px]">
                                                    <span data-ghost x-bind:class="revealed && 'opacity-0'" class="{{ $field['ghost'] }} absolute top-1/2 left-0 -mt-[.3em] h-[.6em] rounded-[2px] bg-secondary"></span>
                                                    <span
                                                        data-value
                                                        @if ($field['ref']) x-ref="{{ $field['ref'] }}" @endif
                                                        x-text="credential.{{ $field['key'] }}"
                                                        x-bind:class="revealed ? 'opacity-100' : 'opacity-0'"
                                                        class="{{ $field['value'] }} block truncate"
                                                    ></span>
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>

                                <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4" x-on:submit.prevent="submit">
                                    @csrf

                                    @if (session('status') === 'password-reset')
                                        <x-alert title="Tu contraseña quedó lista. Entra con ella." icon="o-check-circle" class="alert-success" />
                                    @endif

                                    <div role="alert" x-show="error" x-cloak class="alert alert-error alert-soft items-start text-[14px]">
                                        <x-icon name="o-exclamation-circle" class="h-5 w-5" />
                                        <span id="login-error" x-text="error"></span>
                                    </div>

                                    {{-- Con la persona recordada el usuario ya va en su ficha: el campo se esconde pero se manda --}}
                                    <div x-show="!remembered" x-bind:aria-hidden="remembered ? 'true' : 'false'">
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
                                    </div>

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
                                        <a href="{{ route('password.request') }}" class="link text-[14.5px] underline-offset-3">¿Olvidaste tu contraseña?</a>
                                        <x-checkbox label="Recordarme" name="remember" value="1" :checked="(bool) old('remember')" />
                                    </div>

                                    <div class="mt-2.5 flex items-center gap-3.5">
                                        <x-button type="submit" class="btn-accent h-[50px] flex-1 text-[16.5px]" x-bind:aria-busy="busy ? 'true' : 'false'">
                                            <span x-show="busy" x-cloak class="loading loading-spinner loading-sm"></span>
                                            <span x-text="busy ? 'Entrando…' : 'Entrar'">Entrar</span>
                                        </x-button>
                                        <button type="button" x-show="remembered" x-cloak x-on:click="forget" class="link shrink-0 text-[14.5px] underline-offset-3">¿No eres tú?</button>
                                    </div>
                                </form>
                            </div>
                        </article>

                        {{-- Reverso: los atajos (LG-02) --}}
                        @if ($hasShortcuts)
                            <article
                                id="login-shortcuts"
                                x-ref="back"
                                inert
                                x-bind:inert="!flipped"
                                aria-labelledby="login-shortcuts-title"
                                class="@container col-start-1 row-start-1 flex rotate-y-180 flex-col overflow-hidden rounded-[12px] bg-base-100 shadow-[0_18px_44px_rgb(9_45_72/0.22),0_2px_6px_rgb(9_45_72/0.12)] backface-hidden"
                            >
                                <x-core::login.badge-header />

                                <div class="flex flex-1 flex-col px-[22px] pt-5 pb-6">
                                    <h2 id="login-shortcuts-title" tabindex="-1" data-drop class="mb-3 text-[22px] font-bold outline-none">Atajos</h2>
                                    <ul class="border-t border-base-300">
                                        @foreach ($shortcuts as $shortcut)
                                            <li data-drop class="border-b border-base-300">
                                                <a
                                                    href="{{ $shortcut['href'] }}"
                                                    @if ($shortcut['download']) download @else target="_blank" rel="noopener" @endif
                                                    class="group flex items-center gap-3.5 rounded-box px-2 py-3.5 hover:bg-base-200"
                                                >
                                                    <span class="grid size-[42px] shrink-0 place-items-center rounded-box bg-base-200 text-primary group-hover:bg-secondary">
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
                                    <div class="mt-auto pt-[22px]">
                                        <button type="button" data-drop class="btn btn-ghost -ml-2 gap-2 font-normal" x-on:click="closeShortcuts">
                                            <x-icon name="o-arrow-left" class="h-[18px] w-[18px]" />Iniciar sesión
                                        </button>
                                    </div>
                                </div>
                            </article>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Pie del panel: el aviso de privacidad del TecNM (AC-23), siempre a la vista; fuera de la credencial para que el giro de los
                 atajos no lo esconda, y en otra pestaña para no perder lo escrito --}}
            <p class="mt-5 flex justify-center lg:mt-[clamp(18px,3vh,28px)]">
                <a href="{{ route('privacy-notice') }}" target="_blank" rel="noopener" data-testid="login-privacy-notice" class="inline-flex min-h-11 items-center gap-1.5 px-2 text-[13.5px] text-neutral underline decoration-base-300 underline-offset-[3px] hover:text-base-content hover:decoration-current">
                    <x-icon name="o-shield-check" class="h-4 w-4" />Aviso de privacidad<span class="sr-only"> (se abre en otra pestaña)</span>
                </a>
            </p>

            {{-- La banda a la que llega la credencial al entrar, con la forma de core::components.band (LoginScreenTest compara sus medidas) --}}
            <template x-ref="band">
                <div aria-hidden="true" class="fixed inset-x-0 top-0 text-sm/[1.45]">
                    <div class="flex h-16 items-center gap-[18px] bg-band pe-5 text-band-content max-desktop:gap-3.5 max-md:h-14 max-md:gap-3 max-md:pe-2.5">
                        <span data-arrival="hare" class="grid h-16 w-[72px] flex-none place-items-center bg-accent max-md:h-14 max-md:w-14">
                            <span class="block size-[46px] bg-accent-content mask-(--hare) mask-contain mask-center mask-no-repeat max-md:size-[38px]"></span>
                        </span>
                        <span data-arrival="wordmark" class="text-xl font-bold tracking-[-0.01em] max-md:text-lg">Nexus</span>
                        {{-- Lo que no vuela ocupa su lugar sin verse (la página siguiente lo hace aparecer): así la credencial cae donde estará --}}
                        <span data-arrival="route" class="invisible flex min-w-0 items-center gap-2 text-[15px] whitespace-nowrap text-band-muted max-md:hidden">
                            <span data-arrival="area" class="max-desktop:hidden"></span>
                            <x-icon name="o-chevron-right" class="w-4 h-4 max-desktop:hidden" />
                            <span data-arrival="title" class="truncate font-medium text-band-content"></span>
                        </span>
                        <span class="flex-1"></span>
                        <span data-arrival="search" class="invisible flex h-10 w-[clamp(260px,26vw,520px)] flex-none items-center gap-2.5 px-3 text-band-muted max-desktop:w-[226px] max-md:hidden"></span>
                        <span data-arrival="search-icon" class="invisible hidden size-11 flex-none max-md:grid"></span>
                        <span data-arrival="bell" class="invisible block size-11 flex-none text-band-content/70"></span>
                        <span class="flex h-[46px] max-w-full min-w-0 items-center gap-2.5 rounded-full border border-band-content/20 ps-1.5 pe-3 text-start max-md:h-11 max-md:px-[5px]">
                            <span data-arrival="avatar" class="grid size-[34px] flex-none place-items-center overflow-hidden rounded-full bg-secondary text-[13px] font-bold text-secondary-content"></span>
                            <span class="flex min-w-0 flex-col leading-tight max-md:sr-only">
                                <span data-arrival="name" class="truncate font-bold"></span>
                                <span data-arrival="summary" class="text-[12.5px] whitespace-nowrap text-band-muted max-desktop:hidden"></span>
                            </span>
                            <x-icon name="o-chevron-down" class="h-[18px] w-[18px] flex-none max-md:hidden" />
                        </span>
                    </div>
                    <div class="h-[3px] bg-accent"></div>
                </div>
            </template>
        </main>

        {{-- Avisos (LG-01) sobre la foto; sin avisos, la foto del edificio sola --}}
        @if ($notices !== [])
            <h2 class="order-2 mx-auto mt-[26px] mb-2.5 w-full max-w-[560px] px-4 text-[19px] font-bold lg:hidden">Avisos</h2>
        @endif
        <section
            aria-roledescription="carrusel"
            aria-label="Avisos"
            data-login-photos
            class="@container relative order-3 mx-4 mb-16 overflow-hidden rounded-[14px] border border-base-300 bg-base-100 shadow-[0_2px_4px_rgb(0_0_0/0.08),0_18px_40px_-18px_rgb(0_0_0/0.42)] sm:mx-auto sm:w-[calc(100%-32px)] sm:max-w-[560px] lg:sticky lg:top-0 lg:order-1 lg:m-0 lg:h-svh lg:w-auto lg:max-w-none lg:rounded-none lg:border-0 lg:shadow-none"
            x-on:mouseenter="hover(true)"
            x-on:mouseleave="hover(false)"
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
                            <img src="{{ $notice['image_url'] }}" alt="" class="aspect-[16/11] w-full object-cover lg:absolute lg:inset-0 lg:aspect-auto lg:h-full" />
                        @else
                            <div class="relative aspect-[16/11] overflow-hidden bg-primary lg:absolute lg:inset-0 lg:aspect-auto" aria-hidden="true">
                                <x-core::login.blade class="absolute top-[10%] -right-[12%] w-[118%] -rotate-[8deg]" />
                            </div>
                        @endif
                        <div
                            @class([
                                'px-5 pt-4 pb-4 lg:absolute lg:top-[clamp(24px,3.4vw,56px)] lg:right-[clamp(24px,3.4vw,56px)] lg:max-w-[calc(100%-2*clamp(24px,3.4vw,56px))] lg:rounded-[12px] lg:px-[clamp(16px,1.6vw,24px)] lg:py-[clamp(12px,1.1vw,16px)] lg:shadow-[0_2px_4px_rgb(0_0_0/0.08),0_18px_40px_-18px_rgb(0_0_0/0.42)] lg:backdrop-blur-md',
                                'lg:bg-base-200/60' => $notice['image_url'],
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
                    <img src="{{ $fallbackPhoto }}" alt="" class="aspect-[16/11] w-full object-cover lg:absolute lg:inset-0 lg:aspect-auto lg:h-full" />
                @endforelse
            </div>

            @if (count($notices) > 1)
                <div
                    class="mb-3.5 ml-3 inline-flex items-center gap-0.5 rounded-full px-2.5 py-1 lg:absolute lg:top-[calc(clamp(24px,3.4vw,56px)+clamp(60px,4.6vw,70px))] lg:right-[clamp(24px,3.4vw,56px)] lg:z-10 lg:m-0 lg:bg-base-200/60 lg:shadow-[0_2px_4px_rgb(0_0_0/0.08),0_18px_40px_-18px_rgb(0_0_0/0.42)] lg:backdrop-blur-md"
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
                                x-bind:class="current === {{ $index }} ? 'w-[34px] bg-base-300' : 'w-2 bg-neutral group-hover:bg-primary'"
                            ><span data-progress class="block h-full w-full origin-left scale-x-0 bg-primary"></span></i>
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($hasShortcuts)
                <button
                    type="button"
                    x-ref="shortcutsButton"
                    x-on:click="openShortcuts"
                    aria-controls="login-shortcuts"
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
