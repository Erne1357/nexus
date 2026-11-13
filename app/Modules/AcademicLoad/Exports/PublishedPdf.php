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
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Support\WeekTime;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Lo común de los PDF públicos de lo publicado (tarea 4.5; ADR-0021): uno por carrera y semestre, docente o salón,
 * descargable sin sesión desde la consulta. Lo publicado es por modalidad (M1); con el campus de la consulta (`?campus=`)
 * el PDF lleva solo los grupos de ese campus, y su nombre dice el campus en lugar de la modalidad. Guarda solo ids, claves y los ids de las versiones publicadas que lee
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
        public readonly string $modalityId,
        public readonly string $termCode,
        public readonly string $modalityCode,
        /** @var list<string> */
        public readonly array $snapshotIds,
        /** El campus del filtro (clave), o null para todos los de la modalidad. */
        public readonly ?string $campusCode = null,
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
    abstract protected function document(PublishedSchedule $schedule, Term $term, Modality $modality): array;

    public function key(): string
    {
        return $this->kind().'-'.hash('xxh128', implode('|', [$this->termId, $this->modalityId, $this->campusCode ?? '*', ...$this->subject(), ...$this->snapshotIds]));
    }

    /**
     * Dónde, para el nombre del archivo: el campus del filtro o, sin él, la clave de la modalidad.
     */
    protected function place(): string
    {
        return $this->campusCode ?? $this->modalityCode;
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
        return Target::of(modality: $this->modalityId);
    }

    public function build(): string
    {
        $term = Term::query()->findOrFail($this->termId);
        $modality = Modality::withTrashed()->findOrFail($this->modalityId);
        $campus = $this->campusCode === null ? null : Campus::withTrashed()->where('code', $this->campusCode)->first();
        $schedule = app(PublishedScheduleQuery::class)->scheduleOf($term, $modality, $this->snapshotIds)->onlyCampus($this->campusCode);
        [$view, $data, $orientation] = $this->document($schedule, $term, $modality);

        $pdf = new Dompdf(self::options());
        $pdf->loadHtml(view($view, [...$data, 'term' => $term, 'modality' => $modality, 'campus' => $campus])->render(), 'UTF-8');
        $pdf->setPaper('letter', $orientation);
        $pdf->render();

        return (string) $pdf->output();
    }

    /**
     * La configuración de DomPDF, explícita aunque los valores por omisión de la versión 3 ya sean seguros: sin acceso
     * remoto, sin PHP ni JavaScript incrustados y solo con lectura local de `resources/images` (ADR-0021).
     */
    public static function options(): Options
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([resource_path('images')]);
        $options->setDefaultFont('DejaVu Sans');

        return $options;
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
