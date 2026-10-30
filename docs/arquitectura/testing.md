# Estrategia de pruebas

Cómo se prueba Nexus. Aplica a todo el código del repositorio, incluido el escrito con ayuda de IA. Las herramientas son Pest 5 (unit, feature, browser y arquitectura) y GitHub Actions como CI bloqueante.

## Principios

1. **TDD obligatorio.** Primero el test que falla, luego el código mínimo que lo pasa, luego se refactoriza. Sin excepciones por prisa: la fecha de R1 se cumple con menos alcance, no con menos pruebas.
2. **Evidencia antes de afirmar.** "Funciona" significa una salida de `php artisan test` verde que cualquiera puede repetir. Un PR no describe lo que se probó a mano; enlaza los tests que lo demuestran.
3. **Las pruebas son la red para código generado con IA.** La IA produce código plausible a gran velocidad; la única forma de distinguir plausible de correcto es un test escrito a partir de la regla de negocio, no del código.

## Pirámide

| Nivel | Qué prueba | Ejemplos en Nexus |
|---|---|---|
| **Unit** | Clases puras sin base de datos ni contenedor: reglas, cálculos, puntuaciones, plantillas | Cada regla dura HC-01 a HC-13 y cada blanda SC-01 a SC-08 como clase aislada; `RankingScorer` (orden determinista, empates, docentes sin horas); cálculo de `student_progress` con seriación y repetidores; validación por fila de cada plantilla de importación; Actions con reglas propias (por ejemplo, motivo obligatorio al asignar fuera del ranking, HC-16) |
| **Feature** | Comportamiento con base de datos y contenedor de Laravel | Componentes Livewire con el helper de pruebas de Livewire (renderizado, propiedades, acciones, mensajes de validación); jobs de cola (recálculo de progreso, rankings, choques, aplicación de lotes); policies y alcance (`role_scopes`) en cada pantalla; transiciones de la máquina de estados de `academic_loads` con sus guardas; seeders; restricciones de PostgreSQL (exclusión de empalmes de salón y docente, suma de cupos) |
| **Browser** (Pest 5) | Flujos completos en navegador real | Un flujo crítico por rol por fase: login y cambio de contraseña; asistente de importación; validar catálogos como jefe; enviar propuesta como coordinador; acomodar un semestre en el editor sin choques; asignar docente con motivo; pedir y aprobar un cambio; cerrar y publicar; consultar horario publicado y descargar PDF |
| **Arquitectura** (`arch()`) | Que el código respete la convención del monolito modular | Dependencias solo hacia la izquierda del mapa de `docs/dominio/modelo-de-datos.md`; Actions `final`; modelos sin facades de request, sesión o auth; todo módulo tiene su ServiceProvider; enums respaldados por string. Las consultas desde Blade no se pueden detectar con `arch()`: se revisan en code review |

La base es ancha en unit: las reglas de negocio son lo que más cambia y lo más barato de probar aislado. Feature cubre integración y permisos. Browser se reserva para los flujos que, si fallan, detienen el piloto.

## Convenciones

- Carpetas: `tests/Unit/<Módulo>/`, `tests/Feature/<Módulo>/`, `tests/Browser/<Módulo>/`, `tests/Arch/`. El módulo del test es el módulo de la clase o pantalla que prueba.
- Un archivo de test por clase o por pantalla. Si un archivo pasa de unas 300 líneas, la clase probada probablemente hace demasiado.
- Nombres descriptivos **en español**, escritos como la frase que afirma el comportamiento, e incluyendo el identificador de la regla cuando aplique: "HC-07: rechaza cupos cuya suma excede la capacidad del grupo", "ordena primero a quien tiene más horas base vigentes".
- Cada test prueba una cosa. Preparación, acción y verificación visibles en ese orden.
- Los tests de feature de pantallas prueban también lo que el usuario **no** puede hacer (ver pruebas negativas).

## Datos de prueba

