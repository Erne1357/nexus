<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('campus_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('career_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_catalog_items');
    }
};
