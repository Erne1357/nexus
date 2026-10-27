# Fase 0 — Fundación

**Entrega:** R1 · **Ventana:** 27 oct – 2 nov 2026 · **Modo:** individual (1 persona) · **Estado:** 🟨 · **Milestone:** `Fase 0 — Fundación`

## Objetivo

Dejar una base sobre la que tres personas puedan trabajar en paralelo desde el 3 de noviembre sin pisarse: estructura de módulos, autenticación, roles y alcances, layout, pruebas en tres niveles, CI bloqueante y un módulo de referencia que sirva de plantilla para todos los demás.

## Dependencias

- Stack Docker ya existente (`compose.yaml`, `Dockerfile`).
- Decisiones de `PROJECT.md` (monolito modular, Pest 5, MaryUI, Fortify con username).

## Alcance

### Incluye

- Dependencias y configuración base del proyecto.
- Módulo `Core` completo para R1: usuarios, roles, alcances, campus y modalidad, periodos (solo modelo; las pantallas de periodo van en fase 2), parámetros, bitácora.
- Módulo de referencia (`Core` → Campus) con página MFC, Action, Query, Policy y pruebas unit, feature y browser.
- Pruebas de arquitectura.
- CI en GitHub Actions y protección de `develop`.
- Skill de proyecto `nexus-module` para crear módulos con la convención.

### No incluye

- Pantallas de catálogos (fase 1), periodos y validación (fase 2).
- Diseño visual definitivo (solo tema inicial de daisyUI).

## Tareas

