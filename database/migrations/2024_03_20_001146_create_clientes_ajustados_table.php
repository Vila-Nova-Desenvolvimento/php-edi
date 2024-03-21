<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientesAjustadosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('clientes_ajustados', function (Blueprint $table) {
            $table->id();
            $table->string('arquivo_referencia')->nullable();
            $table->string('identificacao_do_cliente');
            $table->string('razao_social_do_cliente')->nullable();
            $table->string('endereco_logradouro')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cep');
            $table->string('cidade')->nullable();
            $table->string('estado')->nullable();
            $table->string('nome_do_responsavel')->nullable();
            $table->string('numeros_de_telefone')->nullable();
            $table->string('cnpj_cpf_do_cliente')->nullable();
            $table->string('rota')->nullable();
            $table->string('campo_reservado');
            $table->string('tipo_de_loja')->nullable();
            $table->string('representatividade')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('clientes_ajustados');
    }
}
