<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La modalidad del alumno sale del texto (`students.modality`) y pasa a la llave del catálogo `modalities` (MS7,
 * supuesto por confirmar): presencial es `PRES`; a distancia y mixta son `EAD`; sin modalidad, `PRES`. La columna
 * queda obligatoria. `down()` regresa al texto: `PRES` a presencial y las demás a distancia (la mixta ya no se distingue).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::table('students', function (Blueprint $table) {
                $table->foreignUuid('modality_id')->nullable()->constrained('modalities');
            });

            foreach (['presencial' => 'PRES', 'distancia' => 'EAD', 'mixta' => 'EAD'] as $text => $code) {
                DB::table('students')->where('modality', $text)->update(['modality_id' => DB::table('modalities')->where('code', $code)->value('id')]);
            }

            DB::table('students')->whereNull('modality_id')->update(['modality_id' => DB::table('modalities')->where('code', 'PRES')->value('id')]);

            Schema::table('students', function (Blueprint $table) {
                $table->uuid('modality_id')->nullable(false)->change();
                $table->dropColumn('modality');
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::table('students', function (Blueprint $table) {
                $table->string('modality')->nullable();
            });

            DB::table('students')->update([
                'modality' => DB::raw("CASE WHEN modality_id = (SELECT id FROM modalities WHERE code = 'PRES') THEN 'presencial' ELSE 'distancia' END"),
            ]);

            Schema::table('students', function (Blueprint $table) {
                $table->dropConstrainedForeignId('modality_id');
            });
        });
    }
};
