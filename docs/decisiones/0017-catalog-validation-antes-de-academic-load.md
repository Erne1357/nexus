# ADR-0017 — CatalogValidation a la izquierda de AcademicLoad

**Estado:** Aceptada · **Fecha:** 2026-11-01

## Contexto
En el mapa de dependencias (`docs/dominio/modelo-de-datos.md`), CatalogValidation y AcademicLoad estaban en ramas separadas: ninguno podía leer al otro. Pero la carga académica necesita saber cómo van las revisiones de catálogo:
- HC-14: una carga (periodo, campus, departamento) solo sale de `draft` cuando están `validated` las 4 revisiones generales del departamento en el periodo y la de salones de ese campus (tarea 2.3).
- Los avisos de las tareas 2.7 y 2.8 muestran, desde la carga, qué catálogos faltan por validar.

CatalogValidation, en cambio, no necesita nada de AcademicLoad: una revisión no depende del estado de ninguna carga.

## Decisión
Se agrega la flecha `CatalogValidation --> AcademicLoad`: AcademicLoad depende de CatalogValidation y puede leer sus modelos y Queries. El diagrama y `$leftOf` de `tests/Arch/ModulesTest.php` cambian en el mismo PR (tarea 2.2), como pide la prueba que los compara.

## Consecuencias
- AcademicLoad lee `catalog_reviews` directamente, sin eventos ni copias del estado.
- CatalogValidation sigue sin poder importar AcademicLoad; si algún día necesitara reaccionar a una carga, lo haría escuchando un evento.
- AcademicLoad también hereda lo que está a la izquierda de CatalogValidation (Imports y lo que Imports ve), que ya veía casi todo por Academic y Demand.

## Alternativas descartadas
- **Copiar el estado de las revisiones en la carga** (una columna o un evento que la actualice): dos fuentes de verdad que se pueden desalinear.
- **Un evento con veto para que CatalogValidation bloquee la transición**: funciona, pero esconde HC-14 en un listener de otro módulo, cuando la regla es de la carga y la carga la debe poder citar y probar.
