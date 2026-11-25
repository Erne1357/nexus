# ADR-0034 — GD para normalizar las fotos

**Estado:** Aceptada (decisión del usuario, 24 de noviembre de 2026) · **Fecha:** 2026-11-24 · Tarea 1.35 ([#843](https://github.com/Erne1357/nexus/issues/843)) · Rama padre `riel/comunicacion`

## Contexto
Comunicación y Difusión sube las fotos del personal (CO-03) y, en la tarea 1.37, las de los alumnos. Las que llegan de una cámara o un teléfono vienen en cualquier tamaño y proporción, y se ven en tres lugares con un hueco 3:4 (la credencial, la ficha y la pantalla de acceso). Hace falta recortarlas y reducirlas en el servidor, para no mandar al navegador imágenes de varios megabytes y para que todas se vean igual.

La imagen de Docker no traía ninguna extensión de imágenes de PHP.

## Decisión
- Se agrega la extensión `gd` a `install-php-extensions` del `Dockerfile` (base de dev, CI y producción).
- `PhotoNormalizer` (Core) normaliza con GD: revisa el tipo real con `finfo` (jpg, png o webp, hasta 10 MB), recorta 3:4 centrado a lo ancho y pegado arriba, y produce un JPEG de 600×800 y una miniatura de 150×200 a calidad 85. Lo transparente se aplana sobre blanco.
- Con la extensión `exif` (también en el `Dockerfile`) se lee la etiqueta de orientación de un JPEG y la foto se endereza antes de recortar, para que las de teléfono salgan derechas.
- Antes de decodificar se leen las dimensiones: más de 8000 px por lado o 40 millones de píxeles se rechaza (una bomba de descompresión no debe agotar la memoria).
- El original también se guarda: normalizar no destruye nada.
- Sin dependencias de Composer: GD es una extensión de PHP, no un paquete.

## Consecuencias
- Hay que reconstruir las imágenes `app`, `queue` y `vite` (y la de producción lo hace el release) para tener GD. En un contenedor que ya corre, `docker-php-ext-install gd` lo agrega solo hasta que se recrea.
- GD lee webp si PHP se compiló con soporte; la imagen de FrankenPHP lo trae. Un avif o un gif se rechazan: solo jpg, png y webp.
- Sin la extensión `exif` la foto se procesa sin enderezar.

## Alternativas descartadas
- **Imagick:** más formatos y corrección de EXIF, pero exige ImageMagick en la imagen y su política de seguridad; GD basta para jpg, png y webp.
- **`intervention/image` o similar:** una dependencia más (CLAUDE.md, regla dura 6) para lo que son cuarenta líneas de GD.
- **Recortar en el navegador:** no se puede confiar en lo que llega, y el lote en ZIP no pasa por el navegador.
