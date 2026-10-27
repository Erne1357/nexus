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
| **Arquitectura** (`arch()`) | Que el código respete la convención del monolito modular | Dependencias solo hacia la izquierda del mapa de `docs/dominio/modelo-de-datos.md`; Actions `final`; modelos sin facades de request, sesión o auth; nada de consultas a base de datos desde Blade; todo módulo tiene su ServiceProvider; enums respaldados por string |

La base es ancha en unit: las reglas de negocio son lo que más cambia y lo más barato de probar aislado. Feature cubre integración y permisos. Browser se reserva para los flujos que, si fallan, detienen el piloto.

## Convenciones

- Carpetas: `tests/Unit/<Módulo>/`, `tests/Feature/<Módulo>/`, `tests/Browser/<Módulo>/`, `tests/Arch/`. El módulo del test es el módulo de la clase o pantalla que prueba.
- Un archivo de test por clase o por pantalla. Si un archivo pasa de unas 300 líneas, la clase probada probablemente hace demasiado.
- Nombres descriptivos **en español**, escritos como la frase que afirma el comportamiento, e incluyendo el identificador de la regla cuando aplique: "HC-07: rechaza cupos cuya suma excede la capacidad del grupo", "ordena primero a quien tiene más horas base vigentes".
- Cada test prueba una cosa. Preparación, acción y verificación visibles en ese orden.
- Los tests de feature de pantallas prueban también lo que el usuario **no** puede hacer (ver pruebas negativas).

## Datos de prueba

- **Factories por módulo** en `app/Modules/<Módulo>/Database/Factories/`, con estados nombrados que expresen el dominio: docente de tiempo completo, docente de honorarios, materia con laboratorio, plan inactivo, grupo sin docente, carga cerrada.
- **`DemoSeeder` de Campus II**: las 6 carreras con planes vigentes, tronco común compartido, seriación, docentes con plazas variadas, salones de varios tipos. Es el conjunto de datos de los browser tests, de las demostraciones del viernes y de las mediciones de rendimiento.
- **Fixtures de importación** en `tests/Fixtures/imports/`: archivos pequeños con la estructura real de las exportaciones del SII, **anonimizados** (nombres y números de control inventados, claves de materia reales). Sirven para probar plantillas y el asistente de importación de principio a fin.
- **Nunca datos personales reales en el repositorio.** Las exportaciones reales viven solo en el servidor de pruebas y en las máquinas del equipo, fuera de `git`.

## Base de datos de pruebas

Las pruebas corren contra **PostgreSQL** en la base `nexus_test` (creada por el script de inicialización de `docker/postgres/`), con `RefreshDatabase` para aislar cada test. Las pruebas unitarias no tocan base de datos.

El script `docker/postgres/10-create-test-database.sh` se monta solo en `compose.override.yaml` (desarrollo): producción no tiene base de pruebas. Corre únicamente con el volumen vacío; con un volumen existente se crea a mano con `docker compose exec -T postgres createdb -U nexus nexus_test`. Para correr pruebas en paralelo desde varios worktrees, cada uno usa su propia base con `-e DB_DATABASE=nexus_test_<x>` (ver `CONTRIBUTING.md`).

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

Mínimo **80 % de líneas por módulo** desde la fase 1, medido en CI con el reporte de cobertura de Pest y verificado por módulo, no solo global. Un módulo nuevo entra al repositorio ya con su cobertura; no hay periodo de gracia. La cobertura es un piso, no un objetivo: 80 % con tests que no afirman nada no cumple el principio de evidencia.

## CI

| Job | Contenido | Bloquea el merge |
|---|---|---|
| `lint` | Pint en modo verificación | Sí |
| `tests` | Unit, feature y arquitectura contra PostgreSQL y Valkey; reporte de cobertura por módulo | Sí |
| `browser` | Browser tests de Pest 5 en el contenedor `dev` con Chromium | Sí |
| `rendimiento` | Presupuestos de rendimiento | No (informa; ver arriba) |

Objetivo: menos de **10 minutos** en total para `lint` + `tests` + `browser`. Si se excede, primero se paraleliza por módulo, después se revisa qué browser tests se pueden bajar a feature. Se ejecuta en cada PR a `develop` y a `main`; el check `tests` y el check `browser` son requeridos en la protección de ambas ramas (tarea 0.12).

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
