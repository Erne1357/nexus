# Proceso actual (SII + Excel) y sus deficiencias

Descripción del proceso de carga académica e inscripción en modalidad presencial (Campus I y II) tal como funciona hoy, levantado el 26 de octubre de 2026 con el equipo. Las modalidades EAD, EnLínea y TecNM Virtual tienen procesos distintos que no se han levantado todavía (ver `docs/plan/fase-10-otras-modalidades.md`).

## Actores

| Actor | Qué hace hoy | Herramienta |
|---|---|---|
| Coordinador de carrera | Revisa el avance de los alumnos en la retícula, decide qué materias y cuántos grupos ofertar, arma horarios tentativos por semestre reticular | Consultas al SII, Excel |
| Jefe de departamento académico | Busca docentes para los grupos de sus materias, asigna salones, cierra su carga, negocia en la junta | Excel, correo, reuniones |
| Jefe de proyecto docente | Junto con el jefe, reparte materias entre docentes según base de horas | Excel |
| Docente | Recibe su carga, pide cambios de horario | Correo, en persona |
| Junta interdepartamental | Un representante por departamento acuerda grupos compartidos, "paralelos" y préstamos de salones | Reunión, acuerdos verbales |
| Servicios Escolares / División | Define orden de inscripción y citas; opera la inscripción en el SII | SII |
| Alumno | Se inscribe en su cita dentro del SII | SII |

## Flujo actual

```mermaid
flowchart TB
    subgraph COORD["Coordinador de carrera (1 a 2 semanas)"]
        A1["Consulta avance de alumnos en retículas (uno por uno)"] --> A2["Decide materias y número de grupos a ofertar"]
        A2 --> A3["Acomoda horarios en Excel: que el alumno tipo de cada semestre no tenga empalmes y lleve horas seguidas"]
    end
    subgraph JEFE["Jefe de departamento + Jefe de proyecto docente (aprox. 1.5 meses)"]
        B1["Recibe la propuesta por correo / Excel"] --> B2["Reparte materias por prioridad de base: 40 h primero, honorarios al final"]
        B2 --> B3{"Empate de base"}
        B3 -->|"criterio informal en Excel"| B4["Se asignan materias; algunas se ocultan para favorecer a conocidos"]
        B4 --> B5["Asigna salones (sin memoria de dónde se dio antes)"]
    end
    subgraph DOC["Docente"]
        C1["Pide cambio de horario"] --> C2["Coordinador reacomoda materias afectadas a mano"]
    end
    subgraph JUNTA["Junta interdepartamental (una vez por semestre)"]
        D1["Acuerdan grupos compartidos: el de más alumnos crea el grupo, el otro abre un paralelo"] --> D2["Acuerdan préstamos de salones"]
        D2 --> D3["No hay límite por carrera en el paralelo: entran más de los previstos"]
    end
    subgraph CIERRE["Publicación e inscripción"]
        E1["Carga completa en SII"] --> E2["Se publica poco antes del semestre"]
        E2 --> E3["No hay re-análisis: la reprobación final no se refleja"]
        E3 --> E4["Orden de inscripción por semestre, promedio y créditos"]
        E4 --> E5["Citas de inscripción; el alumno se inscribe en el SII"]
        E5 --> E6["Inicio de semestre"]
    end
    A3 --> B1
    B4 --> C1
    C2 --> B5
    B2 -.-> D1
    B5 --> E1
```

## Línea de tiempo típica

```mermaid
gantt
    title Carga académica presencial (semanas antes del inicio de semestre)
    dateFormat  YYYY-MM-DD
    axisFormat  %d %b
    section Coordinador
    Análisis y propuesta de horarios       :a1, 2026-10-05, 14d
    section Jefe de departamento
    Búsqueda y asignación de docentes      :b1, after a1, 45d
    Cambios de horario y salones           :b2, after a1, 45d
    section Junta
    Junta interdepartamental               :milestone, j1, 2026-11-10, 0d
    section Cierre
    Publicación a alumnos                  :milestone, p1, 2027-01-08, 0d
    Inscripción por citas                  :e1, 2027-01-11, 7d
    Inicio de semestre                     :milestone, s1, 2027-01-18, 0d
```

