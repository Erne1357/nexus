<?php

namespace App\Modules\Scheduling\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resuelve la clave de materia de la proyección a una materia de Nexus, sin crear nunca una (ImportLoadProjection): primero
 * `subjects.code` exacta (sin espacios y en mayúsculas); luego con el guion normalizado (CID2501 = CID-2501); luego los alias
 * del SII (`subject_aliases`) y, si la base trae `sii_archivo`, la clave TecNM de `materias_claves_tecnm`, con la regla de
 * ImportSiiGroupHistory (la menor clave TecNM, sin la que repite la del SII).
 */
final class SubjectResolver
{
    /** @var array<string, array{id: string, department_id: string}> */
    private array $subjects = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, string> */
    private array $tecnm = [];

    public function __construct()
    {
        foreach (DB::table('subjects')->whereNull('deleted_at')->get(['id', 'code', 'department_id']) as $subject) {
            $this->subjects[mb_strtoupper(preg_replace('/\s+/', '', $subject->code) ?? '')] = ['id' => $subject->id, 'department_id' => $subject->department_id];
        }

        foreach (DB::table('subject_aliases')->get(['code', 'subject_id']) as $alias) {
            $this->aliases[mb_strtoupper(preg_replace('/\s+/', '', $alias->code) ?? '')] = $alias->subject_id;
        }

        $this->loadTecnmKeys();
    }

    /**
     * @return array{id: string, department_id: string, via: string}|null
     */
    public function resolve(string $code): ?array
    {
        $clean = mb_strtoupper(preg_replace('/\s+/', '', $code) ?? '');

        foreach ([['exact', $clean], ['hyphen', $this->withHyphen($clean)], ['hyphen', str_replace('-', '', $clean)]] as [$via, $candidate]) {
            if (isset($this->subjects[$candidate])) {
                return $this->subjects[$candidate] + ['via' => $via];
            }
        }

        foreach ([$clean, $this->withHyphen($clean), str_replace('-', '', $clean)] as $candidate) {
            if (isset($this->aliases[$candidate])) {
                $subject = $this->bySubjectId($this->aliases[$candidate]);

                if ($subject !== null) {
                    return $subject + ['via' => 'alias'];
                }
            }
        }

        $tecnm = $this->tecnm[$clean] ?? null;

        if ($tecnm !== null && isset($this->subjects[$tecnm])) {
            return $this->subjects[$tecnm] + ['via' => 'sii'];
        }

        return null;
    }

    private function withHyphen(string $code): string
    {
        return preg_match('/^([A-Z]+)-?(\d+)$/', $code, $m) === 1 ? $m[1].'-'.$m[2] : $code;
    }

    /**
     * @return array{id: string, department_id: string}|null
     */
    private function bySubjectId(string $id): ?array
    {
        foreach ($this->subjects as $subject) {
            if ($subject['id'] === $id) {
                return $subject;
            }
        }

        return null;
    }

    private function loadTecnmKeys(): void
    {
        $exists = DB::table('information_schema.tables')->where('table_schema', 'sii_archivo')->where('table_name', 'materias_claves_tecnm')->exists();

        if (! $exists) {
            return;
        }

        foreach (DB::table('sii_archivo.materias_claves_tecnm')->get(['materia', 'clave_tecnm']) as $row) {
            $materia = mb_strtoupper(trim((string) $row->materia));
            $code = mb_strtoupper(preg_replace('/\s+/', '', (string) $row->clave_tecnm) ?? '');

            if ($code === '' || $code === $materia) {
                continue;
            }

            if (! isset($this->tecnm[$materia]) || strcmp($code, $this->tecnm[$materia]) < 0) {
                $this->tecnm[$materia] = $code;
            }
        }
    }
}
