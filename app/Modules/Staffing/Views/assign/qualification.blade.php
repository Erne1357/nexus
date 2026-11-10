{{--
    El perfil del docente en la materia: «Preferente» con estrella, «Apto» o «Sin perfil».
--}}
@if ($qualification === \App\Modules\Faculty\Enums\QualificationLevel::Preferente)
    <span class="inline-flex items-center gap-1 whitespace-nowrap font-semibold text-success"><x-icon name="o-star" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Preferente</span>
@elseif ($qualification === \App\Modules\Faculty\Enums\QualificationLevel::Apto)
    <span class="inline-flex items-center gap-1 whitespace-nowrap"><x-icon name="o-check" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Apto</span>
@else
    <span class="whitespace-nowrap text-neutral">Sin perfil</span>
@endif
