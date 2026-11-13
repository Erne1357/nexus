<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Target;

/**
 * Un archivo que sale de lo publicado (tareas 4.5 y 4.6): los PDF por carrera y semestre, por docente, por salón y por
 * departamento, el Excel de la modalidad y los archivos para el SII. Lo arma la cola (GeneratePublishedExport) y lo entrega
 * PublishedExports. Es determinista: lee solo versiones de snapshot, que no cambian (HC-18), así que su `key()` lleva
 * los ids de las versiones que lee y el mismo archivo sirve hasta que se publica otra.
 *
 * La cola lo serializa: guarda solo ids, claves y códigos, nunca el payload ni modelos completos.
 */
interface PublishedExport
{
    /**
     * Estable y única por contenido: tipo, periodo, modalidad (y el campus del filtro), sujeto (carrera y semestre, docente, salón o departamento)
     * y un hash de los ids de snapshot que lee. Solo letras, dígitos, guion y guion bajo: nombra el archivo.
     */
    public function key(): string;

    /**
     * El nombre con que se descarga: «Horario ISC semestre 3 - 20271 C2.pdf».
     */
    public function filename(): string;

    public function mimeType(): string;

    /**
     * El permiso que pide la descarga, o null si es pública (los PDF por carrera y semestre, por docente y por salón;
     * decisión del usuario del 10 de noviembre de 2026).
     */
    public function permission(): ?AcademicLoadPermission;

    /**
     * La modalidad y, si aplica, la unidad sobre los que se pregunta el permiso.
     */
    public function scope(): Target;

    /**
     * Los bytes del archivo. Corre en la cola.
     */
    public function build(): string;
}
