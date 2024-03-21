<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('arquivo_referencia')->nullable();
            $table->string('identificacao_do_cliente', 18);
            $table->string('razao_social_do_cliente', 40);
            $table->string('endereco_logradouro', 40);
            $table->string('bairro', 30);
            $table->string('cep', 12);
            $table->string('cidade', 30);
            $table->string('estado', 30);
            $table->string('nome_do_responsavel', 20);
            $table->string('numeros_de_telefone', 40);
            $table->string('cnpj_cpf_do_cliente', 18);
            $table->string('rota', 10);
            $table->string('campo_reservado', 10);
            $table->string('tipo_de_loja', 10);
            $table->decimal('representatividade', 5, 2);
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
        Schema::dropIfExists('clientes');
    }
}
