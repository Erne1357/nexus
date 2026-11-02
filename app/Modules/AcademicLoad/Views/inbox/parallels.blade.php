{{--
    El drawer de paralelos de una materia (academic-load::pages.inbox): lo que piden las carreras contra lo que
    bastan juntándolas, una minitabla carrera × (grupos completos, alumnos al mixto) y los grupos mixtos. Es solo la
    sugerencia de ParallelGroups; lo mixto se arma en la fase 3. `close()` es del x-drawer.
--}}
@php($subject = $parallelsTarget['subject'])
@php($parallels = $parallelsTarget['parallels'])
<div class="flex min-h-dvh flex-col" data-testid="parallels-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <p class="font-mono text-[15px] font-semibold">{{ $subject->code }}</p>
            <h2 id="parallels-title" class="mt-0.5 text-xl font-bold leading-tight">{{ $subject->name }}</h2>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-5 px-5 py-4">
        <p class="text-sm">{{ $parallelsTarget['summary'] }}</p>

        <table class="table table-sm" data-testid="parallels-table">
            <caption class="sr-only">Grupos por carrera, con tamaño objetivo de {{ $parallels->size }} alumnos</caption>
            <thead>
                <tr class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                    <th scope="col">Carrera</th>
                    <th scope="col" class="text-right">Piden</th>
                    <th scope="col" class="text-right">Completos</th>
                    <th scope="col" class="text-right">Al mixto (alumnos)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($parallels->careers as $code => $career)
                    <tr @class(['bg-base-200 font-semibold' => $code === ($selected['career']->code ?? null)]) data-current="{{ $code === ($selected['career']->code ?? null) ? 'true' : 'false' }}">
                        <th scope="row" class="font-mono text-[15px] font-semibold">{{ $code }}@if ($code === ($selected['career']->code ?? null)) <span class="font-sans text-sm font-normal text-base-content">(elegida)</span>@endif</th>
                        <td class="text-right font-mono tabular-nums text-base-content">{{ $career['requested'] }}</td>
                        <td class="text-right font-mono tabular-nums text-base-content">{{ $career['full'] }}</td>
                        <td class="text-right font-mono tabular-nums text-base-content">{{ $career['remainder'] }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-semibold">
                    <th scope="row">Total</th>
                    <td class="text-right font-mono tabular-nums">{{ $parallels->requested }}</td>
                    <td class="text-right font-mono tabular-nums">{{ array_sum(array_column($parallels->careers, 'full')) }}</td>
                    <td class="text-right font-mono tabular-nums">{{ array_sum($parallels->mixedShares) }}</td>
                </tr>
            </tfoot>
        </table>

        @if ($parallelsTarget['mixed'] !== null)
            <section aria-labelledby="parallels-mixed-title" data-testid="parallels-mixed">
                <h3 id="parallels-mixed-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Grupos mixtos, de hasta {{ $parallels->size }} alumnos</h3>
                <p class="mt-1 text-sm">{{ $parallelsTarget['mixed'] }}</p>
            </section>
        @endif
    </div>
</div>
