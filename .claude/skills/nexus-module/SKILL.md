---
name: nexus-module
description: Usar cuando se crea un módulo nuevo en app/Modules de Nexus (Academic, Facilities, Faculty, Students, Imports u otro del mapa de dependencias) o cuando a un módulo existente se le agrega una pantalla CRUD de catálogo, una Action, una Query, una Policy, una migración, una factory o una entrada del menú.
---

# Módulo de Nexus con la forma del CRUD de Campus

Todo módulo copia la forma del módulo de referencia, el CRUD de Campus de `app/Modules/Core`; no la reinventa. Cada paso remite al archivo de Campus que hay que abrir y copiar cambiando nombres. Si algo del módulo nuevo no cabe en esa forma, o una regla o permiso no está en `docs/`, pregunta en el issue: no inventes.

- Se copia la forma, no las reglas de Campus. El código en mayúsculas, el perfil de flujo, `updateProfile`, `canEditData` y lo que hace la subdirección son propios de Campus. Las reglas, claves y permisos del módulo nuevo salen de `docs/dominio/reglas-de-negocio.md` y `docs/dominio/modelo-de-datos.md`.
- `<M>`: carpeta del módulo en PascalCase (`Academic`). `<alias>`: su kebab-case (`academic`, `catalog-validation`), que es el namespace de Livewire, vistas y traducciones, y el prefijo de rutas.
- Rutas cortas como `Policies/CampusPolicy.php` son relativas a `app/Modules/Core/`.
- TDD: cada pieza empieza por su prueba (paso 13 dice cuál y dónde) y la ves fallar antes de escribirla. REQUIRED SUB-SKILL: superpowers:test-driven-development.
- Contexto completo, solo si hace falta: `docs/arquitectura/arquitectura.md` ("Estructura de un módulo", "Registro de un módulo", "Autorización y alcance", "Navegación", "Módulo de referencia: Campus").

Comandos (no hay PHP local; desde un worktree, siempre con `-w`, y nunca `docker compose up`):

```bash
docker compose exec -T -w /app/.worktrees/<wt> app php artisan make:livewire <alias>::pages.<recurso>
docker compose exec -T -w /app/.worktrees/<wt> app php artisan make:migration create_<tabla>_table --path=app/Modules/<M>/Database/Migrations
docker compose exec -T postgres createdb -U nexus nexus_test_<x>      # una vez por worktree (CONTRIBUTING.md)
docker compose exec -T -e DB_DATABASE=nexus_test_<x> -w /app/.worktrees/<wt> app php artisan test
docker compose exec -T -w /app/.worktrees/<wt> app ./vendor/bin/pint  # sin --dirty: el contenedor no tiene git
```

## Pasos

### 1. Ubicar el módulo en el mapa

- La carpeta se llama como un módulo del mapa de `docs/dominio/modelo-de-datos.md` ("Mapa de dependencias entre módulos"). `tests/Arch/ModulesTest.php` lo copia en `$leftOf` y falla con una carpeta que no esté ahí.
- Solo importa clases de los módulos a su izquierda, transitivamente: Academic → Core; Facilities, Faculty y Students → Academic y Core; Imports → Core, Academic, Faculty, Facilities y Students. La prueba revisa también el texto de rutas, migraciones, MFC y Blade, incluidos los alias (`scheduling::`).
- Hacia la derecha se avisa con eventos propios (paso 7). Escuchar el evento de un módulo de la derecha también es importarlo: si hace falta, decídelo en el issue antes de escribirlo.
- Cambiar el mapa es cambiar el diagrama y `$leftOf` en el mismo PR (una prueba los compara); pregunta antes.

### 2. Carpeta y provider

