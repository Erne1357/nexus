# Migración del SII

`imports:migrate-sii` lee una exportación cruda del SII y la pasa por el motor de importación, paso por paso, con las mismas plantillas, reglas y bitácora que el asistente (tarea 1.16). Es repetible: se corre ahora para poblar Nexus y otra vez en el corte oficial. Las reglas de cada paso están en [reglas-de-negocio.md](../dominio/reglas-de-negocio.md), «Reglas de datos».

> **No la corras sobre una base con curaduría sin decidir antes, paso por paso, qué debe ganar** (D23, abajo). Una segunda corrida pisa lo que se editó en Nexus en los campos de la tabla. La corrida en seco muestra cuántos cambios haría.

## Antes de correr

1. La exportación vive en `storage/app/imports/sii/<fecha>/`, fuera de git, porque trae datos personales. Nunca se copia al repositorio ni a fixtures.
2. Perfílala primero, sin tocar la base: `.github/scripts/sii-profile.sh storage/app/imports/sii/<fecha>` deja `_perfil.md` en esa carpeta (ver [testing.md](testing.md), «Perfilar exportaciones del SII»). Compara sus renglones malformados con la corrida anterior.
3. Revisa los curados de `app/Modules/Imports/Sii/Curated/` (`organigrama.csv`, `carreras.csv`, `planes.csv`, `aulas.csv`). Son catálogos hechos a mano, sin datos personales y en git: cada clave del SII tiene su renglón, y si falta o sobra uno, el paso se detiene antes de escribir. Guárdalos en UTF-8 **sin BOM**: Excel lo agrega y hoy rompe la lectura (ver «Pendientes antes del corte»).
4. Necesitas un usuario con permiso de aplicar importaciones (el admin). En una corrida real, los lotes encolan al terminar el recálculo del avance de los alumnos y el alta de usuarios de los docentes (que necesita `NEXUS_DEFAULT_PASSWORD`): corren cuando la cola los toma (`docker compose logs -f queue`).

## Cómo se corre

Primero en seco, siempre:

```bash
docker compose exec app php artisan imports:migrate-sii storage/app/imports/sii/<fecha> --user=admin --dry-run
```

Si las cuentas cuadran, la corrida real es la misma línea sin `--dry-run`.

| Opción | Qué hace |
|---|---|
| `--dry-run` | Corre todo dentro de una transacción que se revierte, sin cola, y borra los archivos subidos. Sí deja `_incidencias.csv` y `_nexus/`. La tabla de la consola dice cuántas altas y cambios haría cada lote |
| `--only=<paso>` | Corre solo ese paso; se repite para varios (`--only=organigrama --only=carreras`). Los pasos son `archivo`, `organigrama`, `carreras`, `materias`, `aulas`, `docentes`, `plazas`, `historial_docente`, `alumnos`, `kardex` y `verificacion`, en ese orden. Un paso que necesita un mapa de otro se detiene diciendo cuál correr antes en la misma corrida: `materias` necesita organigrama y carreras; `docentes`, organigrama; `plazas`, docentes; `historial_docente`, docentes y materias; `alumnos`, carreras; `kardex`, materias (y los alumnos ya migrados, de esta corrida o de una anterior). `verificacion` no se detiene: si en la corrida no corrieron `archivo` y `kardex`, avisa que no verificó (ver «Verificación contra el SII original») |
| `--user=<username>` | Quién aplica los lotes; debe tener permiso de aplicar importaciones |
| `--force` | Obligatorio en producción |

La consola imprime solo cuentas: una fila por lote (filas, válidas, advertencias, errores, altas y cambios), el resumen de la verificación, cuántos renglones del SII no se pudieron leer y el total de incidencias. Nunca imprime valores. La última línea dice cómo terminó: «Migración terminada», «Migración terminada con diferencias» si la verificación encontró algo que no cuadra (la salida sigue siendo 0 y nada se revierte) o «Ensayo terminado, sin cambios».