Las fechas son ilustrativas; lo que importa son las duraciones: dos semanas para la propuesta, mes y medio para docentes, y nada entre la publicación y la inscripción para corregir.

## Deficiencias

| # | Deficiencia | Causa | Impacto | Cómo lo resuelve Nexus |
|---|---|---|---|---|
| D1 | El análisis de demanda es manual, alumno por alumno | El SII no cruza avance reticular con seriación | Grupos de más o de menos; se descubre en la inscripción | R1: conteo automático de alumnos elegibles por materia desde el kardex. R2: proyección con tasas históricas de reprobación y grupos sugeridos |
| D2 | Horarios en Excel sin validación | No hay regla que impida empalmes | Empalmes para el alumno tipo, docentes y salones; retrabajo | R1: validador de restricciones en vivo en el editor de horarios. R2: generación automática |
| D3 | Asignación docente opaca ("mafia" en Excel) | No hay criterio de desempate explícito ni visibilidad de la oferta completa | Materias ocultas, favoritismo, conflictos | Ranking calculado y visible por grupo; toda la oferta del departamento es visible para todos sus docentes; asignar fuera del ranking exige motivo y queda en bitácora |
| D4 | Mes y medio para asignar docentes | Trabajo serial por correo, sin tablero de pendientes | Publicación tardía | Tablero de grupos sin docente, carga por docente en tiempo real, notificaciones |
| D5 | Cambios de horario sin ver impacto | El coordinador reacomoda a ciegas | Nuevos empalmes, cadena de cambios | R1: solicitud de cambio con aprobación y validador. R2: impacto calculado antes de aprobar |
| D6 | "Paralelos" sin límite por carrera | El SII no soporta cupo por carrera | Sobrecupo, alumnos fuera de su carrera desplazan a los de la carrera dueña | Cupos por carrera dentro del grupo; suma de cupos validada contra capacidad |
| D7 | Acuerdos de la junta son verbales | No hay registro | Préstamos de salón olvidados o duplicados | Acuerdos registrados y verificados por el validador de salones |
| D8 | Salones sin memoria ni sugerencia | No existe "salón por defecto" | Laboratorios asignados a materias sin laboratorio y viceversa | Salón por defecto y sugerido por historial, tipo y capacidad |
| D9 | No hay re-análisis después de calificaciones | La carga se cierra antes de que acabe el semestre | Grupos saturados o vacíos al iniciar | Etapa de re-análisis: re-importar kardex, recalcular demanda, sugerir ajustes, versionar la carga |
| D10 | Sin trazabilidad | Excel y correo | Imposible saber quién cambió qué | Bitácora inmutable y máquina de estados con historial |
| D11 | Catálogos desactualizados (docentes inactivos, retículas viejas, capacidades) | Nadie es responsable de validarlos | Errores arrastrados cada semestre | Validación obligatoria de catálogos por jefe de departamento antes de abrir la carga |
| D12 | Un solo sistema con años de datos acumulados | SII monolítico y sin limpieza | Lentitud, campos sin uso, reglas ocultas | Nexus carga solo lo necesario, por importación controlada, y nace como nueva fuente de verdad por etapas |

## Qué se conserva del proceso actual

- La secuencia de responsabilidades (coordinador propone, departamento asigna, junta negocia, se publica, se inscribe) es correcta y la gente la conoce. Nexus la formaliza, no la reinventa.
- La prioridad por base de horas es regla institucional y se mantiene como primer criterio.
- La nomenclatura de grupos y claves de materias del SII se conserva para que la publicación sea familiar a docentes y alumnos.