- `app/Modules/<M>/<M>ServiceProvider.php`, `final`, extiende `App\Modules\Core\ModuleServiceProvider`. La base registra por convención rutas, migraciones, vistas, Livewire, traducciones y policies, solo lo que exista (ver `ModuleServiceProvider.php`); el provider no repite nada de eso:

  ```php
  namespace App\Modules\Academic;

  use App\Modules\Core\ModuleServiceProvider;

  final class AcademicServiceProvider extends ModuleServiceProvider
  {
      public function navigation(): array
      {
          return []; // un NavigationItem por pantalla (paso 11)
      }
  }
  ```

- Si sobrescribes `boot()`, llama a `parent::boot()`. Nada estático ni singletons con estado (Octane): lo que dependa de la petición se registra con `scoped`, como `ScopedContext` en `CoreServiceProvider.php`.
- Regístralo en `bootstrap/providers.php` en el orden de `$leftOf` (Core, Academic, Facilities, Faculty, Students, Imports, …), entre los que ya existan; ese orden es también el de la barra lateral. Sin registro, `make:livewire <alias>::…` no encuentra el alias.
- Crea solo las carpetas que uses; la lista completa está en "Estructura de un módulo".

### 3. Migraciones

- Siempre con `--path=app/Modules/<M>/Database/Migrations`: sin él, la migración cae fuera del módulo. Modelo: `Database/Migrations/2026_10_27_000001_create_campuses_table.php`.
- Catálogo: clave natural única (un registro borrado la sigue ocupando), la que dicen `reglas-de-negocio.md` ("Reglas de datos") y el modelo de datos. Puede ser compuesta: `rooms.code` es única por campus, `$table->unique(['campus_id', 'code'])`. Si no está escrita, pregunta. Además: `is_active` con `default(true)`, `timestampsTz()` y `softDeletesTz()`. El stub trae `$table->id()` y `timestamps()`: cámbialos por `$table->uuid('id')->primary()->default(DB::raw('uuidv7()'))` y `timestampsTz()` (ADR-0014). Llaves foráneas con `foreignUuid()`, polimórficas con `nullableUuidMorphs()`. Eventos en `timestampTz`, horas de sesión en `time`, enums como `string`, jsonb para perfiles.
- Tablas y columnas exactamente como `docs/dominio/modelo-de-datos.md`; si cambias algo, actualiza ese documento en el mismo PR. Llaves foráneas solo hacia tablas del módulo o de su izquierda. Academic agrega las FK de `role_scopes.department_id` y `career_id`, que Core dejó sin FK. Al hacerlo, las pruebas que usan ids sueltos (`'department_id' => fakeKey(10)`, `'career_id' => fakeKey(20)`, en muchas de `tests/Feature/Core/`) necesitan filas reales: ajústalas en el mismo PR, acordado en el issue.
- Docblock en español con el porqué (catálogo, clave natural, ADR que aplica).
- Una migración mezclada en `develop` no se edita: crea otra.

### 4. Modelo y factory

- Copia `Models/Campus.php`: `#[Fillable([...])]`, `#[UseFactory(<Modelo>Factory::class)]`, `use HasFactory, HasUuids, SoftDeletes;` (UUIDv7, lo exige arch) y `casts()` con enums y booleanos. Solo relaciones, casts, scopes y accessors: nada de "si el estado es X…", ni `request()`, `session()`, `auth()` o sus fachadas (`tests/Arch/ModulesTest.php`). Las relaciones apuntan solo a la izquierda: `Building::campus()` sí, `Campus::buildings()` no, porque Core importaría Facilities.
- Copia `Database/Factories/CampusFactory.php`: `#[UseModel(<Modelo>::class)]` (fuera de `app/Models` y `database/factories` Laravel no empareja modelo y factory por convención: hacen falta los dos atributos), `definition()` válido con `fake()->unique()` en la clave natural, y estados con nombre del dominio (`inactive()`).
- No uses `make:model` ni `make:factory`: escriben en `app/Models` y `database/factories`.

### 5. Enums

- `Enums/<Nombre>.php`, `enum <Nombre>: string` (la prueba de arquitectura lo exige), valores del modelo de datos en snake_case y `label()` en español para la interfaz. Copia `Enums/Modality.php`.

### 6. Policy

