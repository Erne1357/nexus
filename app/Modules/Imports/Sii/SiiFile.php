<?php

namespace App\Modules\Imports\Sii;

/**
 * Los archivos que el SII exporta y que la migración lee. El valor es el nombre normalizado del archivo
 * (sin extensión, en minúsculas, sin acentos y con `_` en lugar de cualquier otro separador).
 */
enum SiiFile: string
{
    case Organigrama = 'organigrama';
    case Carreras = 'carreras';
    case Especialidades = 'especialidades';
    case Materias = 'materias';
    case MateriasCarreras = 'materias_carreras';
    case MateriasClavesTecnm = 'materias_claves_tecnm';
    case Aulas = 'aulas';
    case Docentes = 'docentes';
    case Docentes2 = 'docentes2';
    case Alumnos = 'alumnos_todos';
    case Grupos = 'grupos';
    case Horarios = 'horarios';
    case HistoriaAlumno = 'historia_alumno';
    case SeleccionMaterias = 'seleccion_materias';
    case RequisitosMateria = 'requisitos_materia';

    /**
     * Encabezados exactos (normalizados) que trae la exportación real. Si cambian, la migración se detiene.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        return match ($this) {
            self::Organigrama => [
                'clave_area',
                'descripcion_area',
                'area_depende',
                'nivel',
                'tipo_area',
                'p_sustantivos',
                'pro_sus',
                'p_sus',
                'p_s',
            ],
            self::Carreras => [
                'carrera',
                'reticula',
                'nivel_escolar',
                'clave_oficial',
                'nombre_carrera',
                'nombre_reducido',
                'siglas',
                'carga_maxima',
                'carga_minima',
                'fecha_inicio',
                'fecha_termino',
                'clave_cosnet',
                'creditos_totales',
                'tipo_carrera',
            ],
            self::Especialidades => [
                'especialidad',
                'carrera',
                'reticula',
                'nombre_especialidad',
                'periodo_inicio',
                'periodo_termino',
                'creditos_optativos',
                'creditos_especialidad',
            ],
            self::Materias => [
                'materia',
                'nivel_escolar',
                'tipo_materia',
                'clave_area',
                'nombre_completo_materia',
                'nombre_abreviado_materia',
            ],
            self::MateriasCarreras => [
                'carrera',
                'reticula',
                'materia',
                'creditos_materia',
                'horas_teoricas',
                'horas_practicas',
                'orden_certificado',
                'semestre_reticula',
                'creditos_prerrequisito',
                'especialidad',
                'clave_oficial_materia',
                'estatus_materia_carrera',
                'programa_estudios',
                'renglon',
            ],
            self::MateriasClavesTecnm => [
                'carrera',
                'reticula',
                'materia',
                'semestre',
                'nombre_completo_materia',
                'nombre_abreviado_materia',
                'area',
                'clave_tecnm',
                'ht',
                'hp',
                'cr',
                'modalidad',
            ],
            self::Aulas => [
                'aula',
                'ubicacion',
                'capacidad_aula',
                'observaciones',
                'permite_cruce',
                'estatus',
            ],
            self::Docentes => [
                'rfc',
                'clave_centro_seit',
                'clave_area',
                'curp_empleado',
                'no_tarjeta',
                'apellidos_empleado',
                'nombre_empleado',
                'horas_nombramiento',
                'nombramiento',
                'clases',
                'ingreso_rama',
                'inicio_gobierno',
                'inicio_sep',
                'inicio_plantel',
                'domicilio_empleado',
                'colonia_empleado',
                'codigo_postal_empleado',
                'localidad',
                'telefono_empleado',
                'sexo_empleado',
                'estado_civil',
                'fecha_nacimiento',
                'lugar_nacimiento',
                'institucion_egreso',
                'nivel_estudios',
                'grado_maximo_estudios',
                'estudios',
                'fecha_termino_estudios',
                'fecha_titulacion',
                'cedula_profesional',
                'especializacion',
                'idiomas_domina',
                'status_empleado',
                'foto',
                'firma',
                'correo_electronico',
                'padre',
                'madre',
                'conyuge',
                'hijos',
                'num_acta',
                'num_libro',
                'num_foja',
                'num_ano',
                'num_cartilla_smn',
                'ano_clase',
                'pigmentacion',
                'pelo',
                'frente',
                'cejas',
                'ojos',
                'nariz',
                'boca',
                'estaturamts',
                'pesokg',
                'senas_visibles',
                'pais',
                'pasaporte',
                'fm',
                'inicio_vigencia',
                'termino_vigencia',
                'entrada_salida',
                'observaciones_empleado',
                'area_academica',
                'tipo_personal',
                'tipo_control',
                'rfc2',
            ],
            self::Docentes2 => [
                'rfc',
                'apellidop',
                'apellidom',
                'nombre_empleado',
                'grado_academico',
                'nombre_carrera_cursada',
                'pasante_titulado',
                'unidad_organica_adscripcion',
                'id_unidad_organica',
                'tipo_nombramiento',
                'cantidad_horas_nombramiento',
                'claves_plazas',
                'fecha_ingreso_sep',
                'fecha_ingreso_institucion',
                'clave_area_academica',
                'puesto',
                'fecha_horario',
            ],
            self::Alumnos => [
                'no_de_control',
                'carrera',
                'reticula',
                'especialidad',
                'nivel_escolar',
                'semestre',
                'clave_interna',
                'estatus_alumno',
                'plan_de_estudios',
                'apellido_paterno',
                'apellido_materno',
                'nombre_alumno',
                'curp_alumno',
                'fecha_nacimiento',
                'sexo',
                'estado_civil',
                'tipo_ingreso',
                'periodo_ingreso_it',
                'ultimo_periodo_inscrito',
                'promedio_periodo_anterior',
                'promedio_aritmetico_acumulado',
                'creditos_aprobados',
                'creditos_cursados',
                'promedio_final_alcanzado',
                'tipo_servicio_medico',
                'clave_servicio_medico',
                'escuela_procedencia',
                'tipo_escuela',
                'domicilio_escuela',
                'entidad_procedencia',
                'ciudad_procedencia',
                'correo_electronico',
                'foto',
                'firma',
                'periodos_revalidacion',
                'indice_reprobacion_acumulado',
                'becado_por',
                'nip',
                'tipo_alumno',
                'nacionalidad',
                'usuario',
                'fecha_actualizacion',
                'campus',
            ],
            self::Grupos => [
                'periodo',
                'materia',
                'grupo',
                'estatus_grupo',
                'capacidad_grupo',
                'alumnos_inscritos',
                'folio_acta',
                'paralelo_de',
                'exclusivo_carrera',
                'exclusivo_reticula',
                'rfc',
                'seleccionado_en_bloque',
                'tipo_personal',
                'alumnos_preinscritos',
            ],
            self::Horarios => [
                'periodo',
                'rfc',
                'tipo_horario',
                'dia_semana',
                'hora_inicial',
                'hora_final',
                'materia',
                'grupo',
                'aula',
                'actividad',
                'consecutivo',
                'vigencia_inicio',
                'vigencia_fin',
                'consecutivo_admvo',
            ],
            self::HistoriaAlumno => [
                'periodo',
                'no_de_control',
                'materia',
                'grupo',
                'calificacion',
                'tipo_evaluacion',
                'fecha_calificacion',
                'plan_de_estudios',
                'estatus_materia',
                'nopresento',
                'checksum',
                'usuario',
                'fecha_actualizacion',
                'periodo_acredita_materia',
            ],
            self::SeleccionMaterias => [
                'periodo',
                'no_de_control',
                'materia',
                'grupo',
                'calificacion',
                'tipo_evaluacion',
                'repeticion',
                'nopresento',
                'status_seleccion',
                'fecha_hora_seleccion',
                'cuantas_curso',
                'creditos_materia_sm',
            ],
            self::RequisitosMateria => [
                'carrera',
                'reticula',
                'materia',
                'materia_relacion',
                'tipo_requisito',
            ],
        };
    }

    /**
     * Columnas que el SII escribe con coma decimal y sin comillas (87,50), así que llegan partidas en dos campos.
     *
     * @return list<string>
     */
    public function decimalColumns(): array
    {
        return match ($this) {
            self::Alumnos => [
                'promedio_periodo_anterior',
                'promedio_aritmetico_acumulado',
                'promedio_final_alcanzado',
                'indice_reprobacion_acumulado',
            ],
            default => [],
        };
    }

    /**
     * Si la exportación debe traer el archivo. Los de la parte 1 son obligatorios; los del kárdex (historia y
     * selección) y la seriación (`requisitos_materia`), que llegaron después, no: sin ellos los pasos `kardex` y
     * `seriacion` avisan y no los migran.
     */
    public function required(): bool
    {
        return ! in_array($this, [self::HistoriaAlumno, self::SeleccionMaterias, self::RequisitosMateria], true);
    }

    /**
     * Normaliza un nombre de archivo o de columna: minúsculas, sin acentos, no alfanuméricos a `_`.
     */
    public static function normalize(string $name): string
    {
        $decomposed = \Normalizer::normalize($name, \Normalizer::FORM_D);
        $ascii = strtolower(preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $name : $decomposed) ?? $name);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $ascii), '_');
    }
}
