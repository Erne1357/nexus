<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Events\Concerns\CollectsReport;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se abrió (o se completó la apertura de) un periodo en una modalidad (OpenTerm; M4). Evento con reporte: se despacha
 * dentro de la transacción de OpenTerm y sus listeners corren en ella, así que abrir es todo o nada. Los módulos de la
 * derecha crean lo suyo para el periodo y la modalidad (AcademicLoad sus cargas, MS15; CatalogValidation sus
 * revisiones; supuestos por confirmar, docs/dominio/reglas-de-negocio.md, Periodo y calendario), solo lo que falte, y
 * lo dicen con report(). No es ShouldDispatchAfterCommit: un listener que oiga después del commit no podría deshacer
 * la apertura.
 */
final class TermOpened
{
    use CollectsReport, Dispatchable;

    public function __construct(public Term $term, public Modality $modality) {}
}
