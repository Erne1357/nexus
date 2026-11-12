# Migración del SII

`imports:migrate-sii` lee una exportación cruda del SII y la pasa por el motor de importación, paso por paso, con las mismas plantillas, reglas y bitácora que el asistente (tarea 1.16). Es repetible: se corre ahora para poblar Nexus y otra vez en el corte oficial. Las reglas de cada paso están en [reglas-de-negocio.md](../dominio/reglas-de-negocio.md), «Reglas de datos».

> **No la corras sobre una base con curaduría sin decidir antes, paso por paso, qué debe ganar** (D23, abajo). Una segunda corrida pisa lo que se editó en Nexus en los campos de la tabla. La corrida en seco muestra cuántos cambios haría.

## Antes de correr

1. La exportación vive en `storage/app/imports/sii/<fecha>/`, fuera de git, porque trae datos personales. Nunca se copia al repositorio ni a fixtures.
2. Perfílala primero, sin tocar la base: `.github/scripts/sii-profile.sh storage/app/imports/sii/<fecha>` deja `_perfil.md` en esa carpeta (ver [testing.md](testing.md), «Perfilar exportaciones del SII»). Compara sus renglones malformados con la corrida anterior.
3. Revisa los curados de `app/Modules/Imports/Sii/Curated/` (`organigrama.csv`, `carreras.csv`, `planes.csv`, `aulas.csv`). Son catálogos hechos a mano, sin datos personales y en git: cada clave del SII tiene su renglón, y si falta o sobra uno, el paso se detiene antes de escribir. Guárdalos en UTF-8 **sin BOM**: Excel lo agrega y hoy rompe la lectura (ver «Pendientes antes del corte»).
4. Necesitas un usuario con permiso de aplicar importaciones (el admin). En una corrida real, los lotes encolan al terminar el recálculo del avance de los alumnos y el alta de usuarios de los docentes (que necesita `NEXUS_DEFAULT_PASSWORD`), y el paso `cuentas_alumnos` encola las cuentas de los alumnos: corren cuando la cola los toma (`docker compose logs -f queue`). Las cuentas de ~68,000 alumnos ocupan a un solo worker unos 52 minutos (ver «Cuentas de los alumnos»); con `docker compose up -d --scale queue=4` bajan a unos 13.

## Cómo se corre

Primero en seco, siempre:

```bash
docker compose exec app php artisan imports:migrate-sii storage/app/imports/sii/<fecha> --user=admin --dry-run
```

Si las cuentas cuadran, la corrida real es la misma línea sin `--dry-run`.

| Opción | Qué hace |
|---|---|
| `--dry-run` | Corre todo dentro de una transacción que se revierte, sin cola, y borra los archivos subidos. Sí deja `_incidencias.csv` y `_nexus/`. La tabla de la consola dice cuántas altas y cambios haría cada lote |
| `--only=<paso>` | Corre solo ese paso; se repite para varios (`--only=organigrama --only=carreras`). Los pasos son `archivo`, `organigrama`, `carreras`, `materias`, `seriacion`, `aulas`, `docentes`, `plazas`, `historial_docente`, `alumnos`, `kardex`, `cuentas_alumnos` y `verificacion`, en ese orden. Un paso que necesita un mapa de otro se detiene diciendo cuál correr antes en la misma corrida: `materias` necesita organigrama y carreras; `seriacion`, carreras y materias; `docentes`, organigrama; `plazas`, docentes; `historial_docente`, docentes y materias; `alumnos`, carreras; `kardex`, materias (y los alumnos ya migrados, de esta corrida o de una anterior). `cuentas_alumnos` no se detiene: usa los alumnos ya migrados, de esta corrida o de una anterior, y cuenta en la bitácora los que no están. `verificacion` no se detiene: si en la corrida no corrieron `archivo` y `kardex`, avisa que no verificó (ver «Verificación contra el SII original») |
| `--user=<username>` | Quién aplica los lotes; debe tener permiso de aplicar importaciones |
| `--force` | Obligatorio en producción |

La consola imprime solo cuentas: una fila por lote (filas, válidas, advertencias, errores, altas y cambios), el resumen de la verificación, cuántos renglones del SII no se pudieron leer y el total de incidencias. Nunca imprime valores. La última línea dice cómo terminó: «Migración terminada», «Migración terminada con diferencias» si la verificación encontró algo que no cuadra (la salida sigue siendo 0 y nada se revierte) o «Ensayo terminado, sin cambios».

Si la exportación ya no cumple el contrato de encabezados (falta un archivo, cambian sus columnas o un archivo viene vacío o ilegible), se detiene antes de escribir nada. Si un paso falla a la mitad de una corrida real, los lotes anteriores ya quedaron aplicados; repetir la migración no los cambia. Al log va solo la clase de la excepción, dónde se lanzó y el SQLSTATE, nunca el mensaje ni los valores.

## Qué deja

- `<carpeta>/_incidencias.csv` (`paso,archivo,fila,campo,nivel,mensaje`): lo que validó cada plantilla, lo que descartó o corrigió cada paso y, una vez por archivo del SII, los renglones que no se pudieron leer (`malformados`, error: no se migran), los reparados por coma decimal y la anotación al final. Una fila se señala por su número en el lote, por una clave de catálogo (materia, aula, carrera, retícula) o por `<archivo>:<línea>` del SII, nunca por el nombre de una persona o su RFC; solo los errores de la verificación señalan al alumno por su número de control. Los mensajes de algunas plantillas sí pueden traer un valor del renglón. El nivel es `error`, `warning` o `info` (el resumen de la verificación, que no cuenta en el total de incidencias de la consola). Se reescribe en cada corrida.
- El esquema `sii_verificacion` con el resultado de la verificación (ver abajo).
- `<carpeta>/_nexus/`: un CSV intermedio por lote, con los encabezados de su plantilla. Trae datos personales: vive junto a la exportación y nunca sale de `storage/`.
- En una corrida real, cada lote queda en el disco `imports` (`storage/app/imports/`) como evidencia, con su bitácora y sus `import_rows`, igual que uno del asistente.