Si la exportación ya no cumple el contrato de encabezados (falta un archivo, cambian sus columnas o un archivo viene vacío o ilegible), se detiene antes de escribir nada. Si un paso falla a la mitad de una corrida real, los lotes anteriores ya quedaron aplicados; repetir la migración no los cambia. Al log va solo la clase de la excepción, dónde se lanzó y el SQLSTATE, nunca el mensaje ni los valores.

## Qué deja

- `<carpeta>/_incidencias.csv` (`paso,archivo,fila,campo,nivel,mensaje`): lo que validó cada plantilla, lo que descartó o corrigió cada paso y, una vez por archivo del SII, los renglones que no se pudieron leer (`malformados`, error: no se migran), los reparados por coma decimal y la anotación al final. Una fila se señala por su número en el lote, por una clave de catálogo (materia, aula, carrera, retícula) o por `<archivo>:<línea>` del SII, nunca por el nombre de una persona o su RFC; solo los errores de la verificación señalan al alumno por su número de control. Los mensajes de algunas plantillas sí pueden traer un valor del renglón. El nivel es `error`, `warning` o `info` (el resumen de la verificación). Se reescribe en cada corrida.
- El esquema `sii_verificacion` con el resultado de la verificación (ver abajo).
- `<carpeta>/_nexus/`: un CSV intermedio por lote, con los encabezados de su plantilla. Trae datos personales: vive junto a la exportación y nunca sale de `storage/`.
- En una corrida real, cada lote queda en el disco `imports` (`storage/app/imports/`) como evidencia, con su bitácora y sus `import_rows`, igual que uno del asistente.

## Archivo del SII original (`sii_archivo`)

El usuario decidió el 10 de noviembre de 2026 (confirmado) guardar dentro de Nexus la exportación original, renglón por renglón, para rastrear cualquier dato hasta su origen sin abrir los CSV. Lo hace el paso `archivo` (`ArchiveStep`, que escribe con la Action `ArchiveSiiExport`), el primero de la corrida, para que el original quede guardado aunque un paso posterior se detenga. No pasa por el motor de importación porque no transforma nada.

- **Qué guarda.** En el esquema de PostgreSQL `sii_archivo`, una tabla por archivo de la exportación, con el nombre normalizado del archivo (`alumnos_todos`, `historia_alumno`, …). Cada tabla tiene `_linea` (el número de línea en el CSV, donde el encabezado es la 1; es la llave primaria) y las columnas del contrato como `text`, en su orden. Los valores son los que entrega el lector del SII: en UTF-8, recortados y con la coma decimal reparada, sin otra conversión. Un campo vacío es `''`, nunca null.
- **Qué no guarda, y por qué.** El `nip` de `alumnos_todos`, porque es la contraseña del alumno en el SII, y la `foto` y la `firma` de `alumnos_todos` y `docentes`, porque son la imagen de la persona y no sirven para rastrear un dato. El lector las descarta en cuanto lee el renglón; la lista está en un solo lugar, `ArchiveSiiExport::EXCLUDED`. Tampoco están los renglones que el lector no pudo leer: su cuenta queda en `_cargas.ilegibles`, sus líneas en `_incidencias.csv` («malformados») y el hueco en `_linea` dice dónde estaban.
- **`sii_archivo._cargas`.** Una fila por archivo de la última carga: `archivo` (el nombre de su tabla), `nombre_en_carpeta`, `renglones`, `ilegibles`, `columnas_excluidas`, `carpeta` de la exportación y `cargado_en`.
- **Cada corrida lo reemplaza completo**, en una sola transacción: borra todas las tablas del esquema (también la de un archivo que esta vez no vino, para que refleje solo esta exportación) y las vuelve a llenar con `COPY`, por bloques y sin leer el archivo entero a memoria. Si algo falla, queda el archivo anterior y la corrida se detiene en el paso `archivo`, antes de migrar nada. Las tablas se borran sin `CASCADE`: si alguien crea una vista sobre ellas, la carga falla en lugar de borrarla. Con datos sintéticos, dos millones de renglones de la historia entran en unos 32 segundos.
- **En seco** no escribe: lee cada archivo y dice cuántos renglones guardaría. `--only=archivo` corre solo este paso.
- **En la consola** sale una fila por archivo con la plantilla `sii_archivo`: filas, válidas y altas son los renglones guardados (o los que guardaría en seco). Como el paso lee completo cada archivo, las incidencias de lectura (malformados, reparados y anotación al final) quedan a su nombre en `_incidencias.csv`.
- **No se edita.** Solo lo consulta el administrador directo en la base: no tiene pantalla y no lo lee ninguna Query. Vive fuera de las migraciones: el paso crea el esquema si no existe y `migrate:fresh` no lo toca, porque solo borra las tablas de los esquemas del `search_path`.

