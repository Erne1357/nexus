# ADR-0012 — Dependencias base de la fundación

**Estado:** Aceptada · **Fecha:** 2026-10-27

## Contexto
La tarea 0.1 instala las dependencias de la fundación. La regla del proyecto es que no se agrega una dependencia sin una nota en `docs/decisiones/`. Esta nota junta en un solo lugar el conjunto base y su respaldo; solo algunos paquetes no tenían ADR propio.

## Decisión
| Paquete | Para qué | Respaldo |
|---|---|---|
| `robsontenorio/mary` ^2.9 y `daisyui` ^5 (npm) | Componentes de UI sobre Tailwind 4 | [ADR-0003](0003-livewire-maryui.md) |
| `laravel/fortify` ^1.41 | Login por username, cambio de contraseña, sin registro público | [ADR-0004](0004-fortify-username-y-alcances.md) |
| `spatie/laravel-permission` ^8 | Roles y permisos; el alcance va en `role_scopes` | [ADR-0004](0004-fortify-username-y-alcances.md) |
| Pest 5 y sus plugins `laravel`, `livewire`, `browser` y `arch` | Pruebas unit, feature, browser y de arquitectura | [ADR-0002](0002-pest-4-y-postgres-de-pruebas.md), [ADR-0011](0011-pest-5.md) |
| `spatie/laravel-activitylog` ^5 | Bitácora inmutable de toda escritura hecha por Actions | Esta nota |
| `openspout/openspout` ^5 | Leer y escribir Excel/CSV en streaming | Esta nota; contexto en [ADR-0005](0005-importacion-por-archivos.md) |
| `laravel/boost` ^2 (solo dev) | Servidor MCP con la documentación exacta de las versiones instaladas | Esta nota |
| `symfony/yaml` ^8 (solo dev) | Leer los archivos de compose en las pruebas del despliegue de producción (`tests/Unit/Deploy`); ya llegaba transitivo por Boost, se declara para no depender de eso | [ADR-0013](0013-despliegue-on-premise-itcj.md), esta nota |
| `playwright` (npm, solo dev, versión exacta) | Navegador que controlan los browser tests; lo exige `pest-plugin-browser` | [ADR-0011](0011-pest-5.md), esta nota |

Por qué los nuevos:
- **activitylog:** CLAUDE.md exige bitácora que nunca se edita ni se borra y que toda escritura pase por una Action con bitácora; el paquete la resuelve sin tablas ni modelos propios.
- **openspout:** las importaciones del SII y los exportes pueden ser archivos grandes; leerlos y escribirlos en streaming evita cargar el archivo completo en memoria dentro de un worker de Octane o de un job.
- **Boost:** da a la IA documentación y APIs de Laravel 13, Livewire 4 y Pest 5 exactas para lo instalado, en lugar de depender de su memoria. Es solo de desarrollo y no entra a la imagen `prod`.
- **playwright:** `pest-plugin-browser` lo lanza desde `node_modules/.bin`; sin el paquete no corre ningún browser test. Se fija a versión exacta porque cada versión descarga un Chromium distinto y el de la imagen `dev` debe coincidir.

## Consecuencias
- `laravel/fortify` trae como dependencias transitivas `laravel/passkeys`, `web-auth/webauthn-lib`, `pragmarx/google2fa` y `bacon/bacon-qr-code`. No se usan en R1 (ni 2FA ni passkeys) y no se declaran aparte.
- La etapa `dev` del `Dockerfile` agrega Node (imagen `node` con glibc, misma versión que la de Vite) y el Chromium de Playwright en `/ms-playwright`. La versión del navegador se lee de `package-lock.json` al construir la imagen; no se escribe a mano en dos lugares. La imagen `prod` no cambia.
- Cualquier dependencia nueva a partir de aquí lleva su propio ADR o una fila en esta tabla en el mismo PR que la instala.
- Las versiones exactas quedan en `composer.lock` y `package-lock.json`; esta nota fija solo la versión mayor (`playwright` va con versión exacta).

## Alternativas descartadas
- `maatwebsite/excel` en lugar de openspout: carga más a memoria y agrega una capa que no hace falta para leer y escribir filas.
- Escribir la bitácora a mano con una tabla propia: más código que mantener y más riesgo de que alguna escritura omita la bitácora.
