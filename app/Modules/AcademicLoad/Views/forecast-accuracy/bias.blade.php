{{-- El sesgo en alumnos por materia con su signo: «+1.8», «−0.4» o «0.0». `$bias`. --}}
{{ round($bias, 1) == 0 ? '0.0' : ($bias > 0 ? '+' : '−').number_format(abs($bias), 1) }}