Para consultarlo:

```sql
-- Qué trajo la última carga.
select * from sii_archivo._cargas order by archivo;

-- La historia de un alumno tal como venía en el SII, con su línea en el CSV.
select _linea, periodo, materia, grupo, calificacion, tipo_evaluacion, estatus_materia
from sii_archivo.historia_alumno
where no_de_control = '<número de control>'
order by periodo, materia;
```

## Verificación contra el SII original (`sii_verificacion`)

El usuario decidió el 10 de noviembre de 2026 (confirmado) que **cada corrida compara el original con Nexus y avisa si algo no cuadra**, por el temor a perder datos al migrar. Lo hace el paso `verificacion` (`VerificationStep`, que escribe con la Action `VerifySiiMigration`), el último de la corrida, cuando ya se aplicó todo. Como el paso `archivo`, no pasa por el motor de importación. Nunca revierte nada de lo aplicado.

- **Qué necesita.** El archivo de esta misma corrida (que haya corrido `archivo`), la historia del SII en la exportación y el kárdex aplicado en esta corrida (que haya corrido `kardex`, que además deja los mapas de claves con que se explica lo que no pasó; que sea de esta corrida y no de una anterior es supuesto por confirmar). Si falta algo, no se detiene: deja en `_incidencias.csv` y en la consola «No se verificó la migración contra el SII» con la razón, y en la tabla de la consola una fila `sii_verificacion | sin verificar`. **En seco no verifica**: nada se escribió.
- **El avance al día.** Antes de comparar recalcula el avance de todos los alumnos ahí mismo, sin esperar al job `RecomputeStudentProgress` que encolaron las plantillas (la cola puede no haberlo corrido aún). Llama directo a `RefreshStudentProgress`, lo mismo que hace el job; no usa `dispatchSync()`, porque con el candado del job el recálculo se saltaría si la cola ya está recalculando. Cuánto tardó queda en `_incidencias.csv` (`info`, fila `avance`) y en la consola.
- **Qué compara, renglón por renglón (`sii_verificacion.materias`).** Un renglón por renglón de `sii_archivo.historia_alumno`, con la misma `_linea`. Se une con el kárdex de Nexus por alumno, periodo y clave del SII (`upper(kardex_records.sii_subject_code) = upper(historia.materia)`); el periodo es el que guarda Nexus: el del SII si es un periodo (año de 1964 al próximo y 1, 2 o 3), si no, ninguno, así que un renglón con un periodo que no existe se une con la fila sin periodo y no sale como «no pasó». Columnas: `no_de_control`, `periodo` (el del SII), `periodo_nexus`, `clave_sii`, `estatus_sii`, `reticula_sii` y `creditos_sii` (la retícula del alumno en el SII y los créditos de la clave en ella), `plan_nexus`, `clave_nexus`, `creditos_nexus` y `resultado_nexus` (a qué llegó en Nexus), `clave_nexus_esperada` (la materia de Nexus que le toca a la clave del SII con el plan del alumno, como la traduce el kárdex) y `diagnostico`.
- **Qué compara, alumno por alumno (`sii_verificacion.alumnos`).** Un renglón por alumno de `sii_archivo.alumnos_todos`. Según el SII (regla confirmada), un alumno está completo cuando los créditos de sus materias aprobadas (`estatus_materia` A) que están en su propia retícula (`materias_carreras` de su carrera y retícula) llegan a los `creditos_totales` de esa retícula; una materia aprobada dos veces cuenta una vez. Según Nexus, cuando `student_progress.credits_approved` llega a `study_plans.total_credits` (el avance ya cuenta lo aprobado en materias pendientes del plan, «Por validar»). Columnas: `reticula_sii`, `estatus_sii`, `creditos_requeridos_sii`, `creditos_campo_sii` (el campo `creditos_aprobados` del SII, para comparar), `creditos_sii`, `materias_sii`, `completo_sii`, `en_nexus`, `plan_nexus`, `estatus_nexus`, `creditos_requeridos_nexus`, `creditos_nexus`, `materias_nexus`, `completo_nexus` (vacío si no está en Nexus) y `creditos_perdidos` (los del SII menos los de Nexus). Un plan sin créditos totales, o un alumno sin avance, no está completo.
- **Repetidos en el archivo.** Un alumno o una retícula que el SII trae dos veces cuentan una vez, el primero por línea; una clave repetida en una retícula, con sus créditos mayores.

