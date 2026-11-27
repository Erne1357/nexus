# Lineamientos de carga docente: horas frente a grupo

Resumen de lo que dice la normativa oficial sobre cuántas horas debe dar un docente frente a grupo y de cómo las usa Nexus (regla **PL-01**, [reglas-de-negocio.md](reglas-de-negocio.md)). Lo que es de la norma va citado; lo que Nexus decidió por su cuenta va marcado **(supuesto por confirmar)**. Desde la tarea 1.50 HC-08 también se mide contra ellas (ver abajo).

## Fuentes oficiales

- **Reglamento Interior de Trabajo del Personal Docente de los Institutos Tecnológicos** (Recursos Humanos, TecNM, 14 de febrero de 2008): <https://www.tecnm.mx/pdf/jur%C3%ADdico/REGLAMENTO_DOCENTE.pdf>
- **Catálogo de claves de categoría**: Documento del Proceso de Promoción para el Personal Docente de los ITs 2022: <https://www.tecnm.mx/menu/academicos/convocatorias_tecnm/Documento_del_Proceso_de_Promocion_para_el_Persomal_Docente_de_los_ITs_2022.pdf?doc=000858>

## Qué dice el Reglamento

| Artículo | Contenido |
|---|---|
| 140 | El personal docente de asignatura imparte cátedra según el número de horas que especifique su nombramiento. |
| 143 | Profesor de carrera de educación superior, horas frente a grupo por jornada (tiempo completo / tres cuartos / medio tiempo): **titular 22 / 16 / 12**, **asociado 24 / 18 / 12**, **asistente 26 / 20 / 14**. |
| 144 | Profesor investigador titular: **12 / 10 / 6**. |
| 146 | Técnico docente de carrera: **36 / 28 / 18**. |
| 147 | Si las horas frente a grupo asignadas a un profesor de carrera no coinciden con las de su categoría, se ajusta al límite inmediato superior o inferior más cercano, «sin que la diferencia exceda de dos horas»: **±2**. El resto de sus horas se dedica a apoyo a la docencia. |
| 149 | Quien tiene plaza de carrera de medio tiempo o de tres cuartos y además horas de asignatura cubre su plaza de carrera y, de las horas de asignatura, da frente a grupo: 1→1, 2→1, 3→2, 4→2, 5→3, 6→3, 7→4, 8→4, 9→5. Las demás horas van a apoyo a la docencia (art. 147). |
| 155 | Profesores y técnicos docentes de asignatura laboran las horas de su nombramiento, entre 1 y 19, y a lo más tres materias diferentes. |
| 156 | Los de carrera laboran: tiempo completo 40 h a la semana, tres cuartos 30 h, medio tiempo 20 h; a lo más tres materias diferentes. |

Las 40, 30 y 20 horas son la **jornada** (lo que se contrata y lo que Nexus llama «horas de plaza»); las horas **frente a grupo** son solo la parte de clases.

## Clave de categoría de la plaza

Una clave del SII es `E3` + jornada (2 dígitos: **35** asignatura, **36** medio tiempo, **37** tres cuartos, **38** tiempo completo) + categoría (2 dígitos); por ejemplo `E3817` es tiempo completo, titular C.

| Categoría | Claves (últimos 2 dígitos) |
|---|---|
| Profesor de carrera asociado A / B / C | 07 / 09 / 11 |
| Profesor de carrera titular A / B / C | 13 / 15 / 17 |
| Técnico docente asociado | 37 / 39 / 41 |
| Técnico docente titular | 43 / 45 / 75 |
| Investigador titular A / B / C | 59 / 61 / 63 |
| Asignatura A / B / C (E35) | 19 / 21 / 25 |
| Técnico docente de asignatura (E35) | 05 / 07 / 09 |

El catálogo no trae claves de «asistente»: la fila del art. 143 existe en la norma pero ninguna plaza la usa. Las categorías de dos letras del SII (IA, IP, IS, IT…) son personal de apoyo y asistencia a la educación.

## Cómo lo aplica Nexus (PL-01)

La categoría de cada plaza se guarda en `teacher_positions.category` (la importa el paso de plazas del SII y el comando `imports:fill-position-categories` la llena en las ya importadas). `Faculty\Rules\TeachingHours` da las horas frente a grupo de un docente; la banda de la orden de selección (SEL-05) es ese valor ±2.

- Plaza de carrera: la tabla de su familia y jornada (arts. 143, 144 y 146).
- Asignatura sola: todas sus horas (arts. 140 y 155).
- Asignatura más plaza de carrera: la tabla del art. 149. **(supuesto por confirmar)** Se aplica también con tiempo completo, aunque el artículo solo nombra medio tiempo y tres cuartos, y de 10 horas en adelante la mitad hacia arriba (la tabla llega a 9).
- Plaza de carrera sin categoría conocida: titular. **(supuesto por confirmar)**
- Apoyo y asistencia: 0, porque no es plaza docente. **(supuesto por confirmar)**
- Honorarios: 0, lo que ya valían sus horas base; su tope sigue siendo `staffing.honorarios_max_hours` en HC-08. **(supuesto por confirmar)**
- HC-08 (tarea 1.50, confirmado el 26 de noviembre de 2026): se mide contra estas horas frente a grupo + 2 (art. 147), con el mismo respaldo del CSV que la selección, y dejó de ser tope duro: pasarse se permite con un oficio de conformidad del docente, que Nexus todavía no registra («oficio pendiente»). Ver HC-08 en [reglas-de-negocio.md](reglas-de-negocio.md).
- Varias plazas: se suman las horas de cada una (tomadas por familia y jornada).
- Sin plazas en Nexus, o con plazas que dan 0 h (solo apoyo u honorarios), el objetivo sale de las horas del CSV de la selección: 40 → 22, 30 → 16, 20 → 12 y cualquier otro valor n → n. **(supuesto por confirmar)**
- El escalón de SEL-06 sigue saliendo de las horas del CSV: no cambia.