- **Factories por módulo** en `app/Modules/<Módulo>/Database/Factories/`, con estados nombrados que expresen el dominio: docente de tiempo completo, docente de honorarios, materia con laboratorio, plan inactivo, grupo sin docente, carga cerrada.
- **`DemoSeeder` de Campus II** (`database/seeders/DemoSeeder.php`): lo base (roles, campus, admin), los periodos `20261`, `20263` y `20271` (el actual), el catálogo académico de las 6 carreras de Campus II los usuarios de prueba, sus edificios y salones y 120 docentes. Es el conjunto de datos de los browser tests, de las demostraciones del viernes y de las mediciones de rendimiento. Se siembra en una base de desarrollo, nunca en producción (los seeders demo se niegan):

  ```bash
  docker compose exec app php artisan migrate:fresh --seed --seeder='Database\Seeders\DemoSeeder'
  ```

  - **Catálogo** (`AcademicDemoSeeder`, con `campuses`; los datos en `app/Modules/Academic/Database/Seeders/data/`, aproximados a las retículas públicas del TecNM): departamentos del organigrama, las carreras del campus con su oferta, un plan vigente por carrera (9 semestres, 260 créditos, 2 o 3 especialidades), tronco común compartido (`ACF-0901` en 10 planes con los dos campus), cadenas de seriación largas y correquisitos, y los planes 2004 inactivos de Sistemas, Industrial y Administración. Casos extremos: un plan sin retícula, uno de 10 semestres, una materia inactiva en una retícula, departamento y carrera inactivos, registros borrados (departamento, materia, especialidad, fila de retícula y seriación), una optativa, una materia sin tipo y nombres muy largos. Con `['C1', 'C2']` siembra también las 12 carreras de Campus I (unas 600 materias).
  - **Usuarios de prueba** (`DemoStaffSeeder`, con `campuses`), con su rol y su alcance desde el día en que se siembra: `subdir` (Subdirección de todo el Tec); `jefe.<depto>` (de todo el Tec) y `jpd.<depto>.c2` (con su fila en `department_heads`) de cada departamento que coordina una carrera del campus o imparte una materia de sus planes (`cb`, `cea`, `ii`, `sc`); y `coord.<carrera>.c2` (`isc`, `ii`, `ige`, `il`, `la`, `cp`). Con los dos campus suma `jpd.<depto>.c1` y `coord.<carrera>.c1`, y un `jefe.<depto>` por cada departamento nuevo. Entran con la contraseña `password` sin tener que cambiarla (supuesto por confirmar).
  - **Alumnos y kárdex** (`StudentsDemoSeeder`, con `studentsPerOffering` y `seed`): 35 alumnos por cada carrera activa del campus (unos 200 en Campus II), con un kárdex simulado periodo por periodo hasta Ago-Dic 2026 (`20263`, el periodo en curso del kárdex): seriación, oportunidades 1 a 3, bajas, verano, especialidad, materias «cursando» en el periodo en curso, algunas «sin calificar» en uno anterior y alguna materia fuera del plan. Hay egresados, bajas temporales y definitivas, alumnos sin estatus, desactivados y en el plan 2004 inactivo, más tres alumnos desactivados por cada carrera inactiva (plan sin retícula, sin kárdex). La misma semilla da los mismos números de control y el mismo kárdex; al final recalcula `student_progress`.
  - **Instalaciones** (`FacilitiesDemoSeeder`, con `campuses` y `seed`; los edificios en `app/Modules/Facilities/Database/Seeders/data/buildings.php`, nombres inventados al estilo del ITCJ): Campus II da 7 edificios y 89 salones; Campus I, 12 y 182. Hay los cinco tipos de salón, con y sin departamento dueño, salones inactivos, un edificio inactivo por campus, un salón borrado, capacidades de 12 a 300, nombres largos y características; bloqueos de Ene-Jun 2027 y de todos los periodos; y 1 o 2 salones por defecto (prioridad 1 y 2, origen manual o historial) para las materias con laboratorio de las carreras del campus. Inserta en bloques con `DB::table()` y la misma semilla da los mismos salones.
  - **Docentes** (`FacultyDemoSeeder`, con `teachersPerCampus` y `seed`; la lista de plantillas la da `TeacherRosterGenerator`, una clase pura): 120 en Campus II (350 y 250 en el masivo), con número de empleado `D<campus><consecutivo>` y nombres inventados. Hay de departamentos de todas las materias de los planes del campus, de los cinco tipos de plaza (varias a la vez, con dos o tres de tiempo completo; vencidas y futuras) y sin plaza; con dos o tres departamentos (la adscripción no lleva campus) y una sola principal; inactivos, borrados, sin correo ni grado y con nombres de más de 80 caracteres. Traen disponibilidad del periodo `20271` (lunes a viernes, 7:00 a 21:00, sin traslapes), horas fuera del ITCJ, perfil de materias, historial de 8 periodos del SII (una fila por grupo) y evaluaciones de 1 a 5 de los dos tipos. Al final `ProvisionTeacherUsers` crea el usuario de cada docente activo (contraseña de `NEXUS_DEFAULT_PASSWORD`, cambio obligatorio; sin la variable el seeder falla) y dos docentes por departamento y campus se nombran jefe de proyecto docente con `AppointDepartmentHead`. Cada campus usa su propia semilla: los de Campus II son los mismos solos o con Campus I.
  - Volver a sembrar no duplica: el catálogo se escribe por clave natural y los usuarios que ya existen no se tocan.
