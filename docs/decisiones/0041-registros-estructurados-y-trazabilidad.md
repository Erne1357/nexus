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
- **Una línea por petición: `request.done`.** Con una lista blanca de claves: `request_id, method, route, module, livewire, status, duration_ms, db_ms, db_queries, external_ms, user_id, role, release`. No se escribe para `/health` ni `/up`. Nivel `info`; `warning` si tarda más de 1000 ms; `error` si responde 5xx. `duration_ms` llega hasta que la respuesta está lista: en un `StreamedResponse` (descargas) no incluye el envío del cuerpo, aunque las consultas que haga sí cuentan en `db_ms`.
- **Una línea por intento de job, con cuatro resultados.** El mensaje es `job.<outcome>`: `job.done` (`info`), `job.released` (`info`; Laravel también avisa `JobProcessed` cuando el job se liberó, p. ej. por `WithoutOverlapping`, y no llegó a correr), `job.retry` (`warning`; el intento lanzó y quedan reintentos) y `job.failed` (`error`). El nivel sale solo del resultado, no de la duración: la regla de 1000 ms es de las peticiones. Lista blanca: `request_id, job, queue, attempt, queue_wait_ms, duration_ms, db_ms, module, outcome`. El `request_id` viaja de la petición que encoló el job a través de `Context`. `queue_wait_ms` tiene resolución de 1 segundo (así lo guarda Laravel). El `module` del job sale del namespace de su clase (`moduleOf`: cualquier clase bajo `AppModules<Modulo>…`; otra clase es `core`). `queue_wait_ms` incluye el retraso o backoff de los reintentos.
- **Los jobs de la conexión `sync` no escriben línea propia.** Corren dentro de quien los llamó (una petición o un job de cola; en producción varias notificaciones mandan su canal `database` por `sync`), así que su trabajo ya cuenta en la línea de ese llamador. Con línea propia, el job reiniciaría el acumulador de la petición y le cambiaría el módulo, y `request.done` saldría con tiempos y módulo truncados.
- **`module`.** Sale del módulo de la ruta o, en Livewire, del componente: `hydrate` etiqueta la petición Livewire con el componente (sin pisar un `.método` ya puesto) y `call` agrega `.método`, solo si parece un identificador (el nombre es entrada del cliente). `TagModule` va antes del grupo `web` en la ruta del módulo: así también lo llevan las respuestas que ese grupo o `auth` cortan antes (redirección de invitado, 419, 404).
- **Los tiempos salen de la petición en curso.** `OperationMetrics` se resuelve del contenedor de la petición actual, no de uno guardado: con Octane los workers viven muchas peticiones y un acumulador compartido mezclaría los números de una con otra.
- **Métricas de Caddy, internas.** `metrics` global y un bloque `:9180 { metrics }` que no se publica; la API admin (`:2019`) nunca sale del contenedor. El stack de observabilidad las lee dentro de la red de Docker.
- **Sin datos personales.** El registro de accesos de Caddy quita la query string completa y las cabeceras `Referer` y `Location` (llevan tokens y números de control). Las listas blancas solo admiten identificadores: `user_id` y `role`, nunca nombre, correo ni parámetros.

## Consecuencias
- Seguir una petición es buscar su `request_id` en los logs (ver [Operación cotidiana](../operacion/despliegue.md#operación-cotidiana)).
- Los nombres de las claves de las listas blancas son un contrato: los tableros y alertas del stack aparte los consultarán. Renombrar o quitar una clave los rompe; agregar una nueva es seguro.
- Cada petición suma una línea de log; `/health` queda fuera para no inundarlo.
- `queue_wait_ms` no sirve para esperas menores a un segundo.
- Docker rota 5 archivos de 10 MB por servicio: si el lector de logs se cae más de un par de horas en pico, se pierden líneas.
- Lo que no sea JSON (arranque de PHP, FrankenPHP) sigue saliendo como texto y el lector debe tolerarlo.

## Alternativas descartadas
- **Un paquete de logging o de trazas** (middleware de terceros, contexto propio): Laravel ya trae `Context`, y Monolog ya trae el formateador JSON; no hace falta otra dependencia.
- **`/metrics` en Laravel:** con Octane y varios workers habría que agregar contadores entre procesos y publicar otro puerto de la app. Caddy ya mide las peticiones en el borde y los tiempos finos viajan en `request.done`.
- **Trazas distribuidas (spans) ahora:** vienen después, tras un spike que mida su costo. El `request_id` ya permite unir petición, job y Caddy.
- **Generar el id en la aplicación:** Caddy lo ve primero y es quien escribe la línea de acceso; generarlo ahí evita que cliente y aplicación lo dicten.