Cada renglón de la historia lleva uno de estos diagnósticos; se evalúan en este orden y gana el primero que aplica:

| Diagnóstico | Qué significa | Aviso |
|---|---|---|
| `alumno no está en Nexus` | El número de control no está en Nexus (su retícula no se migra, por ejemplo) | No |
| `no pasó: el mismo alumno tiene la misma materia de Nexus en el mismo periodo (se queda uno)` | No llegó al kárdex, pero el alumno ya tiene en ese periodo la materia de Nexus que le tocaba: dos claves del SII son la misma materia y el kárdex guarda una fila por alumno, materia y periodo | No |
| `no pasó: la clave del SII no está en ninguna retícula` | No llegó y su clave no está en `materias_carreras`, así que no tiene materia de Nexus | No |
| `no pasó: sin explicar` | No llegó y nada de lo anterior lo explica | **Error**, uno por clave del SII con cuántos renglones y sus primeras líneas |
| `pendiente del plan: cuenta` | Llegó a una materia pendiente del plan del alumno («Por validar»): cuenta en el avance | No |
| `fuera de la retícula del plan: no cuenta` | Llegó a una materia que no está en la retícula ni en los pendientes del plan del alumno: no cuenta en el avance | No |
| `otra clave del SII es la misma materia: cuenta una vez` | El alumno aprobó esa materia de Nexus con dos claves del SII: en Nexus cuenta una vez | No |
| `créditos distintos` | Los créditos de la clave en la retícula del alumno en el SII no son los de la materia de Nexus (o la clave no está en su retícula) | Advertencia, una por clave del SII, materia de Nexus y créditos |
| `igual` | Llegó a una materia de la retícula del plan del alumno, con los mismos créditos | No |

Además, un **error por cada alumno completo en el SII e incompleto en Nexus**, con su número de control como fila (`_incidencias.csv` vive en `storage/`, fuera de git) y sus créditos en cada lado. Un alumno que no está en Nexus no da error: se cuenta en el resumen (supuesto por confirmar: la mayoría son de retículas que no se migran, y el paso `alumnos` ya avisa por retícula). Si hay algún error, la corrida termina «con diferencias», con salida 0 (supuesto por confirmar: todo se aplicó).

**El resumen** va a `_incidencias.csv` (nivel `info`, fila `resumen`) y a la consola, debajo de la tabla: cuántos renglones y alumnos se compararon, cuántos renglones hay de cada diagnóstico y, por estatus del SII, cuántos alumnos hay, cuántos están completos en el SII, cuántos en Nexus y cuántos no están en Nexus. La tabla de la consola trae dos filas con la plantilla `sii_verificacion`: `materias` (filas = renglones comparados, advertencias = créditos distintos, errores = sin explicar) y `alumnos` (filas = alumnos, errores = completos en el SII e incompletos en Nexus); las altas son los renglones escritos.

