<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Edificios de un campus. La clave es única por campus, también contra uno borrado. La unicidad
 * (`id`, `campus_id`) existe para que `rooms` pueda amarrar su campus al de su edificio con una llave compuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('campus_id')->constrained();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['campus_id', 'code']);
            $table->unique(['id', 'campus_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
