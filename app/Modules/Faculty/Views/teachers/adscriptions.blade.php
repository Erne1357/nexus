{{--
    Departamentos de un docente (faculty::pages.teachers): el principal primero, «SC», en Mono y con el nombre en el
    `title`; los otros después de «También». La adscripción es solo de un departamento: el docente no es de un
    campus. TeacherQuery ya las trae ordenadas, la principal primero, con su departamento aunque esté borrado. En la
    tabla (`$stacked`) los otros van en su propio renglón; en el renglón apilado del celular, entre paréntesis.
--}}
@forelse ($teacher->adscriptions as $adscription)
    @if ($loop->index === 1)
        <span @class(['text-neutral', 'block text-xs' => $stacked])>{{ $stacked ? 'También' : '(también' }}
    @endif
    <span class="font-mono" title="{{ $adscription->department?->name }}">{{ $adscription->department?->code }}</span>{{ $loop->index === 0 ? '' : ($loop->last ? ($stacked ? '' : ')') : ',') }}
    @if ($loop->index >= 1 && $loop->last)
        </span>
    @endif
@empty
    <span class="text-neutral">Sin departamento</span>
@endforelse
