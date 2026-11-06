<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La clave natural del docente pasa de `employee_number` a `rfc` (reglas-de-negocio.md, confirmado el 5 de
 * noviembre de 2026): en el SII todo se liga por RFC y el número de tarjeta no identifica a nadie. Renombra la
 * columna y su índice único y actualiza los mapeos de importación guardados de las plantillas de docentes, cuyo
 * campo `employee_number` ahora es `rfc`. Los renglones de lotes pasados conservan el nombre viejo: son historia.
 */
return new class extends Migration
{
    private const array TEMPLATES = ['teachers', 'positions', 'teacher_history', 'evaluations'];

    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->renameColumn('employee_number', 'rfc');
        });

        DB::statement('alter index teachers_employee_number_unique rename to teachers_rfc_unique');

        $this->remap('employee_number', 'rfc');
    }

    public function down(): void
    {
        $this->remap('rfc', 'employee_number');

        DB::statement('alter index teachers_rfc_unique rename to teachers_employee_number_unique');

        Schema::table('teachers', function (Blueprint $table) {
            $table->renameColumn('rfc', 'employee_number');
        });
    }

    /**
     * Cambia el campo de destino `$from` por `$to` en las columnas de los mapeos de esas plantillas.
     */
    private function remap(string $from, string $to): void
    {
        $rows = DB::table('import_mappings')->whereIn('template', self::TEMPLATES)->get(['id', 'columns']);

        foreach ($rows as $row) {
            $columns = json_decode((string) $row->columns, true);

            if (! is_array($columns) || ! in_array($from, $columns, true)) {
                continue;
            }

            $columns = array_map(fn (mixed $field): mixed => $field === $from ? $to : $field, $columns);

            DB::table('import_mappings')->where('id', $row->id)->update(['columns' => json_encode($columns, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