- **`MassiveSeeder`** (`database/seeders/MassiveSeeder.php`): lo mismo con Campus I y Campus II (también sus 271 salones), unos 600 docentes y unos 12 mil alumnos (670 por carrera en Campus I y 660 en Campus II) con unos 360 mil renglones de kárdex, para ver las pantallas con volumen y medir rendimiento. En una base aparte corre en unos 45 s con el límite de memoria por omisión (la simulación 1.5 s, insertar alumnos 1.5 s y kárdex 35 s, recalcular `student_progress` 5 s) e imprime esos tiempos (con unos 600 docentes y sus usuarios, la parte de Faculty tarda unos 10 s, casi todo creando usuarios):

  ```bash
  docker compose exec app php artisan migrate:fresh --seed --seeder='Database\Seeders\MassiveSeeder'
  ```
- **Archivos de importación del demo** (`php artisan imports:export-demo --dir=demo`, `app/Modules/Imports/Console/ExportDemoFiles.php`): escribe lo sembrado (`DemoSeeder` o `MassiveSeeder`) como un CSV por plantilla (`departments`, `careers`, `study_plans`, `subjects`, `prerequisites`, `students`, `kardex`, `teachers`, `positions`) y un `rooms-<CAMPUS>.csv` por cada campus que tenga salones (la plantilla `rooms` es de un campus; sin borrados, con los inactivos) en `storage/app/imports/<dir>/`, ignorado por git. Los encabezados son las etiquetas de cada plantilla, así que el asistente los reconoce sin mapeo; `careers` trae un renglón por carrera y campus, `teachers` uno por adscripción (departamento) con la principal primero (la primera de un docente es la principal al importar), no se exporta lo borrado y lo inactivo sí. Alumnos y kárdex salen por bloques, sin cargarlos en memoria: con el masivo escribe unos 355 mil renglones de kárdex (13 MB) en unos 30 s con 128 MB de memoria. Sirve para probar el asistente con archivos grandes; se suben en el orden de las reglas de datos.
- **Fixtures de importación** en `tests/Fixtures/imports/`: archivos pequeños con la estructura real de las exportaciones del SII, **anonimizados** (nombres, números de control y números de empleado inventados, claves de materia reales). Sirven para probar plantillas y el asistente de importación de principio a fin.
- **Ids de registros que la prueba no crea:** `fakeKey(10)` (`tests/Pest.php`) da un uuid fijo y válido. Lo usan los departamentos y carreras de los alcances mientras Academic no tenga sus tablas; un id que no es uuid lo rechaza PostgreSQL ([ADR-0014](../decisiones/0014-uuidv7-como-llave-primaria.md)).
- **Nunca datos personales reales en el repositorio.** Las exportaciones reales viven solo en el servidor de pruebas y en las máquinas del equipo, fuera de `git`.

