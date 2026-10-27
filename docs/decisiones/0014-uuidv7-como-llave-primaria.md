# ADR-0014 — UUIDv7 como llave primaria

**Estado:** Aceptada · **Fecha:** 2026-10-27

## Contexto
La fase 0 se cerró con `id` bigint autoincremental en todas las tablas, como decía `modelo-de-datos.md`. Antes de que la fase 1 cree las primeras tablas de Academic, Imports, Students, Faculty y Facilities, el equipo revisó si conviene un identificador que no sea secuencial.

- Escalar horizontalmente la aplicación no lo exige. Varios contenedores `app` o workers de Octane escriben en el mismo PostgreSQL, y una secuencia no choca entre escrituras concurrentes. Los choques solo aparecen con varias bases que escriben por separado, o al consolidar instalaciones independientes. Hoy no hay ningún plan de eso, pero tampoco está descartado: TecNM podría adoptar Nexus con una instalación por tecnológico.
- Las razones que sí aplican a Nexus:
  - **Un solo identificador opaco en todas partes:** URLs, HTML, exportes y el contrato con el solver (R2). En R3 los alumnos verán URLs, y un id secuencial revela volúmenes (cuántos alumnos hay) y permite adivinar registros ajenos si una policy falla.
  - **Ids generados en la app antes de insertar:** el solver devuelve miles de sesiones con filas hijas, y con UUID se insertan en bloque sin pedirle cada id a la base.
- **Momento:** hoy no hay ninguna base desplegada, y el cambio es un PR. Con datos de producción y llaves foráneas en todos los módulos, sería una migración delicada.

## Decisión
| Tema | Decisión |
|---|---|
| Llave primaria | `uuid` con **UUIDv7**, en todo modelo de Nexus. El modelo usa `HasUuids` de Laravel, que la genera en la app (`Str::uuid7()`). La columna lleva además `default uuidv7()` de PostgreSQL 18, para los inserts que no pasan por el modelo (las importaciones en bloque). |
| Llaves foráneas | `foreignUuid()`; columnas sin FK todavía (`role_scopes.department_id`, `career_id`) en `uuid()`. |
| Polimórficas | `nullableUuidMorphs()` en la bitácora (`subject`, `causer`); `model_id` de spatie (`model_has_roles`, `model_has_permissions`) en `uuid`. Una columna polimórfica guarda un solo tipo, así que todos los modelos que apunta deben usar UUID. |
| Infraestructura | Conservan su llave las tablas que no son entidades de Nexus: `jobs` y `failed_jobs` de Laravel, `roles` y `permissions` de spatie (se buscan por nombre y nunca salen en URLs), y `activity_log`, cuyo id secuencial da el orden total de una bitácora que solo crece. Las sesiones y los lotes de trabajos ya tienen llave de texto propia. |
| Ids del cliente | Un id que llega del navegador (propiedad de Livewire, argumento de un método, ruta) puede no ser uuid, y PostgreSQL lo rechaza con un error que sería un 500. Las Queries buscan con `FindByKey::orFail()` (`Core/Queries`), que trata un valor que no es uuid como un registro que no existe. En Blade, el id va entre comillas en `wire:click` y `spinner` (`edit('{{ $campus->id }}')`). |
| Orden | UUIDv7 empieza por el tiempo, y el generador de Laravel es monótono dentro del proceso (incrementa dentro del mismo milisegundo). Aun así, los listados ordenan por una columna del dominio (código, nombre, fecha), no por `id`. |

**Excepción única a "una migración mezclada en `develop` no se edita":** este PR edita las migraciones de Core de la fase 0 en lugar de crear migraciones que conviertan los tipos. Se aceptó porque no hay ninguna base desplegada y la conversión dejaría ruido permanente. Cada quien corre `php artisan migrate:fresh --seed` una vez después de mezclarlo. La regla vuelve a aplicar sin excepciones desde aquí.

## Consecuencias
- `tests/Arch/ModulesTest.php` exige `HasUuids` con UUIDv7 en todo modelo de `app/Modules/*/Models`. `tests/Feature/Core/PrimaryKeysTest.php` revisa en el esquema que ninguna llave ni referencia quede en bigint fuera de las tablas de infraestructura, y que toda llave uuid tenga `default uuidv7()`.
- Las pruebas que necesitan un id de un registro que no crean (departamentos y carreras mientras Academic no tenga tablas) usan `fakeKey(10)` de `tests/Pest.php`: un uuid fijo y válido.
- Cada llave pesa 16 bytes en lugar de 8; a la escala de Nexus (decenas de miles de alumnos, cientos de miles de filas de kardex) los índices crecen unos MB.
- Depurar con UUIDs es menos cómodo que con enteros. Se compensa porque las consultas a mano van por las claves naturales: código de campus, número de control, número de empleado.
- Un UUIDv7 revela su fecha de creación al milisegundo; en Nexus no es un dato sensible.
- Las claves naturales con datos personales (número de control, RFC, CURP) siguen sin ir en URLs: la URL lleva el UUID.

## Alternativas descartadas
- **bigint interno y una columna `public_id` UUIDv7 solo en los modelos con URL propia.** No exige trabajo hoy, pero deja para siempre dos identificadores por registro, ids secuenciales en el HTML (valores de selects, `wire:key`) y no resuelve una consolidación entre instalaciones.
- **UUID solo como llave de los modelos "importantes".** Rompe las columnas polimórficas, que guardan un solo tipo para todos los modelos a los que apuntan.
- **Migraciones nuevas que conviertan los tipos.** Respeta la regla al pie de la letra para llegar al mismo esquema, con más riesgo (llaves foráneas que soltar y recrear) y ruido permanente en el historial de migraciones.
- **ULID.** También ordenado por tiempo, pero PostgreSQL no tiene tipo ni generador nativos: se guardaría como texto de 26 caracteres o se convertiría a uuid de todos modos.
