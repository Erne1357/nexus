<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Solo para el smoke del stack de producción (.github/scripts/prod-smoke.sh): nunca entra a la app.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smoke_contract', function (Blueprint $table) {
            $table->id();
            $table->string('legacy')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smoke_contract');
    }
};