**Dónde queda.** En el esquema de PostgreSQL `sii_verificacion`, con las reglas de `sii_archivo`: cada corrida lo reemplaza completo en una sola transacción (si algo falla, queda la verificación anterior), borra sus tablas sin `CASCADE` (una vista sobre ellas hace fallar el paso en lugar de borrarse), vive fuera de las migraciones, no se edita y no lo lee ninguna pantalla ni Query. `materias` tiene llave primaria `_linea` e índices por número de control y por diagnóstico y clave; `alumnos`, llave primaria `no_de_control`. Antes de comparar, el paso actualiza las estadísticas (`analyze`) de las tablas que lee, recién cargadas, para que PostgreSQL las una por hash; no hacen falta índices sobre `sii_archivo`. Con datos sintéticos del tamaño de la exportación real (68 000 alumnos, 2 040 000 renglones de historia y de kárdex), la verificación tardó 62 segundos: 42 de recálculo del avance y 20 de comparación. Sin estadísticas al día de alumnos, materias y planes, la misma comparación pasaba de 7 minutos.

Para consultarla:

```sql
-- Cuántos renglones de la historia hay de cada diagnóstico.
select diagnostico, count(*) from sii_verificacion.materias group by diagnostico order by count(*) desc;

-- Los que no pasaron sin explicación, con su línea en el CSV para buscarla en sii_archivo.historia_alumno.
select _linea, no_de_control, periodo, clave_sii, estatus_sii, clave_nexus_esperada
from sii_verificacion.materias
where diagnostico = 'no pasó: sin explicar'
order by clave_sii, _linea;

-- Los alumnos completos en el SII e incompletos en Nexus, y lo que pasó con su historia.
select * from sii_verificacion.alumnos where completo_sii and en_nexus and not completo_nexus order by creditos_perdidos desc;
select * from sii_verificacion.materias where no_de_control = '<número de control>' order by periodo, clave_sii;
```

## Qué escribe cada paso en una segunda corrida

Toda plantilla da de alta lo que falta por su clave natural y, en lo que ya existe, escribe los campos de esta tabla. En ningún paso cambia `is_active` (si el SII dice otra cosa, avisa), no pisa con vacío lo que el archivo trae vacío y no restaura lo borrado (es error).

| Paso | Lote | En lo que ya existe escribe | No toca |
|---|---|---|---|
| `organigrama` | `departments` | Nombre, del curado `organigrama.csv` | Clave y estado |
| | `org_units` | Nombre, tipo y unidad madre, del curado, solo si el Organigrama lo permite (OrgUnitRules: la Dirección y la Subdirección Académica no se mueven ni cambian de tipo; Servicios Escolares no cambia de tipo con jefe) | Estado |
| `carreras` | `careers` | Nombre y departamento, del curado `carreras.csv`; da de alta la oferta en un campus nuevo | Estado y ofertas que ya existen |
| | `study_plans` | Versión, créditos totales (`creditos_totales`, si el SII los trae) y si es retícula histórica (`is_historical`, ver abajo); si la exportación marca un plan de nuevo ingreso, ese pasa a ser el actual de la carrera y el anterior deja de serlo | Nombre (el paso no lo trae), carrera (cambiarla es error) y estado |
| `materias` | `subjects` | De la materia: nombre, departamento, horas teóricas y prácticas, créditos y tipo (si lo trae). De su lugar en la retícula: semestre, especialidad (si la trae) y orden en el semestre (si el SII trae `renglon`). El nombre de la especialidad, si el SII lo trae | Estado; un lugar quitado de la retícula (es error) |
| | `pending_placements` | La materia, igual que `subjects`; de un pendiente sin resolver, especialidad, retícula del SII, renglón y semestre sugerido (tal cual: vacío lo borra) | Los pendientes resueltos (no se reabren); si la materia ya está en la retícula, no crea pendiente |
| | `subject_aliases` | Nada: solo da de alta las claves que faltan | Las que ya existen |
| `aulas` | `rooms` | Edificio (lo crea si el campus no lo tiene), nombre, tipo, capacidad y departamento dueño, del curado `aulas.csv` | Estado, características y los edificios que ya existen |
| `docentes` | `teachers` | Nombre, correo (si es válido) y fecha de ingreso; agrega las adscripciones nuevas | Estado, grado (el SII no lo trae), adscripción principal; nunca quita adscripciones |
| `plazas` | `positions` | Horas base de la plaza de cada tipo (la suma de las claves de ese tipo) | Tipo y vigencia; nunca quita una plaza que ya no venga |
| `historial_docente` | `teacher_history` | Nada: solo agrega los grupos que faltan | Lo que ya existe |
| `alumnos` | `students` | Nombre, carrera, plan, campus, semestre (si es válido), estatus (si el código del SII es uno de los conocidos), modalidad y motivo del SII | Estado activo y número de control |
| `kardex` | `kardex` | Calificación, resultado y oportunidad de cada alumno, materia y periodo (o sin periodo, si el SII trae uno que no existe); una materia «cursando» pasa a su resultado cuando llega a la historia | Nunca borra una fila que ya no venga, ni la sin periodo cuando el SII ya trae el bueno: esa se borra a mano |
| `verificacion` | — | Reemplaza completo el esquema `sii_verificacion` | Nada de lo aplicado |

