<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clientes_alvo', function (Blueprint $table) {
            $table->id();
            $table->string('arquivo_referencia')->nullable();
            $table->string('nrocgccpf')->nullable();
            $table->string('nomerazao')->nullable();
            $table->string('fantasia')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cidade')->nullable();
            $table->string('logradouro')->nullable();
            $table->string('uf')->nullable();
            $table->string('telefone')->nullable();
            $table->string('cnpj')->nullable();
            $table->string('cep')->nullable();
            $table->string('codatividadeedi')->nullable();
            $table->string('nrorepresentante')->nullable();
            $table->integer('int_flag')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes_alvo');
    }
};
