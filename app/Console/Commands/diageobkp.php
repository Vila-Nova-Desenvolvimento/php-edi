<?php

namespace App\Console\Commands;

use App\Models\Clientes;
use App\Models\ClientesAjustados;
use App\Models\ClientesAlvo;
use App\Models\Vendas;
use App\Models\VendasAjustadas;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class diageobkp extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'edi:diageo';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Processa os arquivos EDI da Diageo.';

    /* Se for mensal, gera datas dentro do perído, qualquer outra coisa usa a data original */
    protected $periodo = "diario";

    protected $nomeArquivoVendas = "VENDASUN13032024112404.txt";
    protected $nomeArquivoClientes = "CLIENTESUN13032024112404.txt";

    /**
     * Execute the console command.
     */
    public function handle()
    {

        DB::table('vendas_ajustadas')->delete();
        DB::table('vendas')->delete();
        DB::table('clientes')->delete();
        DB::table('clientes_ajustados')->delete();
        DB::table('clientes_alvo')->delete();

        // Copia os arquivos para uma pasta intermediária
        $this->copiarArquivos();

        // Le o arquivo e grava na tabela vendas
        $this->importarVendas();

        // Le o arquivo e graba na tabela clientes
        $this->importarClientes();

        // Copia os clientes da Consico para uma tabela inermediária
        $this->copiarClientesConsinco();

        // Pulveriza a venda entre novos clientes
        $this->pulverizarVendas();

        // Calcula os clientes
        $this->calcularClientes();

    }

    public function copiarArquivos()
    {

        // Escaneie a pasta /storage/edi_diageo e pegue todos os arquivos
        $files = File::allFiles(storage_path('edi_change'));

        // Se houver um arquivo que comece com "VENDAS", então copie-o para storage/edi_change
        foreach ($files as $file) {
            if (strpos($file->getFilename(), 'VENDAS') === 0) {
                File::copy($file->getPathname(), storage_path('edi_change/' . $file->getFilename()));
            }
        }

        // Se houver um arquivo que comece com "VENDAS", então copie-o para storage/edi_change
        foreach ($files as $file) {
            if (strpos($file->getFilename(), 'CLIENTE') === 0) {
                File::copy($file->getPathname(), storage_path('edi_change/' . $file->getFilename()));
            }
        }

    }


    public function copiarClientesConsinco()
    {

        $firstDayOfLastMonth = Carbon::now()->subMonth()->startOfMonth()->toDateString();
        $lastDayOfLastMonth = Carbon::now()->subMonth()->endOfMonth()->toDateString();

        $clientes = DB::connection('consinco')
            ->table(DB::raw('GE_PESSOA P'))
            ->distinct()
            ->select(
                'P.NROCGCCPF',
                'P.NOMERAZAO',
                'P.FANTASIA',
                'P.BAIRRO',
                'P.CIDADE',
                'P.LOGRADOURO',
                'P.UF',
                DB::raw('P.FONEDDD1 || P.FONENRO1 as FONE'),
                DB::raw('LPAD(P.NROCGCCPF, 12, \'0\') || LPAD(P.DIGCGCCPF, 2, \'0\') AS CNPJ'),
                DB::raw('SUBSTR(P.CEP, 1, 5) || \'-\' || SUBSTR(P.CEP, 6, 3) AS CEP'),
                'MA.CODATIVIDADEEDI',
                'C.NROREPRESENTANTE'
            )
            ->join(DB::raw('MAD_EDIATIVIDADE MA'), function ($join) {
                $join
                    ->where('MA.NOMEEDI', '=', 'DIAGEO')
                    ->on('MA.CODATIVIDADE', '=', 'P.ATIVIDADE');
            })
            ->join(DB::raw('MFL_DOCTOFISCAL DF'), 'DF.SEQPESSOA', '=', 'P.SEQPESSOA')
            ->join(DB::raw('MRL_CLIENTESEG C'), function ($join) {
                $join
                    ->on('C.SEQPESSOA', '=', 'P.SEQPESSOA')
                    ->on('C.NROSEGMENTO', '=', 'DF.NROSEGMENTO');
            })
            ->whereBetween('DF.DTAHOREMISSAO', [DB::raw("TO_DATE('".$firstDayOfLastMonth."', 'YYYY-MM-DD')"), DB::raw("TO_DATE('".$lastDayOfLastMonth."', 'YYYY-MM-DD')")])
            ->where('P.UF', '=', 'MG')
            ->limit(config('edi.customer_limit'))
            ->get();

        $clientes->map(function ($cliente) {
            ClientesAlvo::insert([
                'arquivo_referencia' => 'DIAGEO',
                'nrocgccpf' => $cliente->nrocgccpf,
                'nomerazao' => $cliente->nomerazao,
                'fantasia' => $cliente->fantasia,
                'bairro' => $cliente->bairro,
                'cidade' => $cliente->cidade,
                'logradouro' => $cliente->logradouro,
                'uf' => $cliente->uf,
                'telefone' => $cliente->fone,
                'cnpj' => $cliente->cnpj,
                'cep' => $cliente->cep,
                'codatividadeedi' => $cliente->codatividadeedi,
                'nrorepresentante' => $cliente->nrorepresentante,
            ]);
        });

        return true;

    }

    public function importarClientes()
    {

        $file = File::get(storage_path("edi_change/{$this->nomeArquivoClientes}"));

        // Cabeçalho do arquivo
        $firstLine = explode("\n", $file)[0];

        // Novo arquivo com o cabeçalho original
        File::put(storage_path("edi_changed/{$this->nomeArquivoClientes}"), $firstLine . "\n");

        foreach (explode("\n", $file) as $key => $line) {

            // Se for a primeira linha, pule, pois é o cabeçalho
            if ($key == 0) {
                continue;
            }

            $linha = $this->interpretarLinhaCliente($line);

            Clientes::insert([
                'identificacao_do_cliente' => $linha['identificacao_do_cliente'],
                'razao_social_do_cliente' => $linha['razao_social_do_cliente'],
                'endereco_logradouro' => $linha['endereco_logradouro'],
                'bairro' => $linha['bairro'],
                'cep' => $linha['cep'],
                'cidade' => $linha['cidade'],
                'estado' => $linha['estado'],
                'nome_do_responsavel' => $linha['nome_do_responsavel'],
                'numeros_de_telefone' => $linha['numeros_de_telefone'],
                'cnpj_cpf_do_cliente' => $linha['cnpj_cpf_do_cliente'],
                'rota' => $linha['rota'],
                'campo_reservado' => $linha['campo_reservado'],
                'tipo_de_loja' => $linha['tipo_de_loja'],
                'representatividade' => $linha['representatividade'],
            ]);

        }

    }

    public function importarVendas()
    {

        // Arquivo temporario
        $file = File::get(storage_path("edi_change/{$this->nomeArquivoVendas}"));

        // Cabeçalho do arquivo
        $firstLine = explode("\n", $file)[0];

        // Novo arquivo com o cabeçalho original
        File::put(storage_path("edi_changed/{$this->nomeArquivoVendas}"), $firstLine);

        // Para cada linha do arquivo, faça
        foreach (explode("\n", $file) as $key => $line) {

            // Se for a primeira linha, pule, pois é o cabeçalho
            if ($key == 0) {
                continue;
            }

            // Interpretar a linha do produto
            $linha = $this->interpretarLinhaProduto($line);

            // Adicionar a linha criada no banco de dados
            Vendas::insert([
                'tipo_do_registro' => $linha['tipo_do_registro'],
                'cnpj_agente_distribuicao' => $linha['cnpj_agente_distribuicao'],
                'identificacao_cliente' => $linha['identificacao_cliente'],
                'data_transacao' => $linha['data_transacao'],
                'numero_documento' => $linha['numero_documento'],
                'codigo_do_produto' => $linha['codigo_do_produto'],
                'quantidade' => $linha['quantidade'],
                'preco_de_venda' => $linha['preco_de_venda'],
                'codigo_vendedor' => $linha['codigo_vendedor'],
                'campo_reservado' => $linha['campo_reservado'],
                'tipo_documento' => $linha['tipo_documento'],
                'cep' => $linha['cep'],
                'codigo_lote' => $linha['codigo_lote'],
                'validade_lote' => $linha['validade_lote'],
                'dia_validade_lote' => $linha['dia_validade_lote'],
                'pedido_sugerido' => $linha['pedido_sugerido'],
                'preco_de_venda_us' => $linha['preco_de_venda_us'],
                'tipo_de_unidade' => $linha['tipo_de_unidade'],
            ]);

        }

    }

    public function calcularClientes()
    {

        $vendas = DB::table('vendas_ajustadas')
            ->selectRaw('ROUND(SUM(CAST(REPLACE(quantidade, ",", ".") AS REAL) * CAST(REPLACE(preco_de_venda, ",", ".") AS REAL)), 2) AS total')
            ->first();

        $clientesAjustados = ClientesAjustados::all();


        foreach ($clientesAjustados as $clienteAjustado) {


            $vendaPorCliente = DB::table('vendas_ajustadas')
                ->selectRaw('ROUND(SUM(CAST(REPLACE(quantidade, ",", ".") AS REAL) * CAST(REPLACE(preco_de_venda, ",", ".") AS REAL)), 2) AS total')
                ->where('identificacao_cliente', 'LIKE', "$clienteAjustado->identificacao_do_cliente%")
                ->first();

            ClientesAjustados::updateOrInsert(
                ['identificacao_do_cliente' => $clienteAjustado->identificacao_do_cliente],
                [
                    'representatividade' => number_format(($vendaPorCliente->total / $vendas->total) * 100, 2, '.', ''),
                ]
            );

            $clienteAjustado = json_decode(json_encode($clienteAjustado), true);
            $clienteAjustado['representatividade'] = number_format(($vendaPorCliente->total / $vendas->total) * 100, 2, '.', '');

            $linha = $this->criar_linha_cliente($clienteAjustado);

            File::append(storage_path("edi_changed/{$this->nomeArquivoClientes}"), $linha . "\n");

        }
    }

    public function pulverizarVendas()
    {

        // Arquivo temporario
        $file = File::get(storage_path("edi_change/{$this->nomeArquivoVendas}"));

        // Cabeçalho do arquivo
        $firstLine = explode("\n", $file)[0];

        // Novo arquivo com o cabeçalho original
        File::put(storage_path("edi_changed/{$this->nomeArquivoVendas}"), $firstLine . "\n");

        // Separar o conteúdo do arquivo por linhas
        $linhas = explode("\n", $file);

        foreach ($linhas as $key => $line) {

            // Se for a primeira linha, pule
            if ($key == 0) {
                continue;
            }

            // Interpretar a linha do produto
            $linha = $this->interpretarLinhaProduto($line);

            // Se o CEP não for de Minas Gerais
            if (!in_array(substr($linha['cep'], 0, 2), ['30', '31', '32', '33', '34', '35', '36', '37', '38', '39'])) {

                // Obter a quantidade do produto como um número inteiro
                $quantidade = (int) explode('.', $linha['quantidade'])[0];

                // Inicializar a quantidade restante com a quantidade total
                $quantidade_restante = $quantidade;

                // Enquanto houver quantidade restante
                while ($quantidade_restante > 0) {

                    // Definir a quantidade ideal com base na lógica fornecida
                    if ($quantidade_restante <= 120) {
                        $quantidade_ideal = $quantidade_restante;
                    } else {
                        // Array com as quantidades ideais disponíveis
                        $quantidades_ideais_disponiveis = [6, 12, 12, 24, 24, 24, 24, 36, 36, 48, 48, 48, 60, 60, 72, 84, 96, 96, 96, 102, 114, 120, 120];
                        // Selecionar aleatoriamente uma quantidade ideal disponível
                        $quantidade_ideal = $quantidades_ideais_disponiveis[array_rand($quantidades_ideais_disponiveis)];
                    }

                    // Padronizar a quantidade para ter 20 caracteres, preenchendo com zeros à esquerda
                    $linha['quantidade'] = str_pad($quantidade_ideal, 15, '0', STR_PAD_LEFT) . ".0000";

                    $clienteAlvo = ClientesAlvo::where('int_flag', 0)->inRandomOrder()->first();

                    // Troca o CNPJ
                    $linha["identificacao_cliente"] = $clienteAlvo->cnpj;
                    $linha["cep"] = $clienteAlvo->cep;

                    // Se a configuração do arquivo tiver para mensal, gera datas aleatórias
                    if($this->periodo == "mensal") {

                        // pegue o ano de $linha["data_transacao"]
                        $ano = substr($linha["data_transacao"], 0, 4);
                        $mes = substr($linha["data_transacao"], 4, 2);
                        $ultimoDiaMes = Carbon::createFromDate($ano, $mes, 01)->endOfMonth()->day;

                        $linha["data_transacao"] = Carbon::createFromDate($ano, $mes, 01)->addDays(rand(1, $ultimoDiaMes))->format('Ymd');
                    }

                    // Troca o número da nota
                    $linha["numero_documento"] = $linha["numero_documento"] + rand(-1000, 1000);
                    $linha["numero_documento"] = $linha["numero_documento"] > 1000 ? $linha["numero_documento"] : $linha["numero_documento"] + 1000;

                    // Criar a linha de produtos
                    $linhaNova = $this->criarLinhaProdutos($linha);

                    // Volta o formato
                    $linha['quantidade'] = (int) explode('.', $linha['quantidade'])[0];

                    ClientesAjustados::updateOrInsert(
                        ['identificacao_do_cliente' => $linha['identificacao_cliente']],
                        [
                            'razao_social_do_cliente' => $clienteAlvo->nomerazao,
                            'endereco_logradouro' => $linha['endereco_logradouro'] ?? $clienteAlvo->logradouro,
                            'bairro' => $linha['bairro'] ?? $clienteAlvo->bairro,
                            'cep' => $linha['cep'],
                            'cidade' => $linha['cidade'] ?? $clienteAlvo->cidade,
                            'estado' => $linha['estado'] ?? $clienteAlvo->uf,
                            'nome_do_responsavel' => $clienteAlvo->fantasia,
                            'numeros_de_telefone' => $linha['numeros_de_telefone'] ?? $clienteAlvo->telefone,
                            'cnpj_cpf_do_cliente' => $linha['identificacao_cliente'] ?? $clienteAlvo->cnpj,
                            'rota' => $linha['rota'] ?? "90        ",
                            'campo_reservado' => $linha['campo_reservado'],
                            'tipo_de_loja' => $linha['tipo_de_loja'] ?? $clienteAlvo->codatividadeedi,
                            'representatividade' => $linha['representatividade'] ?? null,
                        ]
                    );

                    VendasAjustadas::insert([
                        'tipo_do_registro' => $linha['tipo_do_registro'],
                        'cnpj_agente_distribuicao' => $linha['cnpj_agente_distribuicao'],
                        'identificacao_cliente' => $linha['identificacao_cliente'],
                        'data_transacao' => $linha['data_transacao'],
                        'numero_documento' => $linha['numero_documento'],
                        'codigo_do_produto' => $linha['codigo_do_produto'],
                        'quantidade' => $linha['quantidade'],
                        'preco_de_venda' => $linha['preco_de_venda'],
                        'codigo_vendedor' => $linha['codigo_vendedor'],
                        'campo_reservado' => $linha['campo_reservado'],
                        'tipo_documento' => $linha['tipo_documento'],
                        'cep' => $linha['cep'],
                        'codigo_lote' => $linha['codigo_lote'],
                        'validade_lote' => $linha['validade_lote'],
                        'dia_validade_lote' => $linha['dia_validade_lote'],
                        'pedido_sugerido' => $linha['pedido_sugerido'],
                        'preco_de_venda_us' => $linha['preco_de_venda_us'],
                        'tipo_de_unidade' => $linha['tipo_de_unidade'],
                    ]);

                    // Adicionar a linha criada ao final do arquivo
                    File::append(storage_path("edi_changed/{$this->nomeArquivoVendas}"), $linhaNova . "\n");

                    // Atualizar a quantidade restante subtraindo a quantidade ideal utilizada
                    $quantidade_restante -= $quantidade_ideal;

                }
            }else{

                if(!empty($linha['identificacao_cliente'])){

                    $cnpj = trim($linha['identificacao_cliente']);

                    $cliente = Clientes::where('identificacao_do_cliente', 'LIKE', "$cnpj%")->first();

                    ClientesAjustados::updateOrInsert(
                        ['identificacao_do_cliente' => $linha['identificacao_cliente']],
                        [
                            'razao_social_do_cliente' => $cliente->razao_social_do_cliente ?? null,
                            'endereco_logradouro' => $cliente->endereco_logradouro ?? null,
                            'bairro' => $cliente->bairro ?? null,
                            'cep' => $cliente->cep ?? null,
                            'cidade' => $cliente->cidade ?? null,
                            'estado' => $cliente->estado ?? null,
                            'nome_do_responsavel' => $cliente->nome_do_responsavel ?? null,
                            'numeros_de_telefone' => $cliente->numeros_de_telefone ?? null,
                            'cnpj_cpf_do_cliente' => $cliente->cnpj_cpf_do_cliente ?? null,
                            'rota' => $cliente->rota ?? null,
                            'campo_reservado' => $cliente->campo_reservado ?? null,
                            'tipo_de_loja' => $cliente->tipo_de_loja ?? null,
                            'representatividade' => null,
                        ]
                    );

                    VendasAjustadas::insert([
                        'tipo_do_registro' => $linha['tipo_do_registro'],
                        'cnpj_agente_distribuicao' => $linha['cnpj_agente_distribuicao'],
                        'identificacao_cliente' => $linha['identificacao_cliente'],
                        'data_transacao' => $linha['data_transacao'],
                        'numero_documento' => $linha['numero_documento'],
                        'codigo_do_produto' => $linha['codigo_do_produto'],
                        'quantidade' => $linha['quantidade'],
                        'preco_de_venda' => $linha['preco_de_venda'],
                        'codigo_vendedor' => $linha['codigo_vendedor'],
                        'campo_reservado' => $linha['campo_reservado'],
                        'tipo_documento' => $linha['tipo_documento'],
                        'cep' => $linha['cep'],
                        'codigo_lote' => $linha['codigo_lote'],
                        'validade_lote' => $linha['validade_lote'],
                        'dia_validade_lote' => $linha['dia_validade_lote'],
                        'pedido_sugerido' => $linha['pedido_sugerido'],
                        'preco_de_venda_us' => $linha['preco_de_venda_us'],
                        'tipo_de_unidade' => $linha['tipo_de_unidade'],
                    ]);

                }

                File::append(storage_path('edi_changed/VENDASUN13032024112404.txt'), $line . "\n");

            }

        }

    }


    public static function criarLinhaProdutos($campos)
    {
        $estruturaCampos = [
            ['tipo_do_registro', 1],
            ['cnpj_agente_distribuicao', 14],
            ['identificacao_cliente', 18],
            ['data_transacao', 8],
            ['numero_documento', 20],
            ['codigo_do_produto', 14],
            ['quantidade', 20],
            ['preco_de_venda', 8],
            ['codigo_vendedor', 20],
            ['campo_reservado', 10],
            ['tipo_documento', 1],
            ['cep', 9],
            ['codigo_lote', 13],
            ['validade_lote', 6],
            ['dia_validade_lote', 2],
            ['pedido_sugerido', 1],
            ['preco_de_venda_us', 8],
            ['tipo_de_unidade', 4],
        ];

        $linha = '';

        foreach ($estruturaCampos as [$campo, $tamanho]) {
            $valor = isset($campos[$campo]) ? strval($campos[$campo]) : ''; // Convert to string and handle null
            $linha .= str_pad($valor, $tamanho, ' ', STR_PAD_RIGHT);
        }

        return $linha;
    }


    public function criar_linha_cliente($campos) {
        $estrutura_campos = array(
            array('identificacao_do_cliente', 18),
            array('razao_social_do_cliente', 40),
            array('endereco_logradouro', 40),
            array('bairro', 30),
            array('cep', 9),
            array('cidade', 30),
            array('estado', 30),
            array('nome_do_responsavel', 20),
            array('numeros_de_telefone', 40),
            array('cnpj_cpf_do_cliente', 18),
            array('rota', 10),
            array('campo_reservado', 10),
            array('tipo_de_loja', 10),
            array('representatividade', 6)
        );

        $linha = '';

        foreach (array_map(null, $estrutura_campos, $campos) as $info) {
            list(list($campo, $tamanho), $valor) = $info;
            $valor = substr(strval($valor), 0, $tamanho) . str_repeat(' ', max(0, $tamanho - strlen(strval($valor)))); // Convert to string, limit size, and pad with spaces

            if ($campo == 'representatividade') {
                $valor_parts = explode(',', str_replace('.', ',', $valor));
                $parte_inteira = isset($valor_parts[0]) ? str_pad($valor_parts[0], 3, '0', STR_PAD_LEFT) : '000';
                $parte_decimal = isset($valor_parts[1]) ? str_pad(trim($valor_parts[1]), 2, '0', STR_PAD_LEFT) : '00';
                $valor = $parte_inteira . ',' . $parte_decimal;
            }

            $linha .= $valor;
        }

        return 'D23637077000172' . $linha;
    }

    public function interpretarLinhaProduto($linha)
    {
        $campos = [
            'tipo_do_registro' => [1, 1, 'X(1)'],
            'cnpj_agente_distribuicao' => [14, 2, 'X(14)'],
            'identificacao_cliente' => [18, 16, 'X(16)'],
            'data_transacao' => [8, 34, 'AAAAMMDD'],
            'numero_documento' => [20, 42, 'X(20)'],
            'codigo_do_produto' => [14, 62, '9(14)'],
            'quantidade' => [20, 76, '9(15).(4)'],
            'preco_de_venda' => [8, 96, '9(5).9(2)'],
            'codigo_vendedor' => [20, 104, 'X(20)'],
            'campo_reservado' => [10, 124, 'X(10)'],
            'tipo_documento' => [1, 134, 'X(1)'],
            'cep' => [9, 135, '9(5)-9(3)'],
            'codigo_lote' => [13, 144, 'X(13)'],
            'validade_lote' => [6, 157, 'AAAAMM'],
            'dia_validade_lote' => [2, 163, 'DD'],
            'pedido_sugerido' => [1, 165, 'X(1)'],
            'preco_de_venda_us' => [8, 166, '9(5).9(2)'],
            'tipo_de_unidade' => [4, 174, '9(4)'],
        ];

        $resultado = [];

        foreach ($campos as $campo => $detalhes) {
            list($tamanho, $inicio, $formato) = $detalhes;
            $valor = substr($linha, $inicio - 1, $tamanho);
            $resultado[$campo] = $valor;
        }

        return $resultado;
    }

    public function interpretarLinhaCliente($linha)
    {
        $campos = [
            'identificacao_do_cliente' => [18, 16, 'X(18)'],
            'razao_social_do_cliente' => [40, 34, 'X(40)'],
            'endereco_logradouro' => [40, 74, 'X(40)'],
            'bairro' => [30, 114, 'X(30)'],
            'cep' => [9, 144, '9(5)-9(3)'],
            'cidade' => [30, 153, 'X(30)'],
            'estado' => [30, 183, 'X(30)'],
            'nome_do_responsavel' => [20, 213, 'X(20)'],
            'numeros_de_telefone' => [40, 233, 'X(40)'],
            'cnpj_cpf_do_cliente' => [18, 273, 'X(18)'],
            'rota' => [10, 291, 'X(10)'],
            'campo_reservado' => [10, 301, 'X(10)'],
            'tipo_de_loja' => [10, 311, 'X(10)'],
            'representatividade' => [6, 321, '9(3).9(2)'],
        ];

        $resultado = [];

        foreach ($campos as $campo => $detalhes) {
            list($tamanho, $inicio, $formato) = $detalhes;
            $valor = substr($linha, $inicio - 1, $tamanho);
            $resultado[$campo] = $valor;
        }

        return $resultado;
    }


}
