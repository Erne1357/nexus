{{--
    Verificación pública de la credencial (AC-20, tarea 1.27): la abre el QR del reverso. Página suelta, sin el shell ni
    sesión. Solo con un código vigente muestra la foto, el nombre, el número de control, la carrera y el estatus; si el
    código venció, no es de Nexus o la credencial no está vigente, no muestra ningún dato. Siempre en tema claro.

    $result: CredentialVerificationQuery::verify(). $checkedAt: la hora de la verificación (zona del Tec).
--}}
<!DOCTYPE html>
<html lang="es" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Verificación de credencial · {{ config('app.name') }}</title>
        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-base-200 font-sans text-base-content antialiased">
        <header class="bg-primary text-primary-content">
            <div class="mx-auto flex max-w-xl items-center gap-3 px-4 py-4">
                <span class="grid size-9 place-items-center rounded-md bg-accent" aria-hidden="true">
                    <img src="{{ Vite::asset('resources/images/liebre-512.png') }}" alt="" class="size-6 brightness-0 invert">
                </span>
                <div>
                    <p class="text-lg leading-tight font-bold">Nexus</p>
                    <p class="text-sm opacity-80">Verificación de credencial</p>
                </div>
            </div>
            <div class="h-[3px] bg-accent" aria-hidden="true"></div>
        </header>

        <main class="mx-auto max-w-xl px-4 py-8">
            @switch($result['state'])
                @case('valid')
                    <section class="overflow-hidden rounded-box border border-base-300 bg-base-100 shadow-sm" data-testid="credential-valid">
                        <div class="flex items-center gap-3 border-b border-base-300 bg-success/10 px-5 py-4">
                            <x-icon name="o-check-badge" class="w-7 h-7 text-success" />
                            <div>
                                <h1 class="text-lg leading-tight font-bold">Credencial vigente</h1>
                                <p class="text-sm text-neutral">Verificada el {{ $checkedAt->translatedFormat('j \d\e F \d\e Y') }} a las {{ $checkedAt->format('H:i:s') }}</p>
                            </div>
                        </div>
                        <div class="grid gap-5 p-5 sm:grid-cols-[140px_minmax(0,1fr)]">
                            <div class="mx-auto aspect-square w-[140px] overflow-hidden rounded-lg bg-base-200">
                                @if ($result['student']['photo'] !== null)
                                    <img src="{{ $result['student']['photo'] }}" alt="Foto de {{ $result['student']['name'] }}" class="size-full object-cover object-top" data-testid="credential-verify-photo">
                                @else
                                    <div class="grid size-full place-items-center text-sm text-neutral">Sin foto</div>
                                @endif
                            </div>
                            <dl class="grid content-start gap-3">
                                @foreach ([['Nombre', $result['student']['name']], ['Número de control', $result['student']['control_number']], ['Carrera', $result['student']['career']], ['Estatus', $result['student']['status']]] as [$label, $value])
                                    <div>
                                        <dt class="text-[11.5px] font-medium tracking-[.09em] text-neutral uppercase">{{ $label }}</dt>
                                        <dd class="mt-px text-[15.5px] font-semibold">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    </section>
                    @break

                @case('expired')
                    <section class="rounded-box border border-base-300 bg-base-100 p-6 text-center" data-testid="credential-expired">
                        <x-icon name="o-clock" class="mx-auto w-10 h-10 text-warning" />
                        <h1 class="mt-3 text-lg font-bold">Este código ya venció</h1>
                        <p class="mt-1 text-neutral">El código de la credencial cambia cada 30 segundos. Pide que te muestren el código actual y escanéalo de nuevo.</p>
                    </section>
                    @break

                @case('not_current')
                    <section class="rounded-box border border-base-300 bg-base-100 p-6 text-center" data-testid="credential-not-current">
                        <x-icon name="o-x-circle" class="mx-auto w-10 h-10 text-error" />
                        <h1 class="mt-3 text-lg font-bold">Credencial no vigente</h1>
                        <p class="mt-1 text-neutral">Esta credencial no está vigente.</p>
                    </section>
                    @break

                @default
                    <section class="rounded-box border border-base-300 bg-base-100 p-6 text-center" data-testid="credential-invalid">
                        <x-icon name="o-question-mark-circle" class="mx-auto w-10 h-10 text-neutral" />
                        <h1 class="mt-3 text-lg font-bold">Código no reconocido</h1>
                        <p class="mt-1 text-neutral">Este código no es de una credencial de Nexus.</p>
                    </section>
            @endswitch

            <p class="mt-8 text-center text-sm text-neutral">Instituto Tecnológico de Ciudad Juárez</p>
        </main>
    </body>
</html>
