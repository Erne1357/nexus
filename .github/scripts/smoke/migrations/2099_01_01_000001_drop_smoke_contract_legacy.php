<?php

use App\Modules\Core\Database\Contract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Solo para el smoke del stack de producción (.github/scripts/prod-smoke.sh): una contracción que el deployer debe
| registrar, para que volver atrás exija restaurar el respaldo previo.
*/
return new #[Contract('smoke: ningún release usa smoke_contract.legacy')] class extends Migration
{
    public function up(): void
    {
        Schema::table('smoke_contract', function (Blueprint $table) {
            $table->dropColumn('legacy');
        });
    }

    public function down(): void
    {
        Schema::table('smoke_contract', function (Blueprint $table) {
            $table->string('legacy')->nullable();
        });
    }
};
