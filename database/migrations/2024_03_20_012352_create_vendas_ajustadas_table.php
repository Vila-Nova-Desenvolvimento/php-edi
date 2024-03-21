<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVendasAjustadasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vendas_ajustadas', function (Blueprint $table) {
            $table->id();
            $table->string('arquivo_referencia')->nullable();
            $table->string('tipo_do_registro', 1);
            $table->string('cnpj_agente_distribuicao', 14);
            $table->string('identificacao_cliente', 16);
            $table->date('data_transacao');
            $table->string('numero_documento', 20);
            $table->string('codigo_do_produto', 14);
            $table->decimal('quantidade', 19, 4); // Note que 19, 4 é o formato para 9(15).(4)
            $table->decimal('preco_de_venda', 7, 2); // Note que 7, 2 é o formato para 9(5).9(2)
            $table->string('codigo_vendedor', 20);
            $table->string('campo_reservado', 10);
            $table->string('tipo_documento', 1);
            $table->string('cep', 9);
            $table->string('codigo_lote', 13);
            $table->date('validade_lote');
            $table->string('dia_validade_lote', 2);
            $table->string('pedido_sugerido', 1);
            $table->decimal('preco_de_venda_us', 7, 2); // Note que 7, 2 é o formato para 9(5).9(2)
            $table->string('tipo_de_unidade', 4);
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
        Schema::dropIfExists('vendas_ajustadas');
    }
}
