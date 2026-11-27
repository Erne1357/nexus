<?php

namespace App\Modules\AcademicLoad\Exports\Sii;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Exports\StaffExport;
use App\Modules\AcademicLoad\Exports\StaffExports;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * El exporte al SII de una modalidad y un periodo (tarea 4.6; M1, con los grupos de todos sus campus): un zip con `grupos.csv` y `horarios.csv` en la forma de las
 * tablas del SII (decisión del usuario del 10 de noviembre de 2026), armado por SiiLoadExport con la última versión
 * publicada de cada carga. Las versiones se fijan al pedirlo, así que la llave (periodo, modalidad y hash de los ids de
 * snapshot) nombra exactamente lo que el archivo trae; publicar otra versión cambia la llave y pide un archivo nuevo.
 *
 * Lo descarga quien tiene `academic_load.load.export_sii`: el jefe del Departamento de Servicios Escolares, que captura
 * la carga en el SII, la Subdirección Académica y el admin (supuesto por confirmar). Se ofrece en «Exportes del
 * personal» de la consulta publicada (`StaffExports`, tipo `sii`).
 * Se serializa con ids y códigos, nunca con el payload.
 */
final class SiiFilesExport implements StaffExport
{
    /**
     * @param  list<string>  $snapshotIds
     * @param  list<string>  $departmentIds  los departamentos que publicaron, para el permiso
     */
    public function __construct(
        public readonly string $modalityId,
        public readonly string $termCode,
        public readonly string $modalityCode,
        public readonly array $snapshotIds,
        public readonly array $departmentIds,
    ) {}

    /**
     * Las versiones vigentes de la modalidad y periodo; null si no hay nada publicado (404 sin encolar).
     */
    public static function fromRequest(PublishedScheduleQuery $query, Term $term, Modality $modality, Request $request): ?static
    {
        $ids = $query->snapshotIds($term, $modality);

        return $ids === [] ? null : new self($modality->getKey(), $term->code, $modality->code, $ids, array_keys($query->departmentsOf($ids)));
    }

    /**
     * El exporte de las versiones vigentes de la modalidad y periodo; null si no hay nada publicado.
     */
    public static function for(Term $term, Modality $modality): ?self
    {
        return self::fromRequest(app(PublishedScheduleQuery::class), $term, $modality, request());
    }

    public static function label(): string
    {
        return 'Archivos para el SII';
    }

    public function key(): string
    {
        return "sii_{$this->termCode}_{$this->modalityCode}_".substr(hash('sha256', implode(',', $this->snapshotIds)), 0, 16);
    }

    public function filename(): string
    {
        return "SII {$this->termCode} {$this->modalityCode}.zip";
    }

    public function mimeType(): string
    {
        return 'application/zip';
    }

    public static function requiredPermission(): AcademicLoadPermission
    {
        return AcademicLoadPermission::LoadExportSii;
    }

    public function permission(): AcademicLoadPermission
    {
        return self::requiredPermission();
    }

    /**
     * Como los demás exportes del personal: la modalidad y los departamentos que publicaron más Servicios Escolares. El
     * permiso solo aplica a Servicios Escolares y a la Subdirección Académica, así que no lo tiene el jefe de un
     * departamento académico.
     */
    public function scope(): Target
    {
        return Target::of(modality: $this->modalityId, unit: StaffExports::units($this->departmentIds));
    }

    /**
     * @throws RuntimeException si no se puede armar el zip
     */
    public function build(): string
    {
        $snapshots = LoadSnapshot::query()->whereIn('id', $this->snapshotIds)->orderBy('id')->get();
        $files = app(SiiLoadExport::class)->filesOf($this->termCode, $snapshots);

        File::ensureDirectoryExists($directory = storage_path('app/tmp'));
        $path = $directory.'/'.Str::uuid7()->toString().'.zip';
        $zip = new ZipArchive;

        try {
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo crear el zip del exporte al SII.');
            }

            foreach ($files as $name => $bytes) {
                $zip->addFromString($name, $bytes);
            }

            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            File::delete($path);
        }
    }
}
