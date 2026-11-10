<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\AcademicLoad\Queries\PublishedSession;
use App\Modules\AcademicLoad\Queries\PublishedWeek;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Support\WeekTime;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Lo común de los PDF públicos de lo publicado (tarea 4.5; ADR-0021): uno por carrera y semestre, docente o salón,
 * descargable sin sesión desde la consulta. Guarda solo ids, claves y los ids de las versiones publicadas que lee
 * (PublishedScheduleQuery::snapshotIds()): la cola lo serializa ligero, su llave cambia con cada versión nueva y el
 * archivo se arma con esas versiones exactas aunque otra se publique mientras espera en la cola.
 * Arma el HTML con una vista Blade de `academic-load::pdf` y lo pasa por DomPDF en carta, sin acceso remoto y con
 * DejaVu Sans.
 */
abstract class PublishedPdf implements PublishedExport
{
    /** Los tokens `chain-N` del tema claro de DESIGN.md: el PDF no carga `app.css`. */
    public const array CHAINS = ['#6d4fc2', '#0f7c8c', '#b0457f', '#c2661a', '#3b56b8', '#6b7a1f', '#1f86c7', '#8a5a3c', '#4d6b7a', '#9c4fd1', '#2f8f5b', '#c24f9b'];

    public function __construct(
        public readonly string $termId,
        public readonly string $campusId,
        public readonly string $termCode,
        public readonly string $campusCode,
        /** @var list<string> */
        public readonly array $snapshotIds,
    ) {}

    /**
     * Lo que distingue a este PDF de otro del mismo tipo (carrera, retícula y semestre; docente; salón).
     *
     * @return list<string|int>
     */
    abstract protected function subject(): array;

    abstract protected function kind(): string;

    /**
     * La vista y sus datos, con lo publicado ya leído.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: string} vista, datos y orientación
     */
    abstract protected function document(PublishedSchedule $schedule, Term $term, Campus $campus): array;

    public function key(): string
    {
        return $this->kind().'-'.hash('xxh128', implode('|', [$this->termId, $this->campusId, ...$this->subject(), ...$this->snapshotIds]));
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function permission(): ?AcademicLoadPermission
    {
        return null;
    }

    public function scope(): Target
    {
        return Target::of(campus: $this->campusId);
    }

    public function build(): string
    {
        $term = Term::query()->findOrFail($this->termId);
        $campus = Campus::query()->findOrFail($this->campusId);
        [$view, $data, $orientation] = $this->document(app(PublishedScheduleQuery::class)->scheduleOf($term, $campus, $this->snapshotIds), $term, $campus);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view($view, [...$data, 'term' => $term, 'campus' => $campus])->render(), 'UTF-8');
        $pdf->setPaper('letter', $orientation);
        $pdf->render();

        return (string) $pdf->output();
    }

    /**
     * «12 de diciembre de 2026» en la zona de la aplicación, como las páginas (PublishedWeek::updatedOn()); vacío sin
     * grupos.
     */
    protected static function updated(?CarbonImmutable $at): string
    {
        return PublishedWeek::updatedOn($at);
    }

    /**
     * El color de cada materia de estos grupos, por orden de clave, como en las páginas (PublishedWeek::chains()).
     *
     * @param  list<PublishedGroup>  $groups
     * @return array<string, string> clave de materia → color
     */
    protected static function colors(array $groups): array
    {
        return array_map(fn (int $index): string => self::CHAINS[$index % count(self::CHAINS)], PublishedWeek::chains($groups));
    }

    /**
     * Lo que se lee de una sesión: sus horas, dónde (salón, «Virtual» o «Por asignar»; el público no ve por qué falta)
     * y, si es práctica, su marca (la virtual ya lo dice en el lugar).
     *
     * @return array{hours: string, place: string, kind: ?string}
     */
    protected static function session(PublishedSession $session): array
    {
        return [
            'hours' => WeekTime::clock($session->startsAt).'–'.WeekTime::clock($session->endsAt),
            'place' => $session->kind === 'virtual' ? 'Virtual' : ($session->room['code'] ?? 'Por asignar'),
            'kind' => $session->kind === 'practica' ? $session->kindLabel : null,
        ];
    }

    protected static function teacherOf(PublishedGroup $group): string
    {
        return $group->teacher['name'] ?? 'Por asignar';
    }

    /**
     * Para un nombre de archivo: sin las barras ni los caracteres que los sistemas de archivos no aceptan.
     */
    protected static function clean(string $value): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $value) ?? $value);
    }
}