Lo más delicado: una segunda corrida devuelve al semestre y al orden del SII las materias que alguien acomodó en el editor de la retícula (D21). También devuelve los nombres de materias y aulas al del SII o del curado. Los pendientes resueltos en «Por validar» sobreviven.

**Una base migrada antes del 10 de noviembre de 2026 se migra de nuevo desde cero** (`php artisan migrate:fresh` y una corrida completa, no una segunda corrida encima). Hasta esa fecha la migración le ponía a una materia sin semestre el semestre de otra con el mismo nombre, aun de otra carrera, y la dejaba en `study_plan_subjects`. Una corrida nueva ya no lo hace (ver «Retículas históricas y vivas»), pero tampoco quita esos lugares: la plantilla de pendientes, al ver que la materia ya está en la retícula del plan, solo avisa «ya está en la retícula» y no crea el pendiente, así que esas materias se quedarían con el semestre prestado en lugar de pasar a «Por validar».

## Retículas históricas y vivas (D18)

La migración nunca decide un semestre (confirmado, 10 de noviembre de 2026): lo que el SII trae con semestre 0 no entra a la retícula y va a «Por validar». Qué más pasa depende de la retícula, y el paso `carreras` lo decide en cada corrida:

- **Inscritos en el periodo actual.** El paso lee `seleccion_materias` una sola vez (solo `periodo` y `no_de_control`), sin los veranos (periodos que terminan en 2), y se queda con los números de control de los dos periodos regulares más recientes (terminan en 1 o 3). El periodo actual es el regular más reciente; si tiene menos de la mitad de alumnos distintos que el regular anterior, todavía se está abriendo y cuentan los inscritos en los dos (el umbral de la mitad es supuesto por confirmar). Con la exportación del 10 de noviembre de 2026 queda solo el 20263 (7 206 alumnos contra 6 135 del 20261); al corte, con el 20271 recién abierto, contarían el 20271 y el 20263. Los cruza con `alumnos_todos` para saber qué retículas del SII tienen inscritos; un plan de Nexus que junta varias retículas del SII es vivo si cualquiera tiene uno. El aviso «El periodo actual es el …» (o «El periodo … todavía se está abriendo») de `_incidencias.csv` dice qué periodo o periodos tomó, con cuántos alumnos, y cuántos planes quedaron históricos. Como este paso lee la selección antes que `kardex`, sus renglones ilegibles salen en `_incidencias.csv` bajo `carreras`.
- **Retícula histórica** (ningún inscrito y no es vigente ni de nuevo ingreso; `study_plans.is_historical`): se migra tal cual viene del SII. Un plan vigente (con alumnos activos) o de nuevo ingreso nunca es histórico, aunque nadie esté inscrito todavía. Sus materias con semestre lo conservan; las de semestre 0 y las de estatus `N` quedan en «Por validar» sin semestre sugerido, con un solo aviso por plan. No cuentan en el aviso de la lista de planes, porque no son trabajo para Servicios Escolares mientras no vuelva un alumno; si vuelve y aparece inscrito, la siguiente corrida la marca viva.
- **Retícula viva** (con un inscrito, o vigente o de nuevo ingreso aunque nadie esté inscrito todavía): cada materia de semestre 0 va a «Por validar» con el semestre sugerido (el más frecuente, en empate el menor, entre las materias con el mismo nombre y semestre de la misma carrera; nunca de otra) o sin sugerencia, con un aviso por materia. Una de estatus `N` sugiere su semestre del SII, si lo trae, y si no, el de su misma carrera. Servicios Escolares lo confirma o lo cambia. La sugerencia se recalcula en cada corrida: si el plan pasa a histórico, se borra.
- **Sin `seleccion_materias`** (o sin un periodo válido) ningún plan es histórico, y la corrida lo avisa una vez.

