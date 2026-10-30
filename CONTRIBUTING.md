# Contribuir a Nexus

Reglas de trabajo del equipo. Lo que no esté aquí se decide en el issue y, si es permanente, se agrega aquí por PR.

## Ramas

| Rama | Uso | Protección |
|---|---|---|
| `main` | Producción. Solo recibe PRs desde `develop` cuando la integración de una fase está probada en el servidor de pruebas. Cada mezcla lleva un tag semántico (`v1.0.0` = R1). | PR obligatorio, conversaciones resueltas, CI verde, sin push directo ni para administradores |
| `develop` | Integración. Recibe todas las features por PR. | PR obligatorio, conversaciones resueltas, CI verde, sin push directo |
| `feature/<issue>-<slug>` | Una por tarea del plan, creada desde `develop`. Vive pocos días. | — |
| `fix/<issue>-<slug>` | Correcciones del piloto o de producción. Salen de `develop` (o de `main` si es urgente, y se regresan a `develop`). | — |

Ramas cortas: integra a `develop` al menos cada dos días. Usa un worktree de git por rama para no mezclar cambios entre tareas.

**Worktrees con Docker.** Docker monta solo la carpeta principal del repo en `/app`, así que los worktrees van dentro del repo, en `.worktrees/<rama>` (ignorado por git). Cada uno lleva su propio `vendor` y su propio `.env`, y todo comando se corre con `-w`. Git se usa desde la máquina local; el contenedor no lo tiene.

```bash
git worktree add -b feature/<issue>-<slug> .worktrees/feature-<issue>-<slug> origin/develop
cp .env .worktrees/feature-<issue>-<slug>/.env
docker compose exec -w /app/.worktrees/feature-<issue>-<slug> app composer install
docker compose exec -w /app/.worktrees/feature-<issue>-<slug> vite npm install
docker compose exec -w /app/.worktrees/feature-<issue>-<slug> app php artisan test
```

Después de traer `develop` a la carpeta principal, recarga los workers de Octane con `docker compose exec app php artisan octane:reload` (y corre `migrate` si llegaron migraciones): guardan el código en memoria y, sin recargarlos, el navegador sigue mostrando la versión anterior.

Nunca corras `docker compose up` desde un worktree: comparte el nombre de proyecto con el repo principal y lo pisaría. Los contenedores `app` y `queue` sirven siempre el código de la carpeta principal: en un worktree se prueba con `php artisan test`, no en el navegador. El `.env` copiado comparte base de datos, Valkey y cola con el repo principal, así que no corras `migrate` de una rama sin mezclar contra la base de desarrollo.

**Pruebas en paralelo.** `RefreshDatabase` migra y revierte sobre una sola base, así que dos worktrees corriendo pruebas a la vez sobre `nexus_test` se pisan. Dale a cada worktree su propia base de pruebas y selecciónala con `DB_DATABASE` (`phpunit.xml` lo permite porque no lo fuerza):

```bash
docker compose exec -T postgres createdb -U nexus nexus_test_<x>
docker compose exec -T -e DB_DATABASE=nexus_test_<x> -w /app/.worktrees/feature-<issue>-<slug> app php artisan test
docker compose exec -T postgres dropdb -U nexus nexus_test_<x>   # al cerrar el worktree
```

La base también separa los discos falsos: `tests/TestCase.php` guarda los de `Storage::fake()` y las copias temporales de Livewire en `storage/framework/testing/disks/<disco>_test_<base>`. Así, dos corridas a la vez en el mismo worktree, cada una con su base, no se borran los archivos.

**Volumen de Postgres que ya existe.** El script de `docker/postgres/` solo corre cuando el volumen está vacío. Si ya levantaste el stack antes de la tarea 0.3, crea la base a mano una vez: `docker compose exec -T postgres createdb -U nexus nexus_test`.

## Commits

Formato convencional en inglés, con el módulo como alcance:

```
feat(scheduling): add constraint validator for HC-01..HC-04
fix(imports): keep natural key on re-apply
test(staffing): cover ranking ties
docs(plan): mark 3.2 done in phase 3
chore(ci): cache composer
```

Tipos: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`. Un commit por cambio coherente; se permite squash al mezclar.

## Issues

- Todo trabajo nace de un issue con la plantilla correspondiente (tarea, bug, épica). El issue referencia la tarea del plan (`Fase 3 · 3.2`) y lleva etiquetas `type:*`, `module:*`, `release:*` y `priority:*`, y el milestone de su fase.
- Los issues de tarea salen de la tabla *Tareas* del archivo de la fase, con `.github/scripts/plan-issues.py` (Python 3 y `gh`, desde la raíz del repo). El script crea las etiquetas y milestones que falten, un issue por tarea hasta la fase de `--tasks-through` (4 por defecto, las de R1) y una épica por cada fase posterior, y salta los títulos que ya existen. Corre primero `--dry`, que no escribe nada, y después `--apply`. Al detallar una fase de R2, sube `--tasks-through` a esa fase.

  ```bash
  python3 -I .github/scripts/plan-issues.py --dry docs/plan/fase-*.md
  python3 -I .github/scripts/plan-issues.py --apply --tasks-through 5 docs/plan/fase-5-demanda.md
  ```

- Si descubres trabajo no planeado, abre un issue; no lo metas en el PR actual.
- Las incidencias del piloto llevan la etiqueta `piloto` y se atienden antes que cualquier otra cosa.

## Pull requests

1. Un PR por tarea del plan. Si la tarea es grande, divídela en PRs que se puedan mezclar en orden.
2. Título con el mismo formato que los commits. Descripción con la plantilla: qué, por qué, cómo probarlo, capturas si hay UI, checklist.
3. `Closes #<issue>` en la descripción.
4. CI verde (lint, unit, feature, browser).
5. Revisor cruzado obligatorio (A revisa a B, B a C, C a A). Respuesta en menos de 24 horas. El autor mezcla.
6. Menos de 400 líneas netas cuando sea posible; si no, explica por qué.
7. Corre `/code-review` antes de pedir revisión y atiende lo que encuentre.
8. Si el PR cambia el modelo de datos, incluye el cambio en `docs/dominio/modelo-de-datos.md`. Si agrega una dependencia, incluye la nota en `docs/decisiones/`.