- `Policies/<Modelo>Policy.php` junto a `Models/<Modelo>`: Laravel la descubre sola. Copia `Policies/CampusPolicy.php`: `final`, `use UsesScopedContext;` (`Policies/Concerns/UsesScopedContext.php`, exigido por arch) y cada ability pregunta a `$this->scope($user)`: `isAdmin()`, `has(Role)`, `covers(roles, campus, department:, career:)` o `canAccessCampus()`.
- El docblock cita la fila de `docs/dominio/reglas-de-negocio.md`, "Aprobaciones y permisos". Si la acción no aparece ahí, resuélvelo en el issue antes de la primera prueba: la policy, la Query, la página, el menú y sus pruebas dependen de esa respuesta.
- Si el registro pertenece a un campus o un departamento, la ability recibe ese objetivo: `authorize('create', [Building::class, $campus])` y `covers(…, $building->campus_id, department: …)`.
- Catálogo: sin `delete` ni `forceDelete`; se desactiva con `is_active`.
- `covers()` ya incluye al admin, no lo repitas. El flujo se decide por `$campus->profile`, nunca por `$campus->code`.

### 7. Rules, Actions y eventos

- Reglas del modelo, compartidas por alta y cambio: `Rules/<Modelo>Rules.php`, copia de `Rules/CampusRules.php`. Cada violación es `ValidationException::withMessages(['<campo>' => '…'])` en español, que Livewire muestra junto al campo. La revisión de unicidad filtra por las mismas columnas que la clave (`ensureCodeIsFree` de Campus es global; la de `rooms` va por campus). Las reglas `HC-xx`/`SC-xx` van en `Rules/Constraints/<Nombre>` con su id en el docblock.
- Actions: copia `Actions/CreateCampus.php` y `Actions/UpdateCampus.php`.
  - `final`; dependencias por constructor; `handle()` recibe modelos y datos ya validados en forma. No sabe de roles: qué claves le llegan lo decide la página con la policy.
  - Normaliza fuera; dentro de `DB::transaction()`, en este orden: reglas que leen la base (unicidad con `withTrashed()`), escritura, bitácora, evento.
  - Escritura en `try`/`catch (UniqueConstraintViolationException)` que lanza el mismo error de la regla (`$this->rules->codeTaken(...)`): otra sesión puede ganar la carrera.
  - Bitácora: `activity()->performedOn($model)->event(ActivityEvent::Created)->withChanges(['attributes' => $model->only([...])])->log('Dio de alta …')`.
  - Cambio: solo las claves recibidas (`Arr::only`); relee con `lockForUpdate()` dentro de la transacción y calcula `old` de esa lectura; si `getDirty()` queda vacío, no escribe, no registra ni avisa; bitácora `ActivityEvent::Updated` con `attributes` y `old` de lo que cambió. Un jsonb se compara normalizado: PostgreSQL reordena sus claves.
- Eventos: copia `Events/CampusCreated.php` y `Events/CampusUpdated.php`: `final`, `implements ShouldDispatchAfterCommit`, `use Dispatchable, SerializesModels`. Uno por Action de alta o cambio, aunque nadie lo escuche todavía.

### 8. Queries

- Copia `Queries/CampusQuery.php`, `final`:
  - `forTable(ScopedContext $context, string $search = '', ?bool $active = null, int $perPage = 15)`: al admin todo; a los demás, solo lo que la policy les deja ver, sin repetir la lógica de alcance. Búsqueda con `->tap(fn (Builder $query) => TextSearch::apply($query, ['code', 'name'], $search))` (`Queries/TextSearch.php`); filtro de estado; orden por la clave natural; `paginate()`.
  - `findOrFail(string $id)` carga lo que se edita con `KeyLookup::findOrFail(<Modelo>::query(), $id)`: el id llega del cliente y uno que no es uuid haría fallar a PostgreSQL.
