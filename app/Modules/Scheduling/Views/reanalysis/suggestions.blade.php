{{--
    Las sugerencias de una materia en un campus: el lugar de la tarea 8.3 (Riel B) dentro del drawer
    `scheduling::components.reanalysis-suggestions`. Hoy, vacío. La 8.3 pinta aquí cada sugerencia (abrir, cerrar o
    ampliar un grupo, cambiar de salón) con su evidencia y lo que dice el validador, y sus botones llaman métodos del
    componente del drawer. `$block` (ReanalysisBlock), `$load` y `$suggestions` (lo que da `suggestions()` del drawer).
--}}
<section class="space-y-2" aria-labelledby="suggestions-list-title" data-testid="suggestions-slot">
    <h3 id="suggestions-list-title" class="text-base font-bold">Sugerencias <span class="font-mono font-normal text-neutral">{{ count($suggestions) }}</span></h3>
    @if ($suggestions === [])
        <div class="flex items-start gap-3 rounded-box border border-dashed border-base-300 px-4 py-4" data-testid="suggestions-empty">
            <x-icon name="o-light-bulb" class="mt-0.5 h-5 w-5 shrink-0 text-neutral" aria-hidden="true" />
            <div class="grid gap-0.5">
                <p class="font-semibold">Las sugerencias llegan con la tarea 8.3</p>
                <p class="text-sm text-neutral">Mientras tanto, ajusta los grupos de {{ $block->subjectCode }} en Horarios; lo que cambies aparece en su bloque como «Ya ajustaste».</p>
            </div>
        </div>
    @endif
</section>
