# Registro de decisiones

Decisiones de arquitectura y de alcance con su contexto, para que nadie las reabra sin saber por qué se tomaron. Una decisión nueva = un archivo nuevo con el siguiente número; una decisión revertida se marca "Reemplazada por ADR-XXXX", no se borra.

| ADR | Decisión | Estado | Fecha |
|---|---|---|---|
| [0001](0001-monolito-modular.md) | Monolito modular sin hexagonal ni DDD | Aceptada | 2026-10-26 |
| [0002](0002-pest-4-y-postgres-de-pruebas.md) | Pest en tres niveles y PostgreSQL para pruebas | Aceptada; versión de Pest reemplazada por [0011](0011-pest-5.md) | 2026-10-26 |
| [0003](0003-livewire-maryui.md) | Livewire 4 MFC con MaryUI y daisyUI | Aceptada | 2026-10-26 |
| [0004](0004-fortify-username-y-alcances.md) | Fortify con username; roles de spatie más alcances propios | Aceptada | 2026-10-26 |
| [0005](0005-importacion-por-archivos.md) | Carga inicial por importación de archivos del SII | Aceptada | 2026-10-26 |
| [0006](0006-cupos-por-carrera.md) | Cupos por carrera en lugar de paralelos | Aceptada | 2026-10-26 |
| [0007](0007-motor-en-dos-pasos.md) | Motor de horarios: heurística PHP y luego OR-Tools CP-SAT en Python | Aceptada | 2026-10-26 |
| [0008](0008-cierre-por-departamento.md) | Unidad de cierre = departamento en un campus; visto bueno de Subdirección configurable | Aceptada | 2026-10-26 |
| [0009](0009-modalidad-como-perfil-de-campus.md) | Modalidad como perfil de flujo del campus | Aceptada | 2026-10-26 |
| [0010](0010-alcance-y-fecha-de-r1.md) | R1 sin motor de sugerencia, fecha 4 de diciembre de 2026 | Aceptada | 2026-10-26 |
| [0011](0011-pest-5.md) | Pest 5 (PHPUnit 13) para todas las pruebas; reemplaza la versión de Pest de 0002 | Aceptada | 2026-10-27 |
| [0012](0012-dependencias-base.md) | Dependencias base de la fundación: activitylog, openspout y Boost | Aceptada | 2026-10-27 |
| [0013](0013-despliegue-on-premise-itcj.md) | Despliegue on-premise en el ITCJ: HTTPS con Caddy, `.env` con permisos restringidos, `pg_dump` diario con retención | Aceptada; puntos por confirmar con el ITCJ | 2026-10-27 |
| [0014](0014-uuidv7-como-llave-primaria.md) | UUIDv7 como llave primaria en todo modelo de Nexus; se editaron una sola vez las migraciones de la fase 0 | Aceptada | 2026-10-27 |
| [0015](0015-identidad-visual.md) | Identidad visual: credencial con áreas, menú que se transforma, paleta institucional, Atkinson Hyperlegible y la liebre como marca | Aceptada | 2026-10-29 |

Plantilla: Contexto · Decisión · Consecuencias · Alternativas descartadas.
