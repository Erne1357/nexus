@props(['url'])
{{-- La banda de los correos de Nexus (DESIGN.md): «Nexus» en blanco sobre azul espacio, el nombre del Tec debajo y la
franja roja de 3 px. Sin imágenes: muchos lectores de correo las bloquean. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{!! $slot !!}
<span class="header-tagline">Instituto Tecnológico de Ciudad Juárez</span>
</a>
</td>
</tr>
<tr>
<td class="header-stripe">&nbsp;</td>
</tr>