## Base de datos de pruebas

Las pruebas corren contra **PostgreSQL** en la base `nexus_test` (creada por el script de inicialización de `docker/postgres/`), con `RefreshDatabase` para aislar cada test. Las pruebas unitarias no tocan base de datos.

El script `docker/postgres/10-create-test-database.sh` se monta solo en `compose.override.yaml` (desarrollo): producción no tiene base de pruebas. Corre únicamente con el volumen vacío; con un volumen existente se crea a mano con `docker compose exec -T postgres createdb -U nexus nexus_test`. Para correr pruebas en paralelo desde varios worktrees, cada uno usa su propia base con `-e DB_DATABASE=nexus_test_<x>` (ver `CONTRIBUTING.md`).

`phpunit.xml` sube `memory_limit` a 512 MB. `php artisan test` corre unit, feature, arch y browser en un solo proceso, y cada prueba de Laravel deja algo de memoria: hacia la prueba 570 ya iban 121 MB y los browser tests no arrancaban con los 128 MB de la imagen `dev`. En CI cada job corre en su propio proceso.

No se usa SQLite porque Nexus depende de comportamiento propio de PostgreSQL: restricciones de exclusión para empalmes, `jsonb` en perfiles y snapshots, rangos de tiempo, y detalles de ordenamiento y colación. Probar en un motor distinto al de producción deja pasar justo los errores que más cuestan en el piloto.

## Browser tests en Docker

Los browser tests (`tests/Browser`) usan Playwright con Chromium sin pantalla. Todo lo necesario vive en la etapa `dev` del `Dockerfile`: Node, el Chromium de Playwright (solo `chromium-headless-shell`) y sus librerías de sistema, instalados en `/ms-playwright` fuera de `/app`. La imagen `prod` no los lleva.

```bash
docker compose exec app php artisan test --testsuite=Browser
```

- El paquete `playwright` se instala con `npm install` (el contenedor `vite` lo hace al arrancar); si `node_modules` no existe, la prueba falla con "Playwright is not installed".
- La versión de Chromium de la imagen se lee de `package-lock.json` al construir, así que siempre coincide con el paquete `playwright`. Al subir la versión basta con `npm install -D --save-exact playwright@<x>` y reconstruir la imagen.
- Hay que reconstruir la imagen (`docker compose up -d --build`) después de mezclar un cambio del `Dockerfile` o de la versión de `playwright`.
- Los tests visitan rutas con `visit('/ruta')`; el servidor HTTP lo levanta el propio plugin dentro del contenedor, no hace falta `nginx` ni `app`.
- Ese servidor no sirve para subir archivos con Livewire: deja plano el campo `files[]` con que Livewire los manda (`LaravelHttpServer::parseMultipartBody` del plugin) y la subida nunca llega. Una pantalla con subida se prueba en el navegador desde un estado ya subido, y la subida misma con una prueba feature (`Livewire::test()->set('file', UploadedFile::fake()...)`), como el asistente de importación.
- `TestCase` llama a `withoutVite()` para unit y feature, pero los browser tests cargan el CSS y el JS compilados (`tests/Pest.php` llama a `withVite()` en `tests/Browser`): la banda, la credencial y el menú del shell no funcionan sin ellos. Antes de correrlos hay que compilar con `docker compose exec vite npm run build` (en un worktree, con `-w /app/.worktrees/<rama>`); sin `public/build/manifest.json` fallan con "Vite manifest not found". Usan siempre ese manifiesto, aunque el contenedor `vite` esté sirviendo en modo desarrollo, porque Chromium, dentro de `app`, no llega a su servidor. CI compila en el job `browser` antes de las pruebas.
- Una prueba que use el teclado o el shell justo al entrar espera a que Alpine arranque (`assertScript('window.Alpine?.$data(document.body).menuOpen === false')`): un atajo que llega antes no tiene quién lo escuche.

## Revisión visual con volumen