| Id | Tarea | Módulo | Dónde y por qué | Pruebas | Persona |
|---|---|---|---|---|---|
| 0.1 ✅ [#72](https://github.com/Erne1357/nexus/pull/72) | Dependencias: Pest 5 con plugins de Laravel, Livewire y browser; MaryUI; Fortify; spatie/laravel-permission; spatie/laravel-activitylog; Laravel Boost (dev); openspout para leer y escribir Excel/CSV | — | `composer.json`, `package.json`. Se quitan los tests de ejemplo de PHPUnit y se migra `phpunit.xml` a Pest. `APP_LOCALE=es`, zona horaria `America/Ciudad_Juarez` en `config/app.php`. | `php artisan test` corre vacío en verde | A |
| 0.2 ✅ [#73](https://github.com/Erne1357/nexus/pull/73) | Imagen `dev` con Node y dependencias de Chromium para que los browser tests de Pest corran dentro del contenedor `app` | — | `Dockerfile` etapa `dev`. Los browser tests usan Playwright; sin esto no corren ni en local ni en CI. | Un browser test de humo abre `/login` | A |
| 0.3 ✅ [#74](https://github.com/Erne1357/nexus/pull/74) | Base de datos de pruebas `nexus_test` en PostgreSQL y `phpunit.xml` apuntando a ella | — | Script de inicialización en `docker/postgres/` que crea la segunda base. Probar contra PostgreSQL evita diferencias de dialecto con SQLite (jsonb, exclusión de rangos). | Suite usa `RefreshDatabase` contra `nexus_test` | A |
| 0.4 ✅ [#76](https://github.com/Erne1357/nexus/pull/76) | Estructura de módulos: carpeta `app/Modules`, clase base `ModuleServiceProvider` que registra rutas, migraciones, vistas, namespace Livewire y policies por convención; registro de módulos en `bootstrap/providers.php` | Core | Es la convención de `docs/arquitectura/arquitectura.md`. Una sola clase base evita que cada módulo repita el registro. | Test de arquitectura: todo módulo tiene provider; dependencias solo hacia la izquierda del mapa | A |
| 0.5 ✅ [#80](https://github.com/Erne1357/nexus/pull/80) | `Core`: migraciones de `users` (username único, `must_change_password`, `is_active`), `role_scopes`, `campuses`, `terms`, `term_stages`, `settings`; instalación de permission y activitylog | Core | Modelo de datos de `docs/dominio/modelo-de-datos.md`. Los campus se siembran con su perfil de flujo (C1, C2 presencial; EAD mixta; ENL en línea; TNMV virtual). | Feature: seeders crean roles, campus y admin; unit: perfil de campus expone `uses_rooms`, etc. | A |
| 0.6 ✅ [#82](https://github.com/Erne1357/nexus/pull/82) | Fortify con login por **username**, sin registro público, cambio obligatorio de contraseña en primer acceso, límite de intentos | Core | `FortifyServiceProvider` con `authenticateUsing` por username. Los usuarios se crean desde importación (fase 1) o por un admin. | Feature: login correcto e incorrecto, usuario inactivo no entra, primer acceso redirige a cambio de contraseña; browser: flujo de login | A |
| 0.7 ✅ [#81](https://github.com/Erne1357/nexus/pull/81) | Roles, permisos y alcances: seeder de roles, modelo `RoleScope`, `ScopedContext` (campus y periodo activos del usuario, alcances por rol) y helper para policies | Core | Toda policy de los demás módulos usa `ScopedContext`; centralizarlo evita duplicar la lógica de alcance. | Unit: `ScopedContext` responde correctamente para cada rol; feature: policy de ejemplo | A |
| 0.8 ✅ [#83](https://github.com/Erne1357/nexus/pull/83) | Layout MaryUI: sidebar por rol, topbar con selector de campus y periodo, toasts, página de inicio por rol, tema daisyUI inicial (claro y oscuro) | Core | `resources/views/layouts/app.blade.php` y componentes en `Core/Livewire/components`. Es lo que verán todas las pantallas; se define una vez. | Browser: navegación muestra solo lo permitido por rol | A |
| 0.9 ✅ [#84](https://github.com/Erne1357/nexus/pull/84) | Módulo de referencia: CRUD de Campus como página MFC con tabla, formulario en modal, `CreateCampus`/`UpdateCampus` Actions, `CampusQuery`, `CampusPolicy`, factory y seeder | Core | Es la plantilla que copiarán los demás módulos. Debe mostrar cómo se escribe cada tipo de clase y cómo se prueba. | Unit (Action con reglas), feature (componente Livewire), browser (crear y editar campus) | A |
| 0.10 | Skill `.claude/skills/nexus-module/SKILL.md` que explique cómo crear un módulo nuevo copiando la convención del módulo de referencia | — | Para que la IA genere módulos consistentes en fase 1 sin releer toda la arquitectura. | Revisión manual | A |
| 0.11 ✅ [#77](https://github.com/Erne1357/nexus/pull/77) | CI: workflow de GitHub Actions que construye la imagen `dev`, levanta PostgreSQL y Valkey, corre Pint en modo verificación, pruebas unit y feature, y browser tests en un job aparte; se ejecuta en PR a `develop` y `main` | — | `.github/workflows/ci.yml`. Sin CI bloqueante no hay forma de confiar en código generado con IA. | El workflow pasa en un PR de prueba | A |
| 0.12 ✅ (protección vía `gh api`; bloqueo verificado en [#80](https://github.com/Erne1357/nexus/pull/80)) | Marcar el check de CI como requerido en la protección de `develop` y `main` | — | Configuración del repositorio (`gh api`). La protección de `develop` ya exige PR; falta el check. | PR sin CI verde no se puede mezclar | A |
| 0.13 ✅ [#75](https://github.com/Erne1357/nexus/pull/75) | Laravel Boost instalado en el contenedor y MCP configurado con `docker compose exec` (ver `docs/herramientas/ia-tooling.md`) | — | Da a la IA documentación exacta de Laravel 13, Livewire 4 y Pest 5 y acceso a tinker, esquema y logs. | Boost responde desde Claude Code | A |
| 0.14 ✅ [#79](https://github.com/Erne1357/nexus/pull/79) | Definir servidor de despliegue (on-premise del ITCJ o VPS) y preparar `compose.yaml` de producción con secretos, respaldos de PostgreSQL (`pg_dump` diario) y HTTPS | — | Decisión pendiente en `PROJECT.md`. Debe existir antes del piloto (fase 4). Se registra como ADR. | Despliegue de la rama `develop` en el servidor de pruebas | A |

## Pruebas de la fase

- **Unit:** `ScopedContext`, perfil de campus, Actions del módulo de referencia, reglas de arquitectura (`arch()`).
- **Feature:** login, políticas, componente Livewire de Campus, seeders.
- **Browser:** login, navegación por rol, CRUD de Campus.
- Cobertura mínima de `app/Modules/Core` reportada en CI (meta 80 % de líneas; no bloqueante en fase 0, bloqueante desde fase 1).

## Criterios de aceptación

1. `docker compose up -d --build` y `docker compose exec app php artisan test` verdes desde cero en una máquina limpia siguiendo el README.
2. Un usuario `admin` entra con username, cambia contraseña y ve el CRUD de Campus; un usuario `coordinador` no ve esa opción.
3. CI verde en un PR a `develop`, y el check es obligatorio.
4. `docs/arquitectura/arquitectura.md` y el skill `nexus-module` coinciden con el código del módulo de referencia.
5. README actualizado con los comandos de pruebas y de Boost.

## Riesgos y notas

- Los browser tests de Pest 5 dentro de Docker pueden necesitar librerías del sistema para Chromium; reservar medio día.
- Octane: cualquier singleton con estado (por ejemplo `ScopedContext`) debe reiniciarse por petición; usar `scoped` en el contenedor, no `singleton`.
- La protección de `develop` ya está activa (PR obligatorio). No se puede subir directo.
