# ADR-0041 — Registros estructurados y trazabilidad de peticiones

**Estado:** Aceptada (decisión del usuario, 27 de noviembre de 2026) · **Fecha:** 2026-11-27 · Rama `riel/observabilidad`

## Contexto
Laravel escribía a `stderr` en texto libre y Caddy tenía su propio registro de accesos. No había forma de seguir una petición de punta a punta: ni de unir la línea de Caddy con lo que hizo la aplicación, ni de saber qué petición encoló un job que luego falló, ni de ver cuánto de una respuesta lenta fue base de datos o llamadas externas. Además un stack de observabilidad aparte lee los logs por el socket de Docker y necesita líneas que pueda interpretar sin adivinar el formato.

## Decisión
- **JSON por línea.** En producción `compose.prod.yaml` fija `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`. El contexto compartido (`Context`) aparece en `extra`. Ejemplo real (la ruta de la traza se acorta):

  ```json
  {"message":"prueba","context":{"exception":{"class":"RuntimeException","message":"prueba","code":0,"file":"/app/vendor/psy/psysh/src/ExecutionClosure.php(41) : eval()'d code:2"}},"level":400,"level_name":"ERROR","channel":"local","datetime":"2026-11-28T02:07:03.693125-07:00","extra":{"request_id":"r-1"}}
  ```

- **`X-Request-Id` nace en Caddy.** Caddy hace `request_header X-Request-Id {http.request.uuid}`: reemplaza siempre la cabecera del cliente, y agrega `request_id` a su propio registro de accesos. Así el id de Caddy y el de la aplicación son el mismo. Como respaldo (acceso sin Caddy, pruebas) `ObserveRequest` acepta el id solo si cumple `^[A-Za-z0-9-]{8,64}$`; si no, genera un UUIDv7. Siempre lo devuelve en la cabecera de respuesta `X-Request-Id` y lo guarda en `Context`.
- **Una línea por petición: `request.done`.** Con una lista blanca de claves: `request_id, method, route, module, livewire, status, duration_ms, db_ms, db_queries, external_ms, user_id, role, release`. No se escribe para `/health` ni `/up`. Nivel `info`; `warning` si tarda más de 1000 ms; `error` si responde 5xx.
- **Una línea por job: `job.done` / `job.failed`.** Lista blanca: `request_id, job, queue, attempt, queue_wait_ms, duration_ms, db_ms, module, outcome`. El `request_id` viaja de la petición que encoló el job a través de `Context`. `queue_wait_ms` tiene resolución de 1 segundo (así lo guarda Laravel). `job.failed` va en `error`.
- **`module`.** Sale del módulo de la ruta o, en Livewire, del componente: `hydrate` etiqueta toda petición Livewire y `call` agrega `.método`.
- **Los tiempos salen de la petición en curso.** `OperationMetrics` se resuelve del contenedor de la petición actual, no de uno guardado: con Octane los workers viven muchas peticiones y un acumulador compartido mezclaría los números de una con otra.
- **Métricas de Caddy, internas.** `metrics` global y un bloque `:9180 { metrics }` que no se publica; la API admin (`:2019`) nunca sale del contenedor. El stack de observabilidad las lee dentro de la red de Docker.
- **Sin datos personales.** El registro de accesos de Caddy quita la query string completa y las cabeceras `Referer` y `Location` (llevan tokens y números de control). Las listas blancas solo admiten identificadores: `user_id` y `role`, nunca nombre, correo ni parámetros.

## Consecuencias
- Seguir una petición es buscar su `request_id` en los logs (ver [Operación cotidiana](../operacion/despliegue.md#operación-cotidiana)).
- Los nombres de las claves de las listas blancas son un contrato: los tableros y alertas del stack aparte los consultan. Renombrar o quitar una clave los rompe; agregar una nueva es seguro.
- Cada petición suma una línea de log; `/health` queda fuera para no inundarlo.
- `queue_wait_ms` no sirve para esperas menores a un segundo.
- Lo que no sea JSON (arranque de PHP, FrankenPHP) sigue saliendo como texto y el lector debe tolerarlo.

## Alternativas descartadas
- **Un paquete de logging o de trazas** (middleware de terceros, contexto propio): Laravel ya trae `Context`, y Monolog ya trae el formateador JSON; no hace falta otra dependencia.
- **`/metrics` en Laravel:** con Octane y varios workers habría que agregar contadores entre procesos y publicar otro puerto de la app. Caddy ya mide las peticiones en el borde y los tiempos finos viajan en `request.done`.
- **Trazas distribuidas (spans) ahora:** vienen después, tras un spike que mida su costo. El `request_id` ya permite unir petición, job y Caddy.
- **Generar el id en la aplicación:** Caddy lo ve primero y es quien escribe la línea de acceso; generarlo ahí evita que cliente y aplicación lo dicten.