Antes del PR de una pantalla, DESIGN.md pide revisarla a 390, 768 y 1440 px en claro y oscuro. Con pocos datos una pantalla puede verse bien y romperse con nombres reales y miles de filas, así que se revisa con el `MassiveSeeder` en una base aparte, sin tocar la de desarrollo:

```bash
docker compose exec -T postgres createdb -U nexus nexus_massive
docker compose exec -T -e DB_DATABASE=nexus_massive app sh -c 'php artisan migrate:fresh --force && php artisan db:seed --class="Database\Seeders\MassiveSeeder" --force'
docker compose exec -d -e DB_DATABASE=nexus_massive app php artisan serve --host=127.0.0.1 --port=8099
docker compose exec -T -e LOGIN=coord.isc.c2 -w /app app node .github/scripts/review-screens.mjs alumnos=/students
docker compose exec -T -e LOGIN=admin -w /app app node .github/scripts/review-screens.mjs materias=/academic/subjects
```

- `review-screens.mjs` entra con `LOGIN` (contraseña `PASSWORD`, por omisión `password`, la de los usuarios demo), abre cada ruta a los anchos de `WIDTHS` (390, 768 y 1440 por omisión) y en claro y oscuro, guarda las capturas en `storage/app/review/` (ignorado por git) y reporta estado, tiempo de carga y desborde horizontal. Termina con error si alguna pantalla desborda, responde con error o lanza un error de JavaScript.
- El admin, `subdir` y los `jefe.<depto>` trabajan en todos los campus activos (Campus I y Campus II lo están con `DemoSeeder` y con el masivo), así que no tienen un campus activo por omisión. Las pantallas de un campus (alumnos) se revisan con un usuario de un solo campus, como `coord.isc.c2`; las de admin (materias), con `LOGIN=admin`.
- Las capturas se miran: el script encuentra desbordes, no textos cortados ni columnas que se parten en tres renglones.
- Al terminar, detén el servidor (`docker compose restart app` o mata el proceso de `artisan serve`) y borra la base con `dropdb`.

## Pruebas de rendimiento

Hay tres presupuestos que se miden, no se suponen:

| Qué | Presupuesto | Cómo se mide |
|---|---|---|
| Validador sobre una carga completa de un campus (unos 400 grupos del `DemoSeeder`) | menos de 5 segundos en job | Test de feature que siembra la carga, cronometra el job de recálculo de `schedule_conflicts` y falla si excede el presupuesto |
| Movimiento en el editor de horarios (colocar o mover una sesión y recibir los mensajes del validador) | menos de 300 ms | Test de feature sobre el componente Livewire con la carga del seeder, cronometrando la acción |
| Importación de kardex grande | memoria estable y tiempo lineal | Test de feature con un fixture generado de decenas de miles de filas que verifica que se procesa por chunks y que la memoria no crece con el tamaño del archivo |

Para que no sean inestables en CI: los presupuestos de tiempo en CI se fijan al doble del presupuesto local (las máquinas de CI son más lentas y compartidas); los tests marcan el grupo `rendimiento` y corren en un job propio que informa pero no bloquea el merge, salvo que excedan el doble del presupuesto tres ejecuciones seguidas, caso en que se abre un issue `priority: p0`. Nunca se afloja el presupuesto para que pase; se optimiza el código.

## Pruebas negativas de seguridad

Cada pantalla y cada Action tiene, además de sus pruebas positivas, al menos:

- Un test por rol que **no** debe poder entrar o ejecutar la acción (un `docente` no asigna docentes; un `coordinador` no valida catálogos).
- Un test de **alcance**: un jefe de un departamento no ve ni edita grupos de otro departamento; un coordinador de Campus I no ve propuestas de Campus II; un usuario inactivo no entra.
- Un test de que la acción **sin pasar por su Action** (por ejemplo, una petición directa de Livewire a un método no expuesto) queda rechazada.

Estas pruebas se escriben al mismo tiempo que las positivas, en el mismo archivo. La fase 4 incluye una revisión transversal (`/security-review`) que no sustituye a estas pruebas.

## Cobertura

