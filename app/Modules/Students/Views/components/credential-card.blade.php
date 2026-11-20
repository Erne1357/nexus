{{--
    La credencial del alumno (AC-15 y AC-20, tarea 1.27): el frente es la plantilla institucional del ITCJ
    (resources/images/credencial/frente.webp: degradado, logos del TecNM y del ITCJ, escudo y monumento) con el chip, la
    foto arriba a la derecha y tres líneas en Montserrat blanca, en las medidas de la plantilla (1017 × 642). El reverso,
    sobre el mismo fondo, lleva el QR que cambia cada 30 s (students::components.credential-qr). El botón la voltea; sin
    movimiento si el sistema lo pide. Las medidas van en `cqw` para que escale con el ancho.

    $credential: StudentCredentialQuery::of().
--}}
@props(['credential'])

@php($front = Vite::asset('resources/images/credencial/frente.webp'))

<div x-data="{ back: false }" class="mx-auto w-full max-w-[640px]" data-testid="my-credential">
    <div class="@container [perspective:1800px]">
        <div
            class="relative aspect-[1017/642] transition-transform duration-700 ease-[cubic-bezier(.2,.7,.2,1)] [transform-style:preserve-3d] motion-reduce:transition-none"
            x-bind:style="back ? 'transform: rotateY(180deg)' : ''"
        >
            {{-- Frente --}}
            <section
                aria-label="Frente de la credencial"
                x-bind:aria-hidden="back ? 'true' : 'false'"
                class="absolute inset-0 overflow-hidden rounded-[3cqw] bg-primary shadow-[0_18px_44px_rgb(9_45_72/0.28),0_2px_6px_rgb(9_45_72/0.14)] [backface-visibility:hidden]"
                data-testid="credential-front"
            >
                <img src="{{ $front }}" alt="" class="absolute inset-0 size-full select-none" draggable="false">

                <svg class="absolute top-[43.6%] left-[7.47%] w-[11.4%]" viewBox="0 0 116 77" aria-hidden="true">
                    <defs>
                        <linearGradient id="credential-chip" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="#f7e6a6" />
                            <stop offset=".45" stop-color="#dcb45c" />
                            <stop offset="1" stop-color="#b08534" />
                        </linearGradient>
                    </defs>
                    <rect x="1" y="1" width="114" height="75" rx="11" fill="url(#credential-chip)" stroke="#9c7a33" stroke-width="1.5" />
                    <g fill="none" stroke="#7a5b20" stroke-width="2.2" stroke-linecap="round" stroke-opacity=".7">
                        <path d="M1 26h30c6 0 10 4 10 10v5c0 6-4 10-10 10H1" />
                        <path d="M115 26H85c-6 0-10 4-10 10v5c0 6 4 10 10 10h30" />
                        <path d="M41 38.5h34" />
                        <path d="M58 1v14c0 5-4 8-9 8h-8" />
                        <path d="M58 76V62c0-5 4-8 9-8h8" />
                    </g>
                </svg>

                <div class="absolute top-0 right-0 h-[48.3%] w-[23.1%] overflow-hidden rounded-bl-[1.1cqw] bg-white p-[0.45cqw]" data-testid="credential-photo">
                    @if ($credential['has_photo'])
                        <img src="{{ $credential['photo_url'] }}" alt="Foto de {{ $credential['name'] }}" class="size-full rounded-bl-[0.8cqw] object-cover object-top" data-testid="credential-photo-img">
                    @else
                        <div class="grid size-full place-items-center rounded-bl-[0.8cqw] bg-base-200">
                            <span class="font-credential text-[5.4cqw] font-semibold text-primary" aria-hidden="true">{{ $credential['initials'] }}</span>
                        </div>
                    @endif
                </div>

                <div class="absolute top-[70.6%] right-[3%] left-[7.47%] font-credential text-[2.6cqw] leading-[1.38] font-medium text-white [text-shadow:0_1px_2px_rgb(0_0_0/0.18)]" data-testid="credential-lines">
                    <p class="truncate">{{ $credential['name'] }} / {{ $credential['control_number'] }}</p>
                    <p class="truncate">{{ $credential['career'] }} / {{ $credential['status'] ?? 'Credencial no vigente' }}</p>
                    <p class="truncate">{{ \App\Modules\Students\Queries\StudentCredentialQuery::INSTITUTION }}</p>
                </div>
            </section>

            {{-- Reverso: la mitad izquierda es la plantilla (logo del TecNM, escudo del ITCJ y monumento) sobre su mismo degradado; la derecha, una hoja blanca con el QR. --}}
            <section
                aria-label="Reverso de la credencial"
                x-bind:aria-hidden="back ? 'false' : 'true'"
                class="absolute inset-0 overflow-hidden rounded-[3cqw] bg-[linear-gradient(180deg,#284393_0%,#a65075_50%,#e73c3b_100%)] shadow-[0_18px_44px_rgb(9_45_72/0.28),0_2px_6px_rgb(9_45_72/0.14)] [backface-visibility:hidden] [transform:rotateY(180deg)]"
                data-testid="credential-back"
            >
                <img src="{{ $front }}" alt="" class="absolute inset-0 size-full select-none [clip-path:inset(0_42.4%_0_0)]" draggable="false">

                <div class="absolute top-[42%] left-[7.47%] w-[47%] font-credential text-white [text-shadow:0_1px_2px_rgb(0_0_0/0.18)]">
                    <p class="text-[1.65cqw] font-semibold tracking-[0.14em] uppercase opacity-85">Credencial digital</p>
                    <p class="mt-[0.6cqw] text-[2.9cqw] leading-tight font-semibold">{{ $credential['name'] }}</p>
                    <p class="mt-[0.4cqw] text-[2.2cqw] font-medium">{{ $credential['control_number'] }} · {{ $credential['status'] ?? 'No vigente' }}</p>
                </div>

                <div class="absolute inset-y-0 right-0 flex w-[41%] flex-col items-center justify-center gap-[1.4cqw] rounded-l-[3cqw] bg-white px-[2cqw] text-center shadow-[-8px_0_30px_rgb(9_45_72/0.22)]">
                    @if ($credential['current'])
                        <livewire:students::components.credential-qr :student-id="$credential['id']" :key="'credential-qr-'.$credential['id']" />
                        <p class="font-credential text-[1.9cqw] leading-tight font-semibold text-primary">Escanea para verificar</p>
                        <p class="font-credential text-[1.5cqw] leading-tight text-primary/70">{{ parse_url((string) config('app.url'), PHP_URL_HOST) }}</p>
                    @else
                        <p class="font-credential text-[2.4cqw] leading-tight font-semibold text-primary" data-testid="credential-not-current">Credencial no vigente</p>
                        <p class="font-credential text-[1.6cqw] leading-snug text-primary/75">Acude a Servicios Escolares si crees que es un error.</p>
                    @endif
                </div>
            </section>
        </div>
    </div>

    <div class="mt-5 flex justify-center">
        <x-button class="btn-primary btn-sm" icon="o-arrow-path" x-on:click="back = ! back" data-testid="credential-flip">
            <span x-text="back ? 'Ver frente' : 'Ver reverso con el código QR'">Ver reverso con el código QR</span>
        </x-button>
    </div>
</div>
