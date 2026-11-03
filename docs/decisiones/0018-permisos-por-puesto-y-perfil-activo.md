# ADR-0018 — Permisos por puesto, nombramientos, árbol de unidades y perfil activo

**Estado:** Aceptada · **Fecha:** 2026-11-02

## Contexto
Nexus crecerá hasta ser el ERP del ITCJ y su autorización tiene que acompañarlo. Hoy:
- Todos los roles de una persona valen a la vez; no puede elegir con qué puesto actúa, y la credencial muestra el primero del enum.
- No hay permisos granulares: las listas de roles están escritas a mano en unos quince lugares (`AcademicLoadPolicy::VIEWERS` y sus copias, `TeacherPolicy::EDITORS`, pares `[Coord, Sub]`).
- La regla de alcance existe una vez en PHP (`ScopedContext`) y tres más en SQL (`TeacherQuery::coveredDepartments`, `StudentQuery::visible`, `RecipientsQuery`).
- Hay tres fuentes de verdad del puesto: `model_has_roles` (spatie), `role_scopes.role` y `department_heads`. Spatie no aporta nada más: cero permisos y sin `config/permission.php`.
- El admin es un atajo global que obliga a excepciones escritas a mano, y no hay caché entre peticiones ni puesto en la bitácora.

El usuario pidió (2 de noviembre de 2026) estructurarlo bien desde el principio, con permisos granulares, atributos y un perfil activo, y dejar lista la puerta a las áreas administrativas del próximo año.

## Decisión
1. **Permisos por módulo.** Cada módulo declara los suyos en un enum respaldado por string (`modulo.recurso.accion`), con etiqueta en español, las dimensiones de alcance que revisa y si es personal. Los junta un `PermissionRegistry` inmutable, armado al arrancar el worker; falla si una concesión cita un permiso desconocido.
2. **Puestos en código.** El enum `Role` sigue siendo el catálogo de puestos; la matriz puesto→permisos vive en los providers (`permissions()` y `grants()`) y se cambia por PR. El admin asigna puestos, no edita qué hace cada uno. Una prueba congela la matriz contra un archivo de referencia.
3. **Nombramientos como única fuente.** `role_scopes` pasa a `role_assignments` (modelo `RoleAssignment`): una persona en un puesto, con su unidad, campus y carrera (nulo = sin límite) y su vigencia. Sustituyen a `model_has_roles` y, en un PR posterior, a `department_heads`.
4. **Árbol `org_units`.** Dirección → Subdirecciones → Divisiones y Departamentos, en lista de adyacencia con el mapa de descendientes cacheado. El alcance sobre una unidad cubre todo su subárbol. Los departamentos académicos comparten llave con su unidad (`departments.id` es FK a `org_units.id`), así que ninguna tabla con `department_id` cambia.
5. **Perfil activo.** La persona elige un puesto con todos sus alcances; solo valen los permisos y alcances de ese puesto. Lo personal («Mi espacio») vale en cualquier perfil. `Actor` (evolución de `ScopedContext`) responde `allows()`, `allowsAny()` y `constrain()`; `constrain()` filtra en SQL con la misma regla, de modo que PHP y SQL salen de una sola definición.
6. **El admin es un puesto.** Un nombramiento sin alcance, elegible como perfil. Activo, lo puede todo menos sus excepciones explícitas por permiso (AZ-S9).
7. **Caché por triggers y huellas.** El snapshot por usuario se guarda con la llave `authz:user:v1:{id}:{authz_version}` y el árbol con una huella de `org_units`; el snapshot vence a la siguiente medianoche de Ciudad Juárez, porque las vigencias son por día; `users.authz_version` lo sube un trigger de `role_assignments`. Nada depende de código que alguien pueda olvidar.
8. **Sale spatie/permission.** `HasRoles` sale de `User` y sus tablas se eliminan con una migración nueva. La migración histórica `2026_10_27_000006` no se edita: lee sus nombres de `config/permission.php`, que se conserva mínimo (sin el paquete) para que corra en bases nuevas.
9. **Excepción documentada a «las escrituras pasan por Actions».** Los triggers de `departments` (crean y sincronizan su unidad 1:1) y de `role_assignments` (suben `authz_version`) escriben desde la base de datos. No son lógica de proceso: mantienen una extensión 1:1 y una versión, y así ningún camino de escritura (Action, importación, seeder, factory) las olvida.

Las reglas nuevas son AZ-01 (autoaprobación marcada, confirmada) y AZ-S1 a AZ-S9 (supuestos por confirmar) en `docs/dominio/reglas-de-negocio.md`. Esta ADR reemplaza la parte de roles y alcances de [ADR-0004](0004-fortify-username-y-alcances.md) (Fortify con username sigue vigente) y retira la fila de spatie/permission de [ADR-0012](0012-dependencias-base.md).

## Consecuencias
- Ningún módulo nombra puestos en Policies, componentes, menú o Queries, solo permisos; solo los providers en `grants()` y las Actions de nombramientos usan `Role::`. Una prueba arch lo exige.
- Hasta que el último módulo migre, `ScopedContext` sigue vivo y responde solo con el perfil activo, de modo que los módulos actuales obtienen el comportamiento por perfil desde el primer día. Después se borra y la prueba arch lo prohíbe.
- La fase 3 escribe sus Policies directamente con permisos; los módulos existentes migran en PRs encadenados, uno por módulo (tareas 3.P1 a 3.P9).
- Un permiso que omita una dimensión amplía el alcance; se mitiga con una prueba unitaria por módulo (recurso con `campus_id` ⇒ dimensión campus), la matriz congelada y una prueba negativa por otro alcance en cada Policy.
- Agregar una dimensión futura (edificio, programa de posgrado) es una columna nullable en `role_assignments`, un caso en `Dimension` y su comparación; no toca a los módulos que no la usan.
- El organigrama sembrado sale del directorio oficial del ITCJ y queda marcado «por confirmar» (AZ-S1); el admin lo corrige en pantalla.

## Alternativas descartadas
- **Motor externo de políticas (Cerbos, OPA, Casbin, OpenFGA):** sirve a sistemas de varios servicios y lenguajes. Nexus es un monolito y el solver no autoriza usuarios. Costaría una dependencia, reglas fuera de TDD y una caché más difícil. La interfaz `allows()` deja abierta esa puerta.
- **Matriz editable por el admin:** la decisión del usuario es que viva en código y se revise por PR.
- **Unión de roles con filtro de vista:** no restringe lo que se puede hacer, y es justo lo que se quiere corregir.
- **Un perfil por nombramiento:** obliga a cambiar de perfil entre carreras de la misma coordinación.
- **Mantener spatie solo para la matriz:** con la matriz en código, sus tablas serían una tercera fuente de verdad.
- **Árbol con `ltree`:** el árbol es chico (decenas de nodos) y el mapa cacheado basta sin otra extensión.