## Archivo del SII original (`sii_archivo`)

El usuario decidió el 10 de noviembre de 2026 (confirmado) guardar dentro de Nexus la exportación original, renglón por renglón, para rastrear cualquier dato hasta su origen sin abrir los CSV. Lo hace el paso `archivo` (`ArchiveStep`, que escribe con la Action `ArchiveSiiExport`), el primero de la corrida, para que el original quede guardado aunque un paso posterior se detenga. No pasa por el motor de importación porque no transforma nada.

- **Qué guarda.** En el esquema de PostgreSQL `sii_archivo`, una tabla por archivo de la exportación, con el nombre normalizado del archivo (`alumnos_todos`, `historia_alumno`, …). Cada tabla tiene `_linea` (el número de línea en el CSV, donde el encabezado es la 1; es la llave primaria) y las columnas del contrato como `text`, en su orden. Los valores son los que entrega el lector del SII: en UTF-8, recortados y con la coma decimal reparada, sin otra conversión. Un campo vacío es `''`, nunca null.
- **Archivos opcionales de cuentas.** `acceso` (`usuario`, `nombre_usuario`, `contrasena`, `tipo_usuario`, `status`: las cuentas del SII) y `permisos` (`usuario`, `clave_area`, `carrera`, `aplicacion`: qué áreas y carreras ve cada cuenta) son opcionales, como los del kárdex. Por ahora solo se archivan en `sii_archivo`; ningún paso de la migración los lee.
- **Qué no guarda, y por qué.** El `nip` de `alumnos_todos`, porque es la contraseña del alumno en el SII, y la `contrasena` de `acceso` (el hash MD5, sin sal, de la contraseña de cada cuenta del SII): ninguna contraseña del SII pasa a Nexus, ni siquiera en hash (decisión del usuario del 11 de noviembre de 2026, confirmada). La excepción es el NIP, que solo llega, cifrado con bcrypt, a la contraseña del usuario del alumno (paso `cuentas_alumnos`, AC-10); el archivo nunca lo guarda. Tampoco la `foto` y la `firma` de `alumnos_todos` y `docentes`, porque son la imagen de la persona y no sirven para rastrear un dato. Ni de `docentes` los datos personales que Nexus no usa, por minimización de datos personales (decisión del usuario, confirmada el 11 de noviembre de 2026): la descripción física (`pigmentacion`, `pelo`, `frente`, `cejas`, `ojos`, `nariz`, `boca`, `estaturamts`, `pesokg`, `senas_visibles`), la familia (`padre`, `madre`, `conyuge`, `hijos`), la cartilla militar (`num_cartilla_smn`, `ano_clase`) y el domicilio (`domicilio_empleado`, `colonia_empleado`, `codigo_postal_empleado`). El lector las descarta en cuanto lee el renglón; la lista está en un solo lugar, `ArchiveSiiExport::EXCLUDED`. Tampoco están los renglones que el lector no pudo leer: su cuenta queda en `_cargas.ilegibles`, sus líneas en `_incidencias.csv` («malformados») y el hueco en `_linea` dice dónde estaban.
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

## Cuentas de los alumnos (`cuentas_alumnos`)

El penúltimo paso le da a cada alumno su usuario con su NIP del SII (AC-10 a AC-14, decisión del usuario del 11 de noviembre de 2026, confirmada; el detalle de las reglas está en reglas-de-negocio.md, «Acceso a Nexus»).

- **Qué lee.** De `alumnos_todos`, solo `no_de_control` y `nip`. No pasa por el motor de importación, para que el NIP no quede en `_nexus/` ni en `import_rows`: arma bloques de 250 alumnos (`SyncStudentAccountsJob::CHUNK`) y los encola en un lote, «Cuentas de alumnos del SII» (`job_batches`), que sigue aunque un bloque falle, en la cola `bulk`: el worker la atiende después de `default`, así que los correos de invitación o de restablecimiento no esperan detrás de los bloques. Un número de control repetido vale por su primer renglón, como en `alumnos`, y se avisa. Cada bloque viaja **cifrado** con la llave de la aplicación (`ShouldBeEncrypted`): ni el NIP ni el número de control quedan en claro en Valkey ni en `failed_jobs`.
- **Qué hace cada bloque.** `SyncStudentAccounts`, alumno por alumno: crea o pone al día su usuario (username = número de control, activo solo si el alumno es vigente), su contraseña con el NIP en bcrypt (con ceros a 4 dígitos si tiene de 1 a 3, AC-11) mientras no la haya cambiado, su correo institucional y su puesto `alumno`, y lo liga (`students.user_id`). Un alumno de la exportación que no está en Nexus (su retícula no se migra, por ejemplo) se salta. Un bloque es idempotente: si falla, reintentarlo es seguro.
- **Qué reporta.** En la consola, una fila `student_accounts` (filas = alumnos con número de control; válidas = los que se encolan; advertencias = los que no traen NIP, que quedan sin acceso) y la nota «Encoló las cuentas de N alumno(s) en M bloque(s)…»; en `_incidencias.csv`, un aviso con cuántos no traen NIP y otro (`info`) con cuántos lo traen de 1 a 3 dígitos. Lo que hizo cada bloque (creadas, puestas al día, sin cambios, sin alumno en Nexus, en conflicto y sin correo porque otro usuario lo tiene) queda en la bitácora, a nombre de quien corrió la migración (evento `student-accounts-synced`), con los números de control en conflicto. Nunca un NIP ni un hash.
- **Si la exportación perdió los NIP.** Si más del 5 % de los alumnos vienen sin NIP (`StudentAccountsStep::MAX_EMPTY_NIP_SHARE`, supuesto por confirmar), el paso no encola nada: la fila `student_accounts` cuenta a todos como errores y `_incidencias.csv` lo dice. Aun por debajo de ese tope, un NIP vacío nunca le quita a un alumno el NIP del SII que ya tenía (se reporta en la bitácora del bloque); solo deja sin acceso a quien todavía no tiene contraseña. La exportación del 11 de noviembre de 2026 no trae ningún NIP vacío.
- **En seco** no encola nada: la nota dice «Encolaría…».
- **Cuánto tarda.** Medido en el contenedor de desarrollo, bcrypt cuesta 36.9 ms a costo 10 (el del NIP, AC-14) y 144.9 ms a 12, y un bloque de 250 alumnos nuevos tarda 11.4 s de punta a punta (45.6 ms por alumno: bcrypt más unos 9 ms de escritura y bitácora). Con el único worker de `compose.yaml`, ~68,000 alumnos tardan **unos 52 minutos**; con `docker compose up -d --scale queue=4`, unos 13. Una segunda corrida cuesta casi lo mismo (41 ms por alumno), porque compara cada NIP con el guardado antes de decidir si lo escribe; el de un alumno que ya entró está a costo 12 (AC-14) y compararlo cuesta unos 150 ms, así que, si casi todos hubieran entrado, una segunda corrida tardaría unas 3 horas con un worker (unos 45 minutos con cuatro). Los bloques van en la cola `bulk`, así que no retrasan los demás jobs. El avance se ve con `php artisan queue:monitor redis:default` o en `job_batches`.