- CampusQuery filtra con `Gate::forUser($context->user())` sobre `ScopedContext::campuses()` porque los campus son el alcance mismo. Una tabla con columnas de alcance (`campus_id`, `department_id`, `career_id`) no se autoriza fila por fila: se filtra en SQL con los alcances de `$context->scopes()`, y una prueba confirma que el resultado coincide con la policy.

### 9. Página MFC

- `make:livewire <alias>::pages.<recurso>` crea `app/Modules/<M>/Livewire/pages/<recurso>/<recurso>.php` y `.blade.php` (MFC por `config/livewire.php`). Sin `--test`: dejaría `<recurso>.test.php` dentro del componente.
- Copia `Livewire/pages/campuses/campuses.php`:
  - `new #[Title('…')] class extends Component` con `use Toast, WithPagination;`.
  - `mount()` autoriza `viewAny`. Cada método público (`create`, `edit`, `save`, `toggleActive`) carga con la Query y vuelve a autorizar: puede llegar de una página que abrió otra sesión. En un catálogo con una sola ability de edición, `edit()` y `save()` autorizan `update`.
  - Catálogo con `campus_id` (Campus no lo tiene porque es el alcance): está sin decidir de dónde sale el campus del formulario (`activeCampus()` de la topbar o un select de `campuses()`), si la tabla filtra por el campus activo y si el campus se puede cambiar. Pregúntalo en el issue.
  - `with(<Modelo>Query $query, ScopedContext $context)` entrega a la vista todo lo que muestra. Nada de `<Modelo>::find()` ni `query()` en el componente, ni consultas en Blade.
  - `#[Locked]` en `editingId` y en las banderas que decide la policy; `#[Session]` en búsqueda y filtros, con `resetPage()` en `updated()`.
  - `validate()` solo de forma, con `validationAttributes()` y `messages()` en español; convierte tipos (números, listas) y llama la Action. Lo que el rol no puede cambiar no se manda, aunque llegue del navegador.
  - Toast: `$this->success('Campus creado', e("{$campus->code} · {$campus->name}"))`. Escapa con `e()`: MaryUI lo pinta con `x-html`.
- Copia `Livewire/pages/campuses/campuses.blade.php`: `x-header` con búsqueda y acción principal, `x-table` con `with-pagination`, `x-modal` con `x-form`, `wire:confirm` al desactivar y `data-testid` para el browser test. Lo que el rol no permite se muestra deshabilitado con su motivo en un tooltip (DESIGN.md, "Nada oculto"); lo de otro alcance no se muestra.

### 10. Ruta

- `app/Modules/<M>/Routes/web.php`, segmentos en inglés en kebab-case. Core no lleva prefijo porque es dueño de `/` (`Routes/web.php`); los demás módulos sí:

  ```php
  Route::middleware('auth')->prefix('academic')->name('academic.')->group(function () {
      Route::livewire('/departments', 'academic::pages.departments')->name('departments');
  });
  ```

- Quién entra lo decide la página (`mount()`), no la ruta.

### 11. Navegación

- En `navigation()` del provider, como `CoreServiceProvider::navigation()`:
  `new NavigationItem('Departamentos', 'academic.departments', 'o-…', ability: 'viewAny', arguments: [Department::class])`.
  Si depende del rol y no de una policy: `visible: static fn (ScopedContext $context): bool => $context->has(Role::Coordinador)`. Siempre `static` y sin estado: el registro es inmutable y se comparte entre peticiones.
- El nombre debe ser una ruta registrada (`tests/Feature/Core/NavigationTest.php` revisa todas). La entrada entra en el mismo PR que su pantalla, con prueba de quién la ve y quién no.
- La misma prueba compara listas exactas de etiquetas por rol: una entrada nueva visible para ese rol la rompe. Actualízala en el mismo PR y avísalo en el issue, porque es una prueba de Core.

### 12. Seeder (si aplica)

