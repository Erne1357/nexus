# ADR-0022 — Un esquema de PostgreSQL por módulo, y el SII original en el suyo

**Estado:** Aceptada · **Fecha:** 2026-11-10

## Contexto
Todas las tablas de Nexus viven en el esquema `public`: más de cien entre Core, Academic, Students, Scheduling, Staffing y los demás módulos, junto con las tablas de Laravel (`migrations`, `jobs`, `cache`, `sessions`). La migración del SII agregó además datos que no son del dominio: el archivo de la exportación original y los resultados de compararla contra Nexus en cada corrida. El monolito ya está partido en módulos con dueño y un mapa de dependencias (`docs/dominio/modelo-de-datos.md`), pero la base no lo refleja: no se puede respaldar, revisar ni dar permisos por módulo, y el archivo crudo del SII se mezclaría con las tablas de la aplicación.

El usuario lo pidió como decisión firme el 10 de noviembre de 2026: aprovechar los esquemas de PostgreSQL, con los módulos grandes cada uno en su esquema, los datos crudos del SII en un esquema propio y lo común en `core` o `public`, según la mejor práctica.

## Decisión
1. **Un esquema por módulo de dominio**, con el nombre del módulo en `snake_case`: `core`, `academic`, `facilities`, `faculty`, `students`, `imports`, `catalog_validation`, `demand`, `academic_load`, `scheduling`, `staffing` y `enrollment`. Cada tabla vive en el esquema del módulo dueño de su migración. Las llaves foráneas entre esquemas son normales en PostgreSQL y siguen el mapa de dependencias. Un módulo que nace después (por ejemplo, `demand` o `enrollment`) crea sus tablas en su esquema desde el principio.
2. **`public` queda para la plomería del framework y de los paquetes**, que no es de ningún módulo: `migrations`, `cache` y `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `password_reset_tokens` y las extensiones (`btree_gist`, ADR-0016). Lo que sí es del dominio de Core va en `core`: `users`, campus, unidades, puestos y nombramientos, y la bitácora (`activity_log`), que en Nexus es parte del dominio porque nunca se edita ni se borra.
3. **El SII original vive fuera del dominio, en esquemas del módulo Imports:**
   - `sii_archivo`: la exportación tal cual, un archivo por tabla, sin el NIP, la contraseña de acceso (`acceso.contrasena`), las fotos, las firmas ni los datos personales de docentes que Nexus no usa (descripción física, familia, cartilla militar y domicilio; confirmado el 11 de noviembre de 2026). Es de solo lectura y cada corrida de `imports:migrate-sii` lo reemplaza.
   - `sii_verificacion`: lo que compara cada corrida entre el original y Nexus.

   No se crean con migraciones: los crea y reemplaza la corrida. `migrate:fresh` no los toca (decisiones del usuario del 10 de noviembre de 2026; `docs/arquitectura/migracion-sii.md`).
4. **Cómo lo ve la aplicación:** `config/database.php` pone en `search_path` todos los esquemas de módulo y al final `public`. Así siguen funcionando, sin cambios:
   - los modelos con nombres sin esquema;
   - las consultas a mano;
   - `Schema::hasTable()`;
   - los drivers de cola, caché y sesión.

   `migrate:fresh` borra las tablas de todos los esquemas del `search_path`. Lo revisamos en `PostgresBuilder::dropAllTables()` de Laravel 13, que recorre `getCurrentSchemaListing()`. Los esquemas `sii_*` quedan fuera del `search_path` a propósito: se consultan siempre con el nombre completo (`sii_archivo.historia_alumno`).
5. **Reglas para las migraciones:**
   - Una tabla nueva se crea con su esquema explícito (`Schema::create('academic.subjects', …)`), porque sin esquema caería en el primero del `search_path`.
   - Un nombre de tabla no se repite entre esquemas, para que el `search_path` nunca sea ambiguo. Una prueba de arquitectura revisa las dos cosas.
   - El esquema de cada módulo lo crea una migración de Core (`create schema if not exists`), antes que cualquier otra.
6. **Cuándo se hace:**
   - `sii_archivo` y `sii_verificacion` nacen ya en su esquema, con la tarea 1.16.
   - Las tablas existentes se mueven en un PR propio cuando cierren los rieles de la fase 4, para no chocar con las migraciones de tres rieles en paralelo. Cada módulo tiene una migración con `alter table … set schema <módulo>`, que conserva datos, índices, restricciones, llaves foráneas y secuencias.
   - Hasta entonces, las tablas nuevas de los rieles se crean como hoy y ese PR también las mueve.

## Consecuencias
- Se puede respaldar o restaurar un módulo (`pg_dump -n academic`), dar permisos por esquema (por ejemplo, que solo el administrador lea `sii_archivo`) y ver de un vistazo qué es de quién (`\dt scheduling.*`).
- El PR que mueve las tablas tiene que corregir el código y las pruebas que preguntan por `table_schema = 'public'` o por `pg_tables.schemaname = 'public'`. También tiene que actualizar el `search_path` de CI, de los contenedores y de las bases de prueba en paralelo (`nexus_test_*_test_N`).
- `docs/dominio/modelo-de-datos.md` dirá el esquema de cada módulo. CLAUDE.md y la skill `nexus-module` dirán que una migración nueva crea su tabla con esquema.
- El `search_path` largo no es aislamiento: un módulo todavía puede leer cualquier tabla sin poner el esquema. Las fronteras entre módulos las siguen cuidando el mapa de dependencias y las pruebas de arquitectura. El esquema ordena, respalda y permite dar permisos.

## Alternativas descartadas
- **Todo en `public`:** es lo que hay. No permite respaldar ni dar permisos por módulo y mezcla el SII crudo con la aplicación.
- **Esquema obligatorio en cada modelo y consulta, sin `search_path`:** es más explícito, pero obliga a tocar cada modelo (`$table`), cada consulta a mano y cada `constrained()`, sin ganar nada sobre la regla de nombres únicos.
- **Una base de datos por módulo:** PostgreSQL no tiene llaves foráneas ni transacciones entre bases, y los módulos se cruzan todo el tiempo (un grupo apunta a materia, docente y aula).
- **Guardar el SII crudo como JSON en una tabla:** repite el nombre de cada columna en cada renglón (casi el doble de espacio) y es más lento de consultar y de cruzar con Nexus. Solo convendría si las columnas cambiaran entre exportaciones, y la migración exige siempre los mismos encabezados.