## Verificación contra el SII original (`sii_verificacion`)

El usuario decidió el 10 de noviembre de 2026 (confirmado) que **cada corrida compara el original con Nexus y avisa si algo no cuadra**, por el temor a perder datos al migrar. Lo hace el paso `verificacion` (`VerificationStep`, que escribe con la Action `VerifySiiMigration`), el último de la corrida, cuando ya se aplicó todo. Como el paso `archivo`, no pasa por el motor de importación. Nunca revierte nada de lo aplicado.

- **Qué necesita.** El archivo de esta misma corrida (que haya corrido `archivo`), la historia del SII en la exportación y el kárdex aplicado en esta corrida (que haya corrido `kardex`, que además deja los mapas de claves con que se explica lo que no pasó; que sea de esta corrida y no de una anterior es supuesto por confirmar). Si falta algo, no se detiene: deja en `_incidencias.csv` y en la consola «No se verificó la migración contra el SII» con la razón, y en la tabla de la consola una fila `sii_verificacion | sin verificar`. En una corrida real además vacía `materias` y `alumnos` y anota la razón en `_corrida` (estado `no verificada`), para que los resultados de una corrida anterior no se lean como de esta exportación. **En seco no verifica ni toca `sii_verificacion`**: nada se escribió, y `_corrida` sigue describiendo la última corrida real.
- **El avance al día.** Antes de comparar recalcula el avance de todos los alumnos ahí mismo, sin esperar al job `RecomputeStudentProgress` que encolaron las plantillas (la cola puede no haberlo corrido aún). Llama directo a `RefreshStudentProgress`, lo mismo que hace el job; no usa `dispatchSync()`, porque con el candado del job el recálculo se saltaría si la cola ya está recalculando. Cuánto tardó queda en `_incidencias.csv` (`info`, fila `avance`) y en la consola.
- **Qué compara, renglón por renglón (`sii_verificacion.materias`).** Un renglón por renglón de `sii_archivo.historia_alumno`, con la misma `_linea`. Se une con el kárdex de Nexus por alumno, periodo y clave del SII (`upper(kardex_records.sii_subject_code) = upper(historia.materia)`); el periodo es el que guarda Nexus: el del SII si es un periodo (año de 1964 al próximo y 1, 2 o 3), si no, ninguno, así que un renglón con un periodo que no existe se une con la fila sin periodo y no sale como «no pasó». Varios renglones de un alumno que caen en la misma fila del kárdex (misma materia de Nexus y mismo periodo: la misma clave repetida en un periodo, la misma clave con dos periodos que no existen o dos claves que son la misma materia) se ordenan como en el paso `kardex`: la aprobada antes que la reprobada, luego la de mayor calificación y luego la última línea; los demás son «se queda uno». Columnas: `no_de_control`, `periodo` (el del SII), `periodo_nexus`, `clave_sii`, `estatus_sii`, `calificacion_sii`, `reticula_sii`, `en_reticula_sii` y `creditos_sii` (la retícula del alumno en el SII, si la clave está en ella y sus créditos ahí), `plan_nexus`, `clave_nexus`, `creditos_nexus` y `resultado_nexus` (a qué llegó en Nexus), `clave_nexus_esperada` (la materia de Nexus que le toca a la clave del SII con el plan del alumno, como la traduce el kárdex) y `diagnostico`.
- **Qué compara, alumno por alumno (`sii_verificacion.alumnos`).** Un renglón por alumno de `sii_archivo.alumnos_todos`. Según el SII (regla confirmada), un alumno está completo cuando los créditos de sus materias aprobadas (`estatus_materia` A) que están en su propia retícula (`materias_carreras` de su carrera y retícula) llegan a los `creditos_totales` de esa retícula; una materia aprobada dos veces cuenta una vez. Los créditos totales se leen con la regla del paso `carreras` (`GridTotalCredits`, la misma clase): solo cuenta un entero mayor que cero y, si `carreras` trae la retícula en varios renglones con créditos distintos, quedan los mayores; una retícula sin un total mayor que cero no deja a nadie completo. Según Nexus, cuando `student_progress.credits_approved` llega a `study_plans.total_credits` (el avance ya cuenta lo aprobado en materias pendientes del plan, «Por validar»). Columnas: `reticula_sii`, `estatus_sii`, `creditos_requeridos_sii`, `creditos_campo_sii` (el campo `creditos_aprobados` del SII, para comparar), `creditos_sii`, `materias_sii`, `completo_sii`, `reticula_migrada` (si su retícula está en `planes.csv` para migrarse), `en_nexus`, `plan_nexus`, `estatus_nexus`, `creditos_requeridos_nexus`, `creditos_nexus`, `materias_nexus`, `completo_nexus` (vacío si no está en Nexus) y `creditos_perdidos` (los del SII menos los de Nexus). Un plan sin créditos totales, o un alumno sin avance, no está completo.
- **Repetidos en el archivo.** Un alumno o una retícula que el SII trae dos veces cuentan una vez, el primero por línea; una clave repetida en una retícula, con sus créditos mayores.