En todos los casos lo aprobado en una materia de «Por validar» cuenta en el avance del alumno y el kárdex la encuentra por su clave del SII.

## Decisión pendiente: qué gana en cada paso (D23)

El usuario decidió el 5 de noviembre de 2026 que si una segunda corrida pisa lo que se editó en Nexus se decide **paso por paso, más adelante** (en el corte). Lo que espera: los catálogos que se limpian en Nexus (aulas, edificios, retículas) se quedan con lo de Nexus, y lo que cambia en el SII (alumnos, docentes, horarios) probablemente toma lo del SII. Hoy el código no tiene esa opción: en cada paso gana el SII o el curado en los campos de la tabla.

| Paso | Lo que espera el usuario | Hoy |
|---|---|---|
| `organigrama` | Por decidir (no lo mencionó; es un catálogo curado) | Gana el curado |
| `carreras` | Por decidir (no lo mencionó; es un catálogo curado) | Gana el curado; el plan actual, la exportación |
| `materias` | Se queda Nexus (retículas) | Gana el SII |
| `aulas` | Se queda Nexus (aulas y edificios) | Gana el curado; los edificios que existen no se tocan |
| `docentes` | Probablemente el SII | Gana el SII |
| `plazas` | Por decidir (no lo mencionó) | Gana el SII; no quita plazas |
| `historial_docente` | El SII (horarios) | Solo agrega |
| `alumnos` | Probablemente el SII | Gana el SII |
| `kardex` | Por decidir (no lo mencionó; el kárdex no se edita en Nexus) | Gana el SII; no borra filas |

Hasta decidirlo: corre en seco, mira los «Cambios» de cada lote y no hagas la corrida real de un paso cuyos cambios pisarían curaduría, o córrela solo con los pasos que sí deben tomar el SII (`--only`). La opción de conservar lo de Nexus por paso queda como issue de seguimiento.

## Pendientes antes del corte

De la revisión de la rama (5 de noviembre de 2026), para hacer antes de la corrida oficial:

- **Curados:** `SiiMigration::curated()` no revisa que abra el archivo, el BOM ni encabezados repetidos (un BOM de Excel da un ErrorException). En `organigrama.csv`: claves repetidas, un `destino` desconocido que se trata como omitir, `departamento_de` sin revisar y valores laxos («Sí» con acento cuenta como No); hacerlos estrictos como en `aulas.csv`. `SiiCuratedMismatch` del organigrama no limita cuántas claves lista.
- **Perfilador y lector:** el perfilador corta en la primera línea ilegible después de un renglón en blanco y cuenta el resto como anotación al final; la migración sigue leyendo los renglones que vengan después (y cuenta como malformadas las líneas ilegibles). Si el perfil muestra una anotación al final de más de unas líneas, revísala antes de migrar.
- **Fallas secundarias del motor:** `ValidateImportBatch` y `ApplyImportBatch` todavía mandan al log con `report()` las fallas al marcar un lote fallido (la original sí llega limpia a la migración), y `ApplyImportBatch` guarda el mensaje de la excepción en el lote.
- **Plazas** (paso del 6 de noviembre de 2026): confirmar con Recursos Humanos que E35, E36, E37 y E38 son asignatura, medio tiempo, tres cuartos y tiempo completo, que la jornada de apoyo es de 36 horas y qué significan los códigos 10, 20 y 95 de `tipo_nombramiento`; averiguar por qué 117 docentes con grupos en 20263 no están en `docentes2` (se quedan sin plaza y sin horas base).
- **Kárdex** (paso del 9 de noviembre de 2026): confirmar con Servicios Escolares qué significa cada código de `tipo_evaluacion` (sobre todo E1 con estatus A y calificación 0, RC, RU, AC, EA y CG) y el `status_seleccion` S; preguntar por qué hay renglones de `seleccion_materias` de periodos cerrados, con calificación, que no están en la historia (no se migran, K3). El paso guarda una llave por renglón de la historia: el comando sube su límite de memoria a 1 GB. Con la exportación del 9 de noviembre de 2026 (2 042 299 filas), la corrida real del kárdex tardó 2 horas 8 minutos (unos 4 de lectura, 44 de validación y 80 de aplicación); en seco no es práctica, porque todo va en una sola transacción y a los 50 minutos seguía validando: ensaya el kárdex con una corrida real sobre una base de prueba.
- **Retículas** (9 de noviembre de 2026): al fundir variantes de una materia que reparten distinto las horas, queda la de la presencial con sus horas (supuesto por confirmar con Servicios Escolares). Cada fusión deja un aviso «son la misma materia con horas distintas» en `_incidencias.csv`; hay que revisarlas, junto con las materias que el SII trae varias veces en una misma retícula. Desde el 10 de noviembre de 2026 ningún lugar toma su semestre de otra materia: ver «Retículas históricas y vivas». Una clave con horas distintas según el plan (una clave del SII repetida en retículas con otras horas, o igual a una clave TecNM, como ACA-0909 en ICBS-2024-249) queda con las de más planes y deja el aviso «trae créditos/teoría/práctica distintos según el plan». La corrida del 9 de noviembre de 2026 deja la lista completa de lo que la migración juntó, corrigió o no pasó en una página de revisión, con estado y nota por caso.
- **Avance contra el SII** (10 de noviembre de 2026): al comparar el SII crudo con Nexus, 3 691 egresados que en el SII juntan los créditos de su retícula salían incompletos en Nexus. Las causas: lo aprobado en «Por validar» no contaba (3 280), dos materias distintas de una retícula con la misma clave TecNM se juntaban (IIE/41 y ISC/44) y los renglones con un periodo que no existe no pasaban. Ahora lo aprobado en «Por validar» cuenta, el plan lleva `creditos_totales` y esos renglones pasan sin periodo. Al repetir la comparación quedaban 99 egresados de ILO/36: los lugares con estatus `N` no se migraban y se perdían dos materias de su kárdex; ahora pasan a «Por validar». Falta que Servicios Escolares capture el periodo bueno de esos renglones (la lista está en `_incidencias.csv`, por línea). Si después corrige el periodo en el SII, la siguiente corrida agrega la fila con su periodo y deja la sin periodo, que se borra a mano.
- **Lo demás** (seguimientos de la revisión): el lector abre cada archivo tres veces; `ext-intl` no está en `composer.json`; dos lotes con el mismo nombre en `_nexus/`; la fusión de materias que ignora el semestre (T7 M1) va al plan de la parte 2. El mapa de alias por plan para el kárdex ya está: el kárdex traduce cada clave del SII con el plan del alumno.