Mínimo **80 % de líneas por módulo** desde la fase 1, medido en CI con el reporte de cobertura de Pest y verificado por módulo, no solo global. Cuenta el código PHP del módulo salvo Blade, componentes MFC, migraciones y factories (por qué, en "CI → Cobertura"): los componentes no quedan sin pruebas, se cubren con feature y browser. Un módulo nuevo entra al repositorio ya con su cobertura; no hay periodo de gracia. La cobertura es un piso, no un objetivo: 80 % con tests que no afirman nada no cumple el principio de evidencia.

## CI

Workflow: `.github/workflows/ci.yml`. Se ejecuta en todo PR, sin importar su rama base (también los encadenados sobre otra `feature/*`), a mano (`workflow_dispatch`) y en cada push a `develop` y `main`. El push solo calienta la caché de la imagen: un PR lee las cachés de su rama, de su rama base y de `main`, así que un PR encadenado no encuentra la de `develop` y su primera corrida construye la imagen completa (unos 5 minutos, ver "Duración"). Una corrida nueva sobre el mismo PR cancela la anterior.

| Job | Contenido | Bloquea el merge |
|---|---|---|
| `lint` | Pint en modo verificación (`./vendor/bin/pint --test`) | Sí |
| `tests` | Unit, feature y arquitectura contra PostgreSQL y Valkey; reporte de cobertura por módulo | Sí |
| `browser` | Browser tests de Pest 5 en el contenedor `dev` con Chromium | Sí |
| `rendimiento` | Presupuestos de rendimiento (aún no existe) | No (informa; ver arriba) |

Los nombres `lint`, `tests` y `browser` son los checks requeridos de la protección de `develop` y `main` (tarea 0.12): no se renombran sin actualizar la protección.

### Cómo corre

CI usa lo mismo que el desarrollo local: la etapa `dev` del `Dockerfile` y los servicios de `compose.yaml` (PostgreSQL 18 y Valkey, mismas imágenes fijadas por digest; `nexus_test` la crea el script de `docker/postgres/` porque el volumen nace vacío). Cada job:

1. Construye la imagen `dev` con Buildx y la caché de GitHub Actions (`type=gha`) y la etiqueta `nexus-app:dev`, que es la que espera `compose.override.yaml`; así `docker compose run` no reconstruye nada. Los build args `UID`/`GID` son los del usuario del runner, para que el usuario `app` de la imagen pueda escribir en el código montado en `/app`.
2. Crea `.env` desde `.env.example` con un `APP_KEY` generado (igual que el README).
3. Instala Composer dentro del contenedor (`browser` también hace `npm ci`: `pest-plugin-browser` busca `node_modules/.bin/playwright`; Chromium ya viene en la imagen).
4. Levanta PostgreSQL y Valkey (`lint` no los necesita) y corre su comando con `docker compose run --rm -T app ...`.

Los pasos 1 a 3 viven en la acción local `.github/actions/setup-ci`. Solo el job `tests` guarda las capas de la imagen en la caché (un solo escritor evita carreras); los tres la leen.

Cachés: capas de la imagen (`type=gha`, incluye Chromium y las extensiones de PHP), descargas de Composer (clave `composer.lock`) y de npm (clave `package-lock.json`). Si cambia el `Dockerfile`, la primera corrida reconstruye desde la capa modificada.

Las actions están fijadas por SHA completo con la versión en un comentario, igual que las imágenes por digest. Para actualizar una, cambia SHA y comentario juntos.

### Cobertura

La imagen `dev` trae `pcov`. El job `tests` corre con `--coverage-clover` y `--coverage-text`, y `.github/scripts/coverage-summary.sh` deja en el resumen del job la cobertura de líneas por módulo de `app/Modules/` y el reporte completo. Desde la fase 1 es bloqueante: el paso falla si un módulo queda debajo de 80 % y nombra cuál. Los browser tests no entran al reporte.

