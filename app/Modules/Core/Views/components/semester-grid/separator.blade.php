{{--
    Dónde empieza una especialidad dentro de un semestre de core::semester-grid.frame, antes de su primera materia.
    En celular es un separador corto, punteado, con su nombre. Desde 768 px la especialidad se ve en su banda
    (core::semester-grid.band) y este separador queda solo para lectores de pantalla, en el orden de las materias.
--}}
@props(['specialty', 'name'])

<p data-separator="{{ $specialty }}" class="col-span-2 mt-1 border-t border-dashed border-neutral/40 px-0.5 pt-2 text-[13px] leading-snug md:sr-only">
    <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Especialidad</span>
    <span class="font-semibold">{{ $name }}</span>
</p>