Cada renglón de la historia lleva uno de estos diagnósticos; se evalúan en este orden y gana el primero que aplica:

| Diagnóstico | Qué significa | Aviso |
|---|---|---|
| `alumno no está en Nexus` | El número de control no está en Nexus (su retícula no se migra, por ejemplo) | No |
| `no pasó: sin explicar` (estatus) | Un `estatus_materia` distinto de A, R y P, que el kárdex no migra, va aquí antes que cualquier otro diagnóstico: la exportación real no trae otros, y si aparece uno debe saltar (confirmado el 10 de noviembre de 2026) | **Error**, como abajo |
| `no pasó: el mismo alumno tiene la misma materia de Nexus en el mismo periodo (se queda uno)` | Otro renglón del alumno cae en la misma fila del kárdex y gana con el orden del paso `kardex`; o no llegó al kárdex y el alumno ya tiene en ese periodo la materia de Nexus que le tocaba | No |
| `no pasó: la clave del SII no está en ninguna retícula` | No llegó y su clave no está en `materias_carreras`, así que no tiene materia de Nexus | No |
| `no pasó: sin explicar` | No llegó y nada de lo anterior lo explica | **Error**, uno por clave del SII con cuántos renglones y sus primeras líneas |
| `pendiente del plan: cuenta` | Llegó a una materia pendiente del plan del alumno («Por validar»): cuenta en el avance | No |
| `fuera de la retícula del plan: no cuenta` | Llegó a una materia que no está en la retícula ni en los pendientes del plan del alumno: no cuenta en el avance | Advertencia, una por clave del SII, si la clave está en la retícula del alumno en el SII y está aprobada: cuenta en el SII y no en Nexus |
| `otra clave del SII es la misma materia: cuenta una vez` | El alumno aprobó esa materia de Nexus con dos claves del SII: en Nexus cuenta una vez | No |
| `fuera de la retícula del SII, en el plan de Nexus: cuenta en Nexus` | La clave no está en la retícula del alumno en el SII, pero llegó a una materia de la retícula de su plan en Nexus: cuenta en Nexus y no en el SII, así que no le quita créditos a nadie (decidido el 10 de noviembre de 2026, confirmado; la comparación a mano lo contaba como créditos distintos) | Advertencia, una por clave del SII, con las materias de Nexus a las que llegó |
| `créditos distintos` | La materia está en las dos retículas, pero los créditos de la clave en la del alumno en el SII no son los de la materia de Nexus | Advertencia, una por clave del SII, materia de Nexus y créditos |
| `igual` | Llegó a una materia de la retícula del plan del alumno, con los mismos créditos | No |

Además, un **error por cada alumno completo en el SII e incompleto en Nexus**, y por cada alumno completo en el SII que no está en Nexus aunque su retícula se migra, con su número de control como fila (`_incidencias.csv` vive en `storage/`, fuera de git) y sus créditos. Los de retículas que no se migran a propósito (las que `planes.csv` excluye) solo se cuentan en el resumen; el paso `alumnos` ya avisa por retícula. Si hay algún error, la corrida termina «con diferencias», con salida 0 (supuesto por confirmar: todo se aplicó).

**El resumen** va a `_incidencias.csv` (nivel `info`, fila `resumen`) y a la consola, debajo de la tabla: cuántos renglones y alumnos se compararon, cuántos renglones hay de cada diagnóstico; por estatus del SII, cuántos alumnos hay, cuántos están completos en el SII, cuántos en Nexus y cuántos no están en Nexus (y de esos, cuántos son de retículas que no se migran), y cuántos alumnos incompletos en Nexus cuentan ahí menos créditos que en el SII (`creditos_perdidos` mayor que cero). El total de «Incidencias» de la consola no cuenta el resumen. La tabla de la consola trae dos filas con la plantilla `sii_verificacion`: `materias` (filas = renglones comparados, advertencias = los renglones de las tres advertencias, errores = sin explicar) y `alumnos` (filas = alumnos, errores = completos en el SII que en Nexus están incompletos o no están); las altas son los renglones escritos.

