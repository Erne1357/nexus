{{--
    El aviso de privacidad (AC-23, tarea 1.65; DESIGN.md, «Aviso de privacidad»): página pública con los dos avisos del
    TecNM tal cual, primero el simplificado y después el integral, cada uno en su sección con ancla (#simplificado,
    #integral). Desde 1200 px, a la derecha y fija al desplazarse, la columna con los dos enlaces de la página, la fuente
    y los PDF oficiales; abajo de ese ancho, esa columna va antes de los avisos. Los textos viven en
    core::privacy-notice.simplified y core::privacy-notice.integral; las direcciones, en Core\Support\PrivacyNotice.
--}}
@use(App\Modules\Core\Support\PrivacyNotice)

@php
    $sectionLink = 'flex min-h-11 items-center gap-2.5 rounded-field px-2.5 text-[15px] font-medium text-primary hover:bg-base-100 max-desktop:border max-desktop:border-base-300 max-desktop:bg-base-100 max-desktop:hover:bg-secondary/40';
    $label = 'text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase';
    $panel = 'scroll-mt-24 rounded-box border border-base-300 bg-base-100 px-[clamp(18px,4.2vw,48px)] py-[clamp(22px,3.6vw,44px)] text-[15.5px]/[1.62]';
@endphp

<x-core::layouts.public title="Aviso de privacidad" trail="Aviso de privacidad">
    <div class="mx-auto grid max-w-[1180px] gap-x-14 gap-y-6 desktop:grid-cols-[minmax(0,1fr)_300px]">
        <header class="desktop:col-start-1 desktop:pt-2">
            <h1 class="text-[30px] leading-[1.15] font-bold tracking-[-0.01em] text-balance max-md:text-[26px]">Aviso de privacidad</h1>
            <p class="mt-2.5 max-w-[66ch] text-[15px] text-pretty text-neutral">
                Cómo trata tus datos personales el Tecnológico Nacional de México (TecNM), al que pertenece el Instituto Tecnológico de Ciudad Juárez. Es el texto oficial, copiado tal cual: primero el aviso simplificado y después el integral.
            </p>
            <p class="mt-3 flex items-center gap-1.5 text-sm text-neutral">
                <x-icon name="o-calendar" class="h-4 w-4 shrink-0" />
                <span>Fecha de realización: <time datetime="{{ PrivacyNotice::DATE }}">{{ PrivacyNotice::dateLabel() }}</time></span>
            </p>
        </header>

        <aside class="desktop:col-start-2 desktop:row-span-2 desktop:row-start-1">
            <div class="flex flex-col gap-6 desktop:sticky desktop:top-[calc(67px+26px)] desktop:pt-2">
                <nav aria-labelledby="privacy-sections">
                    <h2 id="privacy-sections" class="{{ $label }} mb-2 desktop:px-2.5">En esta página</h2>
                    <ul class="flex flex-wrap gap-2 desktop:flex-col desktop:gap-1">
                        <li>
                            <a href="#simplificado" class="{{ $sectionLink }}">
                                <x-icon name="o-document-text" class="h-5 w-5 shrink-0 text-neutral" />Aviso simplificado
                            </a>
                        </li>
                        <li>
                            <a href="#integral" class="{{ $sectionLink }}">
                                <x-icon name="o-document-duplicate" class="h-5 w-5 shrink-0 text-neutral" />Aviso integral
                            </a>
                        </li>
                    </ul>
                </nav>

                <div class="flex flex-col gap-2.5 desktop:border-t desktop:border-base-300 desktop:px-2.5 desktop:pt-5">
                    <h2 class="{{ $label }}">Fuente</h2>
                    <p class="text-sm text-pretty text-neutral">
                        La página del ITCJ para alumnos,
                        <a href="{{ PrivacyNotice::SOURCE_URL }}" target="_blank" rel="noopener" class="link text-base-content underline-offset-3">cdjuarez.tecnm.mx/alumnos<span class="sr-only"> (se abre en otra pestaña)</span></a>,
                        en «Protección de Datos Personales», lleva a la
                        <a href="{{ PrivacyNotice::TECNM_PAGE_URL }}" target="_blank" rel="noopener" class="link text-base-content underline-offset-3">página del TecNM<span class="sr-only"> (se abre en otra pestaña)</span></a>
                        con los documentos oficiales:
                    </p>
                    <ul class="flex flex-col gap-1.5 text-sm">
                        @foreach ([['Aviso simplificado', PrivacyNotice::SIMPLIFIED_PDF_URL], ['Aviso integral', PrivacyNotice::INTEGRAL_PDF_URL]] as [$name, $url])
                            <li>
                                <a href="{{ $url }}" target="_blank" rel="noopener" class="inline-flex min-h-9 items-center gap-2 font-medium text-primary hover:underline hover:underline-offset-3">
                                    <x-icon name="o-arrow-top-right-on-square" class="h-4 w-4 shrink-0" />{{ $name }} (PDF)<span class="sr-only"> (se abre en otra pestaña)</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-col gap-6 desktop:col-start-1">
            <section id="simplificado" aria-label="Aviso simplificado" class="{{ $panel }}">
                <x-core::privacy-notice.simplified class="max-w-[74ch]" />
            </section>

            <section id="integral" aria-label="Aviso integral" class="{{ $panel }}">
                <x-core::privacy-notice.integral class="max-w-[74ch]" />
            </section>
        </div>
    </div>
</x-core::layouts.public>