### PRs encadenados

Una tarea que no cabe en 400 líneas se parte en una cadena: el primer PR sale de `develop` y cada uno de los siguientes tiene como base la rama del anterior (por ejemplo, #94 a #98 de la tarea 1.1). El CI corre en todos. Solo el último lleva `Closes #<issue>`; los demás dicen `Parte de #<issue>`.

- Mientras una rama no se ha subido, se puede rebasar sobre su base. Una vez subida, los cambios de la base (por ejemplo, correcciones de la revisión) entran con `git merge <base>`, nunca con rebase y `push --force`.
- `merge` de `origin/<base>` y no de la rama local, para no traer algo que no se ha subido.

**Mezclar la cadena.** `develop` exige que la rama esté al día y con CI verde, así que se mezcla un PR a la vez, del primero al último, con squash. El squash deja en `develop` un commit nuevo con el contenido de la rama de base; si después se mezcla `develop` en el siguiente PR sin más, git ve el mismo cambio dos veces y da conflictos falsos en todo lo que ese PR modificó encima. El primero se pone al día con `git merge origin/develop`, como cualquier PR. Para cada uno de los siguientes, con `<n-base>` el número del PR anterior:

```bash
git fetch origin
squash=$(gh pr view <n-base> --json mergeCommit --jq .mergeCommit.oid)
git diff --quiet "$squash" origin/<rama-base> && echo ok   # el squash tiene el mismo contenido que la base
git merge origin/<rama-base>          # lo último de la base, si trae algo
git merge -s ours "$squash"           # el squash ya está en la rama: solo se registra como mezclado
git merge origin/develop              # lo que haya entrado a develop después, de otros PRs
gh api -X PATCH repos/Erne1357/nexus/pulls/<n> -f base=develop
git push
```

- Si la comprobación del `git diff` no imprime `ok`, el `-s ours` no es seguro: detente y revisa qué le falta a la rama.
- `-s ours` solo va contra el commit del squash, nunca contra `origin/develop`. Si a `develop` entró otro PR, `-s ours` sobre `develop` haría que el squash de este PR revirtiera ese otro.
- Un conflicto real en el último `merge` se resuelve por bloque, conservando los dos lados. No tomes el archivo completo de un lado: borrarías lo que trajo el otro.
- `gh pr edit --base` falla en este repo por la baja de Projects clásico; por eso la base se cambia con `gh api`.
- Antes del squash, compara el diff del PR con el que tenía: deben ser los mismos archivos y líneas.
- `Closes #<issue>` no cierra el issue al mezclar a `develop`, solo a `main`: ciérralo a mano y mueve la tarjeta a **Hecho**.

## Definición de hecho

Una tarea está hecha cuando:

- [ ] Tiene pruebas en los niveles que aplican (unit, feature, browser) y todas pasan en CI.
- [ ] Cada regla de negocio que toca tiene su prueba nombrada con el id de la regla.
- [ ] Cada pantalla nueva tiene prueba negativa de alcance (otro rol u otro campus).
- [ ] Las escrituras pasan por Actions con bitácora.
- [ ] Pint sin cambios.
- [ ] Documentación actualizada: archivo de la fase (tarea marcada con el PR), modelo de datos o decisiones si aplica.
- [ ] Revisado por otra persona y mezclado en `develop`.

## Revisión de código

Qué buscar primero: reglas de negocio mal interpretadas, alcance por rol, transacciones y bitácora, pruebas que no prueban lo que dicen, estado compartido bajo Octane, consultas N+1 en listados. Después: nombres, duplicación, consistencia con el módulo de referencia. No se aprueba "por confianza": si no se entiende, se pregunta en el PR.

## Pruebas

Ver `docs/arquitectura/testing.md`. Resumen: TDD, PostgreSQL de pruebas, cobertura mínima 80 % por módulo, prohibido saltar pruebas para pasar CI, pruebas inestables se arreglan o se borran.

## Datos

Los archivos exportados del SII contienen datos personales. Van en `storage/app/imports/` (ignorado por git). Los fixtures de pruebas se anonimizan (nombres y números de control ficticios). Nunca se pega un kardex real en un issue, PR o chat con la IA.

## Releases

1. Al terminar la integración de una fase en el servidor de pruebas, se abre PR `develop` → `main` con el resumen de la fase.
2. Al mezclar, tag `vX.Y.Z` y despliegue con la imagen `prod` (`APP_IMAGE_TAG` = tag).
3. Se actualiza `PLAN.md` con la fecha y el tag.
