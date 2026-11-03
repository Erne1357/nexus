<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organigrama como árbol (ADR-0018, AZ-S1). Una sola raíz (la Dirección, sin contar borradas) y
 * clave única que cuenta también las borradas; los departamentos académicos quedan fuera del
 * índice porque cuidan su clave en `departments`. Siembra la Dirección y la Subdirección Académica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('parent_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('kind');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('parent_id');
        });

        Schema::table('org_units', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('org_units');
        });

        DB::statement("alter table org_units add constraint org_units_kind_check check (kind in ('direccion','subdireccion','division','departamento_academico','departamento','oficina'))");
        DB::statement('create unique index org_units_one_root on org_units ((parent_id is null)) where parent_id is null and deleted_at is null');
        DB::statement("create unique index org_units_code_unique on org_units (code) where kind <> 'departamento_academico'");

        DB::table('org_units')->insert([
            'code' => 'DIRECCION',
            'name' => 'Dirección',
            'kind' => 'direccion',
            'sort' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rootId = DB::table('org_units')->where('code', 'DIRECCION')->value('id');

        DB::table('org_units')->insert([
            'parent_id' => $rootId,
            'code' => 'SUB-ACADEMICA',
            'name' => 'Subdirección Académica',
            'kind' => 'subdireccion',
            'sort' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('org_units');
    }
};
