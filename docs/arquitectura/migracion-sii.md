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
| `--only=<paso>` | Corre solo ese paso; se repite para varios (`--only=organigrama --only=carreras`). Los pasos son `organigrama`, `carreras`, `materias`, `aulas`, `docentes`, `plazas`, `historial_docente`, `alumnos` y `kardex`, en ese orden. Un paso que necesita un mapa de otro se detiene diciendo cuál correr antes en la misma corrida: `materias` necesita organigrama y carreras; `docentes`, organigrama; `plazas`, docentes; `historial_docente`, docentes y materias; `alumnos`, carreras; `kardex`, materias (y los alumnos ya migrados, de esta corrida o de una anterior) |
| `--user=<username>` | Quién aplica los lotes; debe tener permiso de aplicar importaciones |
| `--force` | Obligatorio en producción |

La consola imprime solo cuentas: una fila por lote (filas, válidas, advertencias, errores, altas y cambios), cuántos renglones del SII no se pudieron leer y el total de incidencias. Nunca imprime valores.

Si la exportación ya no cumple el contrato de encabezados (falta un archivo, cambian sus columnas o un archivo viene vacío o ilegible), se detiene antes de escribir nada. Si un paso falla a la mitad de una corrida real, los lotes anteriores ya quedaron aplicados; repetir la migración no los cambia. Al log va solo la clase de la excepción, dónde se lanzó y el SQLSTATE, nunca el mensaje ni los valores.

## Qué deja

- `<carpeta>/_incidencias.csv` (`paso,archivo,fila,campo,nivel,mensaje`): lo que validó cada plantilla, lo que descartó o corrigió cada paso y, una vez por archivo del SII, los renglones que no se pudieron leer (`malformados`, error: no se migran), los reparados por coma decimal y la anotación al final. Una fila se señala por su número en el lote, por una clave de catálogo (materia, aula, carrera, retícula) o por `<archivo>:<línea>` del SII, nunca por el nombre de una persona, su RFC o su número de control; los mensajes de algunas plantillas sí pueden traer un valor del renglón. Se reescribe en cada corrida.
- `<carpeta>/_nexus/`: un CSV intermedio por lote, con los encabezados de su plantilla. Trae datos personales: vive junto a la exportación y nunca sale de `storage/`.
- En una corrida real, cada lote queda en el disco `imports` (`storage/app/imports/`) como evidencia, con su bitácora y sus `import_rows`, igual que uno del asistente.

## Qué escribe cada paso en una segunda corrida

Toda plantilla da de alta lo que falta por su clave natural y, en lo que ya existe, escribe los campos de esta tabla. En ningún paso cambia `is_active` (si el SII dice otra cosa, avisa), no pisa con vacío lo que el archivo trae vacío y no restaura lo borrado (es error).

| Paso | Lote | En lo que ya existe escribe | No toca |
|---|---|---|---|
| `organigrama` | `departments` | Nombre, del curado `organigrama.csv` | Clave y estado |
| | `org_units` | Nombre, tipo y unidad madre, del curado, solo si el Organigrama lo permite (OrgUnitRules: la Dirección y la Subdirección Académica no se mueven ni cambian de tipo; Servicios Escolares no cambia de tipo con jefe) | Estado |
| `carreras` | `careers` | Nombre y departamento, del curado `carreras.csv`; da de alta la oferta en un campus nuevo | Estado y ofertas que ya existen |
| | `study_plans` | Versión; si la exportación marca un plan de nuevo ingreso, ese pasa a ser el actual de la carrera y el anterior deja de serlo | Nombre (el paso no lo trae), carrera (cambiarla es error) y estado |
| `materias` | `subjects` | De la materia: nombre, departamento, horas teóricas y prácticas, créditos y tipo (si lo trae). De su lugar en la retícula: semestre, especialidad (si la trae) y orden en el semestre (si el SII trae `renglon`). El nombre de la especialidad, si el SII lo trae | Estado; un lugar quitado de la retícula (es error) |
| | `pending_placements` | La materia, igual que `subjects`; de un pendiente sin resolver, especialidad, retícula del SII y renglón | Los pendientes resueltos (no se reabren); si la materia ya está en la retícula, no crea pendiente |
| | `subject_aliases` | Nada: solo da de alta las claves que faltan | Las que ya existen |
| `aulas` | `rooms` | Edificio (lo crea si el campus no lo tiene), nombre, tipo, capacidad y departamento dueño, del curado `aulas.csv` | Estado, características y los edificios que ya existen |
| `docentes` | `teachers` | Nombre, correo (si es válido) y fecha de ingreso; agrega las adscripciones nuevas | Estado, grado (el SII no lo trae), adscripción principal; nunca quita adscripciones |
| `plazas` | `positions` | Horas base de la plaza de cada tipo (la suma de las claves de ese tipo) | Tipo y vigencia; nunca quita una plaza que ya no venga |
| `historial_docente` | `teacher_history` | Nada: solo agrega los grupos que faltan | Lo que ya existe |
| `alumnos` | `students` | Nombre, carrera, plan, campus, semestre (si es válido), estatus (si el código del SII es uno de los conocidos), modalidad y motivo del SII | Estado activo y número de control |
| `kardex` | `kardex` | Calificación, resultado y oportunidad de cada alumno, materia y periodo; una materia «cursando» pasa a su resultado cuando llega a la historia | Nunca borra una fila que ya no venga |

Lo más delicado: una segunda corrida devuelve al semestre y al orden del SII las materias que alguien acomodó en el editor de la retícula (D21) y los semestres que se corrigieron a mano en las materias cuyo semestre se tomó por nombre (D18). También devuelve los nombres de materias y aulas al del SII o del curado. Los pendientes resueltos en «Por validar» sobreviven.

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
- **Kárdex** (paso del 9 de noviembre de 2026): confirmar con Servicios Escolares qué significa cada código de `tipo_evaluacion` (sobre todo E1 con estatus A y calificación 0, RC, RU, AC, EA y CG) y el `status_seleccion` S; preguntar por qué hay renglones de `seleccion_materias` de periodos cerrados, con calificación, que no están en la historia (no se migran, K3). El paso guarda una llave por renglón de la historia: el comando sube su límite de memoria a 1 GB.
- **Lo demás** (seguimientos de la revisión): el lector abre cada archivo tres veces; `ext-intl` no está en `composer.json`; dos lotes con el mismo nombre en `_nexus/`; la fusión de materias que ignora el semestre (T7 M1) y el mapa de alias por plan para el kárdex van al plan de la parte 2.