**Dónde queda.** En el esquema de PostgreSQL `sii_verificacion` (ADR-0022), con las reglas de `sii_archivo`: cada corrida real lo reemplaza completo en una sola transacción, también cuando no verifica (si algo falla, queda la verificación anterior), borra sus tablas sin `CASCADE` (una vista sobre ellas hace fallar el paso en lugar de borrarse), vive fuera de las migraciones, no se edita y no lo lee ninguna pantalla ni Query. `materias` tiene llave primaria `_linea` e índices por número de control y por diagnóstico y clave; `alumnos`, llave primaria `no_de_control`. **`_corrida`** tiene un renglón: la `carpeta` de la exportación, `verificado_en`, el `estado` (`verificada`, `con diferencias` o `no verificada`) y la `razon` (cuántos renglones o alumnos no cuadran, o por qué no se verificó). Antes de leer `materias` o `alumnos`, mira `_corrida`: si dice `no verificada`, están vacías. Antes de comparar, el paso actualiza las estadísticas (`analyze`) de las tablas que lee, recién cargadas, para que PostgreSQL las una por hash; no hacen falta índices sobre `sii_archivo`. Con datos sintéticos del tamaño de la exportación real (68 000 alumnos, 2 040 000 renglones de historia y de kárdex), la verificación tardó 74 segundos: 49 de recálculo del avance y 25 de comparación. Sin estadísticas al día de alumnos, materias y planes, la misma comparación pasaba de 7 minutos.

Para consultarla:

```sql
-- De qué corrida es y cómo quedó.
select * from sii_verificacion._corrida;

-- Cuántos renglones de la historia hay de cada diagnóstico.
select diagnostico, count(*) from sii_verificacion.materias group by diagnostico order by count(*) desc;

-- Los que no pasaron sin explicación, con su línea en el CSV para buscarla en sii_archivo.historia_alumno.
select _linea, no_de_control, periodo, clave_sii, estatus_sii, clave_nexus_esperada
from sii_verificacion.materias
where diagnostico = 'no pasó: sin explicar'
order by clave_sii, _linea;

-- Los alumnos completos en el SII que en Nexus están incompletos o no están, y lo que pasó con su historia.
select * from sii_verificacion.alumnos
where completo_sii and ((en_nexus and not completo_nexus) or (not en_nexus and reticula_migrada))
order by creditos_perdidos desc;
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
| `seriacion` | `prerequisites` | El tipo (seriación o correquisito) de un requisito que ya está en la seriación del plan, también el de uno que se capturó en el editor o que entró al validar una materia | Nunca borra una seriación que el SII ya no trae ni la capturada en Nexus; una quitada en Nexus no se restaura (es error) |
| | `pending_prerequisites` | De un requisito que espera y no ha entrado, el tipo y la retícula del SII | Los que ya entraron a la seriación (avisa); su nota |
| `aulas` | `rooms` | Edificio (lo crea si el campus no lo tiene), nombre, tipo, capacidad y departamento dueño, del curado `aulas.csv` | Estado, características y los edificios que ya existen |
| `docentes` | `teachers` | Nombre, correo (si es válido) y fecha de ingreso; agrega las adscripciones nuevas | Estado, grado (el SII no lo trae), adscripción principal; nunca quita adscripciones |
| `plazas` | `positions` | Horas base de la plaza de cada tipo (la suma de las claves de ese tipo) | Tipo y vigencia; nunca quita una plaza que ya no venga |
| `historial_docente` | `teacher_history` | Nada: solo agrega los grupos que faltan | Lo que ya existe |
| `alumnos` | `students` | Nombre, carrera, plan, campus, semestre (si es válido), estatus (si el código del SII es uno de los conocidos), modalidad y motivo del SII | Estado activo y número de control |
| `kardex` | `kardex` | Calificación, resultado y oportunidad de cada alumno, materia y periodo (o sin periodo, si el SII trae uno que no existe); una materia «cursando» pasa a su resultado cuando llega a la historia | Nunca borra una fila que ya no venga, ni la sin periodo cuando el SII ya trae el bueno: esa se borra a mano |
| `cuentas_alumnos` | — (la cola, `SyncStudentAccounts`) | Del usuario del alumno: nombre, activo (si el alumno es vigente y no está borrado), correo institucional (solo si no tiene) y el NIP del SII como contraseña, si no tiene contraseña o sigue con la del SII; el puesto `alumno` si no lo tiene. Un NIP igual al guardado no se reescribe, así que no cierra sesiones | La contraseña que el alumno cambió o la por defecto que le dio el admin (`password_origin`), su correo si ya tiene uno y un usuario con su número de control que no es suyo |
| `verificacion` | — | Reemplaza completo el esquema `sii_verificacion` | Nada de lo aplicado |

Lo más delicado: una segunda corrida devuelve al semestre y al orden del SII las materias que alguien acomodó en el editor de la retícula (D21). También devuelve los nombres de materias y aulas al del SII o del curado. Los pendientes resueltos en «Por validar» sobreviven.

**Una base migrada antes del 10 de noviembre de 2026 se migra de nuevo desde cero** (`php artisan migrate:fresh` y una corrida completa, no una segunda corrida encima). Hasta esa fecha la migración le ponía a una materia sin semestre el semestre de otra con el mismo nombre, aun de otra carrera, y la dejaba en `study_plan_subjects`. Una corrida nueva ya no lo hace (ver «Retículas históricas y vivas»), pero tampoco quita esos lugares: la plantilla de pendientes, al ver que la materia ya está en la retícula del plan, solo avisa «ya está en la retícula» y no crea el pendiente, así que esas materias se quedarían con el semestre prestado en lugar de pasar a «Por validar».

## Retículas históricas y vivas (D18)

La migración nunca decide un semestre (confirmado, 10 de noviembre de 2026): lo que el SII trae con semestre 0 no entra a la retícula y va a «Por validar». Qué más pasa depende de la retícula, y el paso `carreras` lo decide en cada corrida:

- **Inscritos en el periodo actual.** El paso lee `seleccion_materias` una sola vez (solo `periodo` y `no_de_control`), sin los veranos (periodos que terminan en 2), y se queda con los números de control de los dos periodos regulares más recientes (terminan en 1 o 3). El periodo actual es el regular más reciente; si tiene menos de la mitad de alumnos distintos que el regular anterior, todavía se está abriendo y cuentan los inscritos en los dos (el umbral de la mitad es supuesto por confirmar). Con la exportación del 10 de noviembre de 2026 queda solo el 20263 (7 206 alumnos contra 6 135 del 20261); al corte, con el 20271 recién abierto, contarían el 20271 y el 20263. Los cruza con `alumnos_todos` para saber qué retículas del SII tienen inscritos; un plan de Nexus que junta varias retículas del SII es vivo si cualquiera tiene uno. El aviso «El periodo actual es el …» (o «El periodo … todavía se está abriendo») de `_incidencias.csv` dice qué periodo o periodos tomó, con cuántos alumnos, y cuántos planes quedaron históricos. Como este paso lee la selección antes que `kardex`, sus renglones ilegibles salen en `_incidencias.csv` bajo `carreras`.
- **Retícula histórica** (ningún inscrito y no es el de nuevo ingreso; `study_plans.is_historical`): se migra tal cual viene del SII. El plan de nuevo ingreso nunca es histórico, aunque nadie esté inscrito todavía; tener alumnos activos (ACT) que ya no se inscriben no hace viva una retícula, porque el usuario eligió «sin inscritos hoy» y no «sin alumnos vigentes». Sus materias con semestre lo conservan; las de semestre 0 y las de estatus `N` quedan en «Por validar» sin semestre sugerido, con un solo aviso por plan. No cuentan en el aviso de la lista de planes, porque no son trabajo para Servicios Escolares mientras no vuelva un alumno; si vuelve y aparece inscrito, la siguiente corrida la marca viva.
- **Retícula viva** (con un inscrito, o el plan de nuevo ingreso aunque nadie esté inscrito todavía): cada materia de semestre 0 va a «Por validar» con el semestre sugerido (el más frecuente, en empate el menor, entre las materias con el mismo nombre y semestre de la misma carrera; nunca de otra) o sin sugerencia, con un aviso por materia. Una de estatus `N` sugiere su semestre del SII, si lo trae, y si no, el de su misma carrera. Servicios Escolares lo confirma o lo cambia. La sugerencia se recalcula en cada corrida: si el plan pasa a histórico, se borra.
- **Sin `seleccion_materias`** (o sin un periodo válido) ningún plan es histórico, y la corrida lo avisa una vez.

En todos los casos lo aprobado en una materia de «Por validar» cuenta en el avance del alumno y el kárdex la encuentra por su clave del SII.

## Seriación del SII (`seriacion`)

El SII exportó la seriación en `requisitos_materia` (`carrera`, `reticula`, `materia`, `materia_relacion`, `tipo_requisito`): `P` es pre-requisito (la materia pide haber aprobado `materia_relacion`; en Nexus, seriación) y `C`, co-requisito (se cursa a la par; correquisito). La exportación del 10 de noviembre de 2026 trae 1 934 renglones (1 770 `P` y 164 `C`) de 50 retículas, con un renglón donde la materia se pide a sí misma, un ciclo de `P` y algunas claves que no están en la retícula. El archivo es **opcional**, como los del kárdex: sin él, el paso avisa una vez («La exportación no trae requisitos_materia») y no migra seriación. Lo hace `SeriationStep`, justo después de `materias`, porque necesita `plan_for` y `subject_for_plan` y lee de la base qué materias quedaron en la retícula y cuáles en «Por validar» (en seco también: todo va en la misma transacción). Las reglas están en [reglas-de-negocio.md](../dominio/reglas-de-negocio.md), «Migración de la seriación del SII».

- **Cada renglón** va al plan de Nexus de su retícula, y sus dos claves a las materias de ese plan (`subject_for_plan`, como el kárdex). Lo que no pasa queda en `_incidencias.csv`: una advertencia por retícula excluida del curado, con cuántos renglones; una por plan y clave del SII sin materia de Nexus en ese plan; una por renglón cuyas dos claves son la misma materia de Nexus (la que se pide a sí misma, o dos claves fundidas); un **error** por línea con un `tipo_requisito` que no es `P` ni `C`, y uno por requisito con una materia que no está en la retícula ni en «Por validar» del plan.
- **Un requisito por plan, materia y requisito.** Si varias retículas del SII de un plan lo traen, es uno; si unas dicen `P` y otras `C`, queda seriación, lo más estricto, con aviso (supuesto por confirmar).
- **Lote `prerequisites`** (las dos materias en la retícula): la plantilla de la seriación (tarea 1.9) revisa ciclos con la del plan y con los renglones anteriores; el que cierra uno es error del lote, como el ciclo de `P` de la exportación real. Van primero los que piden una materia de un semestre anterior, así en un ciclo entra ese y el otro es el error (supuesto por confirmar). Un `P` cuyo requisito no va en un semestre anterior y un `C` entre semestres distintos avisan y se migran igual: es dato del SII.
- **Lote `pending_prerequisites`** (alguna materia en «Por validar», la otra en la retícula o también en «Por validar»): los **requisitos que esperan** (`study_plan_prerequisite_pendings`), con la retícula del SII de la que salieron y sin revisar ciclos. Decisión del usuario del 10 de noviembre de 2026 (confirmada): se guardan aparte, ligados al plan, y **entran solos a la seriación cuando Servicios Escolares le asigna semestre a la materia** (y a la otra, si también esperaba). Con la exportación real son unos 730.
- **Cuando entran.** `ResolvePendingPlacement` bloquea primero el plan para escribir (como `AddPrerequisite`, para que dos validaciones o una validación y una seriación del editor se esperen) y, al final y en la misma transacción, llama a `ApplyWaitingPrerequisites`: los requisitos que esperaban en el plan con sus dos materias ya en la retícula se recorren primero los que respetan el orden de los semestres que ya tienen sus materias y luego por orden de alta (la misma regla que el lote `prerequisites`). Cada uno entra a `subject_prerequisites` con su tipo, queda con `applied_at` y deja bitácora con quién validó la materia. Uno que cerraría un ciclo de seriación no entra: sigue esperando con una nota que lo dice. Si la seriación ya tiene ese requisito (alguien lo capturó en el editor), se respeta con su tipo hasta la siguiente corrida y el que esperaba queda aplicado, sin nota. Si se quitó de la seriación en Nexus, no se restaura: el que esperaba queda aplicado con la nota «No entró: se quitó de la seriación en Nexus.». La Action devuelve qué pasó con cada uno y «Por validar» lo dice al agregar la materia (advertencia si alguno no entró). Antes, el panel de la materia dice qué requisitos entran con ella a la seriación, marca los que no entrarían por un ciclo con el semestre elegido, dice cuáles esperan a la otra materia y pinta la nota de los que la tienen.
- **Segunda corrida.** Una sola política, la del SII, mientras no se decida qué gana en cada paso (D23): un requisito que ya está en la seriación toma el tipo del SII, también el que alguien capturó en el editor o el que entró al validar una materia; uno que espera y no ha entrado, el tipo y la retícula del SII. Nunca borra una seriación que el SII ya no trae ni la que se capturó en Nexus, y no restaura la que se quitó en Nexus (la plantilla lo reporta como error). Si una materia que esperaba ya se validó, el requisito va directo a la seriación.

Para consultar los que esperan:

```sql
-- Cuántos requisitos esperan por plan y cuántos ya entraron.
select p.code, count(*) filter (where w.applied_at is null) as esperan, count(*) filter (where w.applied_at is not null) as entraron
from study_plan_prerequisite_pendings w join study_plans p on p.id = w.study_plan_id
group by p.code order by p.code;

