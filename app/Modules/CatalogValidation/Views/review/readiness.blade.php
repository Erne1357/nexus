{{--
    Indicadores de la tarjeta de Docentes (CV6, AC-06): cuántos docentes activos no tienen correo institucional (sin él no
    les llega su invitación al validar, AC-09), disponibilidad capturada en el periodo ni perfil de materias del
    departamento. Solo avisan, no bloquean Validar; cada uno es un enlace a la lista filtrada (`?falta=`). Van encima del
    enlace estirado de la tarjeta (`relative z-10`). `$counts` es de TeacherTermReadinessQuery::counts() más `no_email`.
--}}
<div class="relative z-10 flex flex-wrap gap-2 text-sm" data-testid="readiness">
    @foreach ([['key' => 'no_email', 'falta' => 'correo', 'icon' => 'o-envelope', 'text' => 'sin correo institucional'], ['key' => 'no_availability', 'falta' => 'disponibilidad', 'icon' => 'o-calendar-days', 'text' => 'sin disponibilidad en '.$term->code], ['key' => 'no_profile', 'falta' => 'perfil', 'icon' => 'o-book-open', 'text' => 'sin perfil']] as $item)
        @if (($counts[$item['key']] ?? 0) > 0)
            <a href="{{ route('catalog-validation.review', ['department' => $department->id, 'catalogo' => 'teachers', 'falta' => $item['falta']]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-field border border-warning/40 bg-warning/10 px-2.5 py-1.5 font-medium hover:bg-warning/20" data-testid="missing-{{ $item['falta'] }}">
                <x-icon :name="$item['icon']" class="h-4 w-4 text-warning" aria-hidden="true" /><b class="font-mono">{{ $counts[$item['key']] }}</b> {{ $item['text'] }}
            </a>
        @endif
    @endforeach
</div>