- Los totales salen del reporte Clover y no del de texto: el de texto omite las clases sin ninguna línea ejecutada, y un módulo con clases sin pruebas saldría con más cobertura de la real.
- `phpunit.xml` saca del cálculo los `*.blade.php` y los componentes MFC (`app/Modules/*/Livewire`). Livewire los compila a `storage/framework/views` y ejecuta la copia, así que sobre el archivo fuente la cobertura siempre da cero aunque las pruebas feature los recorran. La lógica que el mínimo debe vigilar vive en Actions, Queries, Policies, Rules, Jobs y Models; los componentes se prueban con feature y browser.
- También saca migraciones y factories (`app/Modules/*/Database/Migrations` y `Factories`): corren en toda prueba e inflarían el mínimo de un módulo con Actions sin cubrir. Los seeders sí cuentan.
- El código fuera de `app/Modules/` sale en la tabla como informativo, sin mínimo.
- El paso también falla si el Clover no trae ningún módulo: un cambio de formato o de rutas no pasa como cobertura suficiente.

### Duración

Meta: menos de **10 minutos** en total para `lint` + `tests` + `browser`. Los tres jobs corren en paralelo, así que el tiempo de reloj es el del más lento. Medido en el PR #77 (los tres jobs en paralelo): unos **5 minutos** con la caché de la imagen vacía (la primera corrida, o tras cambiar el `Dockerfile`; casi todo es construir la imagen con Chromium) y **menos de 2 minutos** con la caché caliente. Si se excede, primero se paraleliza por módulo, después se revisa qué browser tests se pueden bajar a feature.

### Cuando falla

- Cada job muestra la salida de Pest en su log; el resumen del job `tests` lleva la cobertura.
- Si falla un browser test, el job sube las capturas de `tests/Browser/Screenshots` como artefacto `browser-screenshots`.
- Para reproducirlo en local, los mismos comandos: `docker compose exec app ./vendor/bin/pint --test`, `docker compose exec app php artisan test --testsuite=Unit,Feature,Arch` y `docker compose exec app php artisan test --testsuite=Browser`.

## Pruebas inestables

Un test que a veces pasa y a veces falla se **arregla o se borra** en el mismo día; nunca se reintenta a ciegas ni se configura un reintento automático. Está prohibido marcar `skip` o `todo` para que CI pase: si un test no se puede arreglar hoy, se abre un issue `priority: p0`, se borra el test en un PR que enlaza el issue, y se repone cuando se arregla la causa. Las causas típicas (orden de ejecución, fechas reales, datos compartidos entre tests, esperas fijas en browser) se documentan en el issue para no repetirlas.

## Cómo usar la IA para TDD

El ciclo es rojo → verde → refactor, y la IA participa en cada paso con el humano revisando lo que importa:

1. **Rojo.** La IA escribe el test primero, a partir de la regla de `docs/dominio/reglas-de-negocio.md` o del criterio de aceptación de la fase, **no** a partir de código existente. El humano revisa que el test pruebe lo correcto: que el nombre afirme la regla, que el caso límite esté, que falle por la razón esperada (se corre y se ve fallar).
2. **Verde.** La IA escribe el código mínimo que pasa. Si para pasar hay que cambiar el test, se detiene y se discute: casi siempre el test tenía razón.
3. **Refactor.** Con todo verde, se limpia el código y se vuelve a correr.

No se acepta un PR cuyos tests se escribieron después del código "para cubrir": se nota porque prueban lo que el código hace, no lo que la regla dice, y porque no incluyen casos negativos. El revisor puede pedir ver el historial de commits del PR para comprobar el orden. La revisión con `/code-review` antes de abrir el PR es parte del ciclo, no un sustituto de los tests.

## Qué no se prueba

- El framework y las librerías (que Eloquent guarde, que Livewire renderice, que Fortify autentique): ya están probados por sus autores.
- Getters, setters y relaciones triviales sin lógica.
- Estilos y disposición visual, salvo que un browser test de flujo los necesite para interactuar.
- Configuración que la CI ya verifica al arrancar la aplicación.

Lo que sí se prueba de esas capas es **nuestro uso**: que la relación tenga el scope correcto, que el componente llame a la Action y no al modelo, que la policy esté registrada.
