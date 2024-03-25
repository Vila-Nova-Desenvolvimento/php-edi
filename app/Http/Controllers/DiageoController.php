<?php

namespace App\Http\Controllers;

use App\Models\Clientes;
use App\Models\Vendas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DiageoController extends Controller
{

    public function index()
    {

        $clientes = Clientes::all();

        # Remova espaços extras em 'estado' e substitua vírgulas por pontos em 'representatividade'
        foreach ($clientes as $key => $cliente) {
            $clientes[$key]->estado = trim($cliente->estado);
            $clientes[$key]->representatividade = str_replace(',', '.', $cliente->representatividade); // Substituir vírgulas por pontos
            $clientes[$key]->cnj_formatado = substr($cliente->cnpj_cpf_do_cliente, 0, 2) . '.' . substr($cliente->cnpj_cpf_do_cliente, 2, 3) . '.' . substr($cliente->cnpj_cpf_do_cliente, 5, 3) . '/' . substr($cliente->cnpj_cpf_do_cliente, 8, 4) . '-' . substr($cliente->cnpj_cpf_do_cliente, 12, 2);
        }


        // Agrupe por estado, some a representatividade e retorne no formato do chartjs
        $grafico = $clientes->groupBy('estado')->map(function ($item, $key) {

            return [
                'estado' => $key,
                'representatividade' => $item->sum(function ($cliente) {
                    return floatval($cliente->representatividade);
                })
            ];

        })->values();

        return Inertia::render('Diageo', [
            'clientes' => $clientes,
            'grafico' => $grafico,
        ]);
    }

    public function vendas(Request $request)
    {

        $vendas = Vendas::where('numero_documento', 'like', '%' . $request->venda . '%')->get();

        $total = 0;

        foreach ($vendas as $key => $venda) {
            $vendas[$key]->subtotal = number_format((float) $venda->preco_de_venda * (float) $venda->quantidade, 2, ',', '.');
            $total += (float) $venda->preco_de_venda * (float) $venda->quantidade;
        }

        return Inertia::render('Vendas', [
            'vendas' => $vendas,
            'total' => number_format($total, 2, ',', '.'),
        ]);

    }

    public function vendas_cnpj(Request $request)
    {



        $vendas = Vendas::select('numero_documento','data_transacao','tipo_do_registro',DB::raw('sum(quantidade * preco_de_venda) as total'))
            ->groupBy(['numero_documento','data_transacao','tipo_do_registro'])
            ->where('identificacao_cliente', 'like', '%' . $request->cnpj . '%')
            ->get();

        $total = 0;

        foreach ($vendas as $key => $venda) {
            $vendas[$key]->total = number_format((float) $venda->total, 2, ',', '.');
            $total += (float) $venda->preco_de_venda * (float) $venda->quantidade;
        }

        return Inertia::render('VendasCnpj', [
            'vendas' => $vendas,
            'total' => number_format($total, 2, ',', '.'),
        ]);

    }

    public function grafico(Request $request)
    {

        return Inertia::render('Chart');

    }

}