-- Los que siguen esperando aunque sus dos materias ya estén en la retícula (cerrarían un ciclo).
select p.code, s.code as materia, r.code as requisito, w.kind, w.note
from study_plan_prerequisite_pendings w
join study_plans p on p.id = w.study_plan_id join subjects s on s.id = w.subject_id join subjects r on r.id = w.prerequisite_subject_id
where w.applied_at is null and w.note is not null;
```

## Decisión pendiente: qué gana en cada paso (D23)

El usuario decidió el 5 de noviembre de 2026 que si una segunda corrida pisa lo que se editó en Nexus se decide **paso por paso, más adelante** (en el corte). Lo que espera: los catálogos que se limpian en Nexus (aulas, edificios, retículas) se quedan con lo de Nexus, y lo que cambia en el SII (alumnos, docentes, horarios) probablemente toma lo del SII. Hoy el código no tiene esa opción: en cada paso gana el SII o el curado en los campos de la tabla.

| Paso | Lo que espera el usuario | Hoy |
|---|---|---|
| `organigrama` | Por decidir (no lo mencionó; es un catálogo curado) | Gana el curado |
| `carreras` | Por decidir (no lo mencionó; es un catálogo curado) | Gana el curado; el plan actual, la exportación |
| `materias` | Se queda Nexus (retículas) | Gana el SII |
| `seriacion` | Por decidir (no lo mencionó; es parte de la retícula) | Gana el SII en el tipo, también sobre lo capturado en Nexus; no borra ni restaura |
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
- **Plazas** (paso del 6 de noviembre de 2026): confirmar con Recursos Humanos que E35 es asignatura con sus horas en la clave y que la jornada de apoyo es de 36 horas (E36, E37 y E38, medio tiempo, tres cuartos y tiempo completo con 20, 30 y 40 horas, quedaron confirmadas el 6 de noviembre de 2026) y qué significan los códigos 10, 20 y 95 de `tipo_nombramiento`; averiguar por qué 117 docentes con grupos en 20263 no están en `docentes2` (se quedan sin plaza y sin horas base).
- **Kárdex** (paso del 9 de noviembre de 2026): confirmar con Servicios Escolares qué significa cada código de `tipo_evaluacion` (sobre todo E1 con estatus A y calificación 0, RC, RU, AC, EA y CG) y el `status_seleccion` S; preguntar por qué hay renglones de `seleccion_materias` de periodos cerrados, con calificación, que no están en la historia (no se migran, K3). El paso guarda una llave por renglón de la historia: el comando sube su límite de memoria a 1 GB. Con la exportación del 9 de noviembre de 2026 (2 042 299 filas), la corrida real del kárdex tardó 2 horas 8 minutos (unos 4 de lectura, 44 de validación y 80 de aplicación); en seco no es práctica, porque todo va en una sola transacción y a los 50 minutos seguía validando: ensaya el kárdex con una corrida real sobre una base de prueba.
- **Doctorado DCII** (10 de noviembre de 2026): sus dos retículas del SII traen la misma clave oficial y el mismo nombre, pero DII/46 pide 164 créditos (sus 9 materias suman 164) y DCI/41 pide 280 (sus 8 materias suman 234). `planes.csv` las separa: DII/46 se queda con DCII-2009-02 y DCI/41 va a DCII-2009-02-41. Confirmar con Servicios Escolares la clave del plan de DCI/41 y sus 280 créditos, que sus materias no alcanzan.
- **Retículas** (9 de noviembre de 2026): al fundir variantes de una materia que reparten distinto las horas, queda la de la presencial con sus horas (supuesto por confirmar con Servicios Escolares). Cada fusión deja un aviso «son la misma materia con horas distintas» en `_incidencias.csv`; hay que revisarlas, junto con las materias que el SII trae varias veces en una misma retícula. Desde el 10 de noviembre de 2026 ningún lugar toma su semestre de otra materia: ver «Retículas históricas y vivas». Una clave con horas distintas según el plan (una clave del SII repetida en retículas con otras horas, o igual a una clave TecNM, como ACA-0909 en ICBS-2024-249) queda con las de más planes y deja el aviso «trae créditos/teoría/práctica distintos según el plan». La corrida del 9 de noviembre de 2026 deja la lista completa de lo que la migración juntó, corrigió o no pasó en una página de revisión, con estado y nota por caso.
- **Avance contra el SII** (10 de noviembre de 2026): al comparar el SII crudo con Nexus, 3 691 egresados que en el SII juntan los créditos de su retícula salían incompletos en Nexus. Las causas: lo aprobado en «Por validar» no contaba (3 280), dos materias distintas de una retícula con la misma clave TecNM se juntaban (IIE/41 y ISC/44) y los renglones con un periodo que no existe no pasaban. Ahora lo aprobado en «Por validar» cuenta, el plan lleva `creditos_totales` y esos renglones pasan sin periodo. Al repetir la comparación quedaban 99 egresados de ILO/36: los lugares con estatus `N` no se migraban y se perdían dos materias de su kárdex; ahora pasan a «Por validar». Falta que Servicios Escolares capture el periodo bueno de esos renglones (la lista está en `_incidencias.csv`, por línea). Si después corrige el periodo en el SII, la siguiente corrida agrega la fila con su periodo y deja la sin periodo, que se borra a mano.
- **Seriación** (10 de noviembre de 2026): confirmar con Servicios Escolares los supuestos del paso `seriacion` y de los requisitos que esperan: si el SII trae un requisito como `P` en una retícula y como `C` en otra del mismo plan, queda seriación, lo más estricto; en un ciclo entra el que pide una materia de un semestre anterior (en la migración y al validar) y el otro queda como error del lote o esperando; un requisito que ya estaba en la seriación se respeta con su tipo hasta la siguiente corrida, que lo pisa con el del SII (D23); uno que se quitó de la seriación en Nexus no se restaura. Antes del corte, revisar los requisitos que siguen esperando aunque sus dos materias ya estén en la retícula porque cerrarían un ciclo (la consulta está en «Seriación del SII»): solo los ve quien valida la materia, en el aviso y en la nota del panel, y no hay pantalla que los liste.
- **Lo demás** (seguimientos de la revisión): el lector abre cada archivo tres veces; `ext-intl` no está en `composer.json`; dos lotes con el mismo nombre en `_nexus/`; la fusión de materias que ignora el semestre (T7 M1) va al plan de la parte 2. El mapa de alias por plan para el kárdex ya está: el kárdex traduce cada clave del SII con el plan del alumno.

## Exporte al SII (tarea 4.6)

`App\Modules\AcademicLoad\Exports\Sii\SiiLoadExport::files(Term, Campus)` devuelve `grupos.csv` y `horarios.csv` con la forma exacta de las tablas del SII (los encabezados de `SiiFile::Grupos` y `SiiFile::Horarios`): Latin-1, fin de línea LF, texto entre comillas simples con relleno al ancho del CHAR, números y fecha-hora sin comillas, nulo como campo vacío. Lee solo los payloads de los snapshots y `subject_aliases`; no escribe. Los archivos se leen de vuelta con `SiiCsvReader`.

Qué se exporta: la última versión publicada de cada carga del periodo y el campus que tenga alguna, aunque después se haya reabierto, igual que la consulta pública (supuesto por confirmar). Sin versiones publicadas, solo los encabezados.

| Campo | Regla |
|---|---|
| `materia` (10) | Alias de la materia con el plan del lugar; si no, alias sin plan; si no, el `code` de Nexus |
| `grupos`, padre | Una fila por grupo: materia según el plan de la carrera dueña, `grupo` (3), `capacidad_grupo` = lugares de la dueña (o la capacidad del grupo si solo tiene la dueña), `rfc` (13) del docente o vacío, `alumnos_inscritos` 0 |
| `grupos`, paralelos | Una fila por lugar no dueño con plan cuya clave SII difiere de la del padre y de las ya emitidas; mismo grupo y docente, `capacidad_grupo` = sus lugares, `paralelo_de` = materia del padre + 4 espacios + grupo del padre, a 17. Un lugar sin plan no genera paralelo |
| `horarios` | Padre y cada paralelo, una fila por sesión: `tipo_horario` `D`, `dia_semana` = día + 1 (lunes 2 … sábado 7), horas `1900-01-01 HH:MM:00.0`, `aula` a 6 (`EAD` sin salón) |
| Vacíos | `estatus_grupo`, `folio_acta`, `seleccionado_en_bloque`, `tipo_personal`, `alumnos_preinscritos`, `actividad`, `consecutivo`, `vigencia_*`, `consecutivo_admvo` |

Orden: clave del departamento, materia, grupo; cada paralelo después de su padre.

Supuestos por confirmar: `exclusivo_carrera` y `exclusivo_reticula` van vacíos (necesitan la clave de carrera del SII, que Nexus no guarda); no se emiten los códigos de día 1 ni 8 a 14 del SII (su significado es inferido); `rfc` de `horarios` repite el del grupo; el padre es la carrera dueña; los alias por plan pueden repetirse y se toma el de menor `code`. El SII trae una sola fila por grupo y día con rango continuo; el exporte conserva las sesiones del payload (ya son bloques continuos).
