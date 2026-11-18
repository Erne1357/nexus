{{--
    Versiones publicadas (tarea 8.10; DESIGN.md «Versiones publicadas»: maqueta C «Línea y detalle», elegida por el usuario
    el 17 de noviembre de 2026). El título con el departamento, la modalidad (como dato con una sola), el periodo y cuántas
    versiones; a la derecha, la insignia del estado de la carga y, con más de una modalidad, el select «Modalidad». A quien
    ve varias cargas, el selector «Departamento». Debajo, la línea de versiones (academic-load::versions.line) y la versión
    elegida en dos columnas desde 64rem de caja (academic-load::versions.summary y .changes); «Ver por docente o carrera»
    abre el drawer (academic-load::versions.affected).
--}}
@php
    $day = fn (\Carbon\CarbonInterface $at): string => str_replace('.', '', $at->timezone(config('app.timezone'))->locale('es')->translatedFormat('j M Y'));
    $clock = fn (\Carbon\CarbonInterface $at): string => $at->timezone(config('app.timezone'))->format('G:i');
    $single = $modalityOptions->count() <= 1;
@endphp
<div class="space-y-4">
    <x-header title="Versiones publicadas" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-0!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="text-sm text-neutral!">
            @if ($load)
                <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5" data-testid="versions-meta">
                    <span class="font-mono text-[15px] font-semibold text-base-content">{{ $load->department->code }}</span>
                    <span class="font-semibold text-base-content">{{ $load->department->name }}</span>
                    <span aria-hidden="true">·</span>
                    @if ($single)
                        <span>{{ $load->modality->name }}</span>
                        <span aria-hidden="true">·</span>
                    @endif
                    <span>{{ $load->term->name }} <span class="font-mono">({{ $load->term->code }})</span></span>
                    <span aria-hidden="true">·</span>
                    <span>{{ count($versions) }} {{ count($versions) === 1 ? 'versión' : 'versiones' }}</span>
                </span>
            @endif
            <span class="mt-1 block max-w-[68ch]">Lo que vieron docentes y alumnos en cada momento: quién publicó, por qué, qué cambió contra la anterior y sus archivos.</span>
        </x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap items-end gap-3 max-md:w-full">
                @if ($load)
                    <x-core::status-badge :tone="$load->status->tone()" :label="$load->status === \App\Modules\AcademicLoad\Enums\LoadStatus::Published ? 'Publicada · v'.$current : $load->status->label().' · vigente la v'.$current" @class(['mb-2.5' => ! $single]) data-testid="versions-status" />
                @endif
                <x-core::modality-scope :options="$modalityOptions" :selected="$modalidad" class="w-full md:w-56" />
            </div>
        </x-slot:actions>
    </x-header>

    @if (count($departments) > 1)
        <div class="w-full md:w-[30rem]">
            <x-select label="Departamento" wire:model.live="department" :options="$departments" class="max-md:h-11" data-testid="versions-department" />
        </div>
    @endif

    @if ($load === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="versions-empty">
            <x-icon name="o-rectangle-stack" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
            <p class="font-semibold">{{ $message }}</p>
            <p class="mt-1 text-sm text-neutral">Aquí aparece cada versión en cuanto se publica.</p>
        </div>
    @else
        @include('academic-load::versions.line')

        <section class="@container rounded-box border border-base-300 bg-base-100" aria-labelledby="version-title" data-testid="version-detail" wire:key="version-detail-{{ $selected['version'] }}">
            <div class="grid @5xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                @include('academic-load::versions.summary')
                @include('academic-load::versions.changes')
            </div>
        </section>

        <x-drawer wire:model.live="showAffected" right close-on-escape class="w-full max-w-2xl p-0!" role="dialog" aria-modal="true" aria-labelledby="affected-title" data-testid="affected-drawer">
            @if ($affected !== null)
                @include('academic-load::versions.affected')
            @endif
        </x-drawer>
    @endif
</div>