- `Database/Seeders/<Modelo>Seeder.php`, copia de `Database/Seeders/CampusSeeder.php`: idempotente (`withTrashed()->firstOrCreate()` por clave natural, no pisa lo editado), solo datos documentados, nunca datos personales reales. Llámalo desde `database/seeders/DatabaseSeeder.php` en el orden del mapa. Prueba como `tests/Feature/Core/CoreSeedersTest.php`.

### 13. Pruebas

`tests/Pest.php` arranca la app y `RefreshDatabase` solo en Feature y Browser: Unit y Arch no tienen base ni contenedor. Descripciones en español, como la frase que afirman, con el id `HC-xx`/`SC-xx` si prueban una regla.

| Nivel | Archivo nuevo | Copia de | Qué cubre |
|---|---|---|---|
| Unit | `tests/Unit/<M>/<Clase>Test.php` | `tests/Unit/Core/TextSearchTest.php`, `tests/Unit/Core/CampusProfileTest.php` | Lo puro: cálculos, value objects, enums |
| Feature: reglas | `tests/Feature/<M>/<Modelo>RulesTest.php` | `tests/Feature/Core/CampusRulesTest.php` | Cada regla y su mensaje |
| Feature: Actions | `tests/Feature/<M>/Create<Modelo>Test.php`, `Update<Modelo>Test.php` | `tests/Feature/Core/CreateCampusTest.php`, `tests/Feature/Core/UpdateCampusTest.php` | Escribe; rechaza sin escribir; bitácora (`Activity::sole()`: causer, `attribute_changes`); evento solo tras el commit (transacción revertida); carrera de unicidad (insert en `creating`); `old` leído con bloqueo; sin cambios no registra |
| Feature: policy | `tests/Feature/<M>/<Modelo>PolicyTest.php` | `tests/Feature/Core/CampusPolicyTest.php` | Cada ability por rol (dataset con todos los roles), por campus o departamento ajeno, admin inactivo, sin `delete` |
| Feature: Query | `tests/Feature/<M>/<Modelo>QueryTest.php` | `tests/Feature/Core/CampusQueryTest.php` | Lo que lista cada rol; búsqueda literal de `%`, `_` y `\`; filtros; borrados fuera; paginación |
| Feature: página | `tests/Feature/<M>/<Recursos>PageTest.php` | `tests/Feature/Core/CampusesPageTest.php` | Un `describe` por rol; errores en español (`assertSee` del mensaje); toast con `lastToastOf()`; 403 en la ruta; 403 en cada método llamado directo, también sobre una página que abrió el admin y luego `actingAs` de otro rol; otro campus u otro departamento; invitado al login; menú |
| Browser | `tests/Browser/<M>/<Recursos>Test.php` | `tests/Browser/Core/CampusesTest.php` | Un flujo por rol: login, menú, alta y edición; el rol sin acceso no ve la entrada; `assertNoJavaScriptErrors()` |
| Arch | — | `tests/Arch/*` | No se edita para un módulo del mapa; tiene que pasar |

- Usuarios: `User::factory()->admin()` y `User::factory()->withRole(Role::Coordinador, ['campus_id' => …, 'career_id' => …])`, que lanza si falta una columna que el rol exige.
- Componente: `Livewire::actingAs($user)->test('<alias>::pages.<recurso>')->call('save')->assertForbidden()`. Después de un `assertForbidden()`, abre un componente nuevo.
- Las funciones auxiliares de un archivo de Pest son globales: nómbralas con el recurso (`newCampus`, `campusCodesFor`, `signInToCampuses`) para que no choquen.

### 14. Documentación

- `docs/dominio/modelo-de-datos.md`: si cambian tablas o columnas, en el mismo PR.
- `docs/decisiones/`: un ADR si agregas una dependencia (Composer o npm) o cambias una decisión.
- `docs/dominio/reglas-de-negocio.md`: el permiso ("Aprobaciones y permisos"), la clave natural ("Reglas de datos") o la regla que se aclaró en el issue.
- `docs/plan/fase-N-*.md`: al cerrar, la tarea marcada con el número de PR. Una tarea grande se divide en PRs que se mezclan en orden (`CONTRIBUTING.md`).
- `docs/arquitectura/arquitectura.md` y este skill: solo si cambia la convención, y los dos a la vez.

## Errores comunes

| Error | En su lugar |
|---|---|
| `where('name', 'ilike', "%{$search}%")` | `TextSearch::apply()`: escapa `%`, `_` y `\` |
| `old` de la bitácora tomado del modelo recibido o fuera de la transacción | Releer con `lockForUpdate()` dentro y calcular `old` de esa lectura |
| Unicidad solo con `exists()` | También `catch (UniqueConstraintViolationException)` con el mismo `ValidationException`; revisar con `withTrashed()` |
| Evento sin `ShouldDispatchAfterCommit` | Lo oyen aunque la transacción se revierta |
| `<Modelo>::find()` en el componente o consultas en Blade | La Query (`findOrFail()`, `forTable()`). Arch no ve las MFC (clases anónimas): revísalo tú |
| Autorizar solo en `mount()` | Autorizar en cada método público y probarlo llamándolo directo |
| `covers(Role::Docente, …)` esperando `true` | `covers()` nunca cubre al docente; su acceso lo deciden Staffing y Scheduling por asignación |
| `if ($campus->code === 'C2')` | El perfil de flujo, `$campus->profile` |
| `wire:click="edit({{ $campus->id }})"` sin comillas, o `findOrFail()` directo con un id del cliente | `edit('{{ $campus->id }}')` también en `spinner`; buscar con `KeyLookup::findOrFail()`. Un uuid sin comillas es un error de JS y uno inválido, un 500 de PostgreSQL |
| `NavigationItem` con una ruta que no existe, o antes que su pantalla | En el mismo PR que la ruta; `tests/Feature/Core/NavigationTest.php` lo atrapa |
| Toast con texto sin `e()` | XSS: MaryUI lo pinta con `x-html` |
| `hasMany` desde un modelo de Core u otro módulo de la izquierda hacia el nuevo | La relación solo del lado nuevo (`belongsTo`); arch lo rechaza |
| Copiar `updateProfile`, `canEditData` o `normalizeCode` de Campus | Son reglas de Campus; las del módulo salen de `reglas-de-negocio.md` |
| Mandar a la Action todo lo que llega del navegador | Armar los datos según la policy (la subdirección solo manda `settings`) |
| Editar una migración ya mezclada en `develop` | Una migración nueva |
| `package-lock.json` cambiado tras `npm install` en el worktree | No lo commitees (`git checkout -- package-lock.json`); `package.json` fija `"name": "nexus"` para que npm no escriba el nombre del worktree |
| `make:livewire --test`, `make:model`, `make:factory` | Escriben fuera de `tests/` o del módulo; crea a mano copiando Campus |
| `pint --dirty` | `pint`: el contenedor no tiene git |

## Checklist antes del PR

Igual que `.github/PULL_REQUEST_TEMPLATE.md` y la "Definición de hecho" de `CONTRIBUTING.md`:

- [ ] Pruebas escritas primero y vistas fallar; suite completa verde (`php artisan test`, con Arch y Browser) y la salida mostrada.
- [ ] Cada regla `HC-xx`/`SC-xx` tocada tiene prueba con su id; cada ability cita su fila de `reglas-de-negocio.md`.
- [ ] Cada pantalla tiene negativas de rol y de alcance llamando cada método de Livewire directo.
- [ ] Las escrituras pasan solo por Actions, con transacción, bitácora y evento.
- [ ] `pint` sin cambios (CI corre `pint --test`); cobertura del módulo de al menos 80 %.
- [ ] Documentación del paso 14 al día, con la tarea de `docs/plan/` marcada con el PR.
- [ ] Sin `package-lock.json` ni migraciones mezcladas editadas en el diff (`git diff --stat develop...`).
- [ ] `/code-review` ejecutado y atendido; PR con la plantilla, `Closes #<issue>` y de preferencia menos de 400 líneas netas.
