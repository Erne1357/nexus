<?php

namespace App\Modules\AcademicLoad\Exports\Sii;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Exports\PublishedExport;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * El exporte al SII de un campus y un periodo (tarea 4.6): un zip con `grupos.csv` y `horarios.csv` en la forma de las
 * tablas del SII (decisión del usuario del 10 de noviembre de 2026), armado por SiiLoadExport con la última versión
 * publicada de cada carga. Las versiones se fijan al pedirlo, así que la llave (periodo, campus y hash de los ids de
 * snapshot) nombra exactamente lo que el archivo trae; publicar otra versión cambia la llave y pide un archivo nuevo.
 *
 * Lo descarga quien tiene `academic_load.load.export_sii` en el campus: admin y Subdirección (supuesto por confirmar).
 * Se serializa con ids y códigos, nunca con el payload.
 */
final class SiiExport implements PublishedExport
{
    /**
     * @param  list<string>  $snapshotIds
     */
    private function __construct(
        public readonly string $campusId,
        public readonly string $termCode,
        public readonly string $campusCode,
        public readonly array $snapshotIds,
    ) {}

    public static function for(Term $term, Campus $campus): self
    {
        $ids = app(SiiLoadExport::class)->latestSnapshots($term, $campus)->modelKeys();
        sort($ids);

        return new self($campus->getKey(), $term->code, $campus->code, array_values($ids));
    }

    public function key(): string
    {
        return "sii_{$this->termCode}_{$this->campusCode}_".substr(hash('sha256', implode(',', $this->snapshotIds)), 0, 16);
    }

    public function filename(): string
    {
        return "SII {$this->termCode} {$this->campusCode}.zip";
    }

    public function mimeType(): string
    {
        return 'application/zip';
    }

    public function permission(): AcademicLoadPermission
    {
        return AcademicLoadPermission::LoadExportSii;
    }

    public function scope(): Target
    {
        return Target::of(campus: $this->campusId);
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
