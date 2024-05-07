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

class diageo extends Command
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

    protected $nomeArquivoVendas = "";
    protected $nomeArquivoClientes = "";

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $this->info('Ajustando os dados...');
        $this->clearTerminal(2);

        DB::table('vendas_ajustadas')->delete();
        DB::table('vendas')->delete();
        DB::table('clientes')->delete();
        DB::table('clientes_ajustados')->delete();
        DB::table('clientes_alvo')->delete();

        $this->info('Processando arquivos EDI da Diageo...');
        $this->clearTerminal(2);

        $this->info('Copiando arquivos...');

        // Copia os arquivos para uma pasta intermediária
        $this->copiarArquivos();

        $this->clearTerminal(0);
        $this->info('Precessando vendas ...');


        // Le o arquivo e grava na tabela vendas
        $this->importarVendas();

        $this->clearTerminal(0);
        $this->info('Processando clientes!');

        // Le o arquivo e graba na tabela clientes
        $this->importarClientes();


        $this->clearTerminal(0);
        $this->info('Buscando clientes no ERP Consinco...');

        // Copia os clientes da Consico para uma tabela inermediária
        $this->copiarClientesConsinco();

        $this->clearTerminal(0);
        $this->info('Pulverizando vendas...');

        // Pulveriza a venda entre novos clientes
        $this->pulverizarVendas();

        $this->clearTerminal(0);
        $this->info('Calculando representatividade...');

        // Calcula os clientes
        $this->calcularClientes();

        $this->clearTerminal(0);
        $this->info('Diponibilizando os arquivos...');

        $this->copiarArquivosAjustados();

        $this->clearTerminal(0);
        $this->info('ok, tá na mão!');

    }

    public function copiarArquivos()
    {

        // Escaneie a pasta /storage/edi_diageo e pegue todos os arquivos
        $files = File::allFiles(storage_path('/edi_diageo/emp_01'));


        // Se houver um arquivo que comece com "VENDAS", então copie-o para storage/edi_change
        foreach ($files as $file) {
            if (strpos($file->getFilename(), 'VENDAS') === 0) {
                File::copy($file->getPathname(), storage_path('edi_change/' . $file->getFilename()));
                $this->nomeArquivoVendas = $file->getFilename();
            }
        }

        // Se houver um arquivo que comece com "VENDAS", então copie-o para storage/edi_change
        foreach ($files as $file) {
            if (strpos($file->getFilename(), 'CLIENTE') === 0) {
                File::copy($file->getPathname(), storage_path('edi_change/' . $file->getFilename()));
                $this->nomeArquivoClientes = $file->getFilename();
            }
        }

    }


    public function copiarClientesConsinco()
    {

        // Obter a data do registro mais recente na tabela ClientesAlvo
        $ultimoRegistro = ClientesAlvo::max('created_at');

        // Obter o primeiro dia do mês atual
        $primeiroDiaDoMesAtual = Carbon::now()->startOfMonth();

        // Comparar se o último registro é menor que o primeiro dia do mês atual
        if ($ultimoRegistro < $primeiroDiaDoMesAtual) {

            // Truncar a tabela ClientesAlvo
            DB::table('clientes_alvo')->truncate();

            $firstDayOfLastMonth = Carbon::now()->subMonth()->startOfMonth()->toDateString();
            $lastDayOfLastMonth = Carbon::now()->subMonth()->endOfMonth()->toDateString();

            $blackList = DB::table('clientes_blacklist')->select('codcli')->get();

            $clientes = DB::connection('consinco')
                ->table(DB::raw('GE_PESSOA P'))
                ->distinct()
                ->select(
                    'P.SEQPESSOA',
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
                ->whereBetween('DF.DTAHOREMISSAO', [DB::raw("TO_DATE('" . $firstDayOfLastMonth . "', 'YYYY-MM-DD')"), DB::raw("TO_DATE('" . $lastDayOfLastMonth . "', 'YYYY-MM-DD')")])
                ->where('P.UF', '=', 'MG')
                ->limit(config('edi.customer_limit'))
                ->get();

            $clientes->map(function ($cliente) use ($blackList) {

                if (!in_array($cliente->seqpessoa, $blackList->toArray())) {
                    ClientesAlvo::insert([
                        'codcli' => $cliente->seqpessoa,
                        'arquivo_referencia' => $this->nomeArquivoClientes,
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
                        'created_at' => Carbon::now()
                    ]);
                }

            });
        }

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

            if(!empty($linha['identificacao_do_cliente'])){
                Clientes::insert([
                    'arquivo_referencia' => $this->nomeArquivoClientes,
                    'identificacao_do_cliente' => trim($linha['identificacao_do_cliente']),
                    'razao_social_do_cliente' => trim($linha['razao_social_do_cliente']),
                    'endereco_logradouro' => trim($linha['endereco_logradouro']),
                    'bairro' => trim($linha['bairro']),
                    'cep' => trim($linha['cep']),
                    'cidade' => trim($linha['cidade']),
                    'estado' => trim($linha['estado']),
                    'nome_do_responsavel' => trim($linha['nome_do_responsavel']),
                    'numeros_de_telefone' => trim($linha['numeros_de_telefone']),
                    'cnpj_cpf_do_cliente' => trim($linha['cnpj_cpf_do_cliente']),
                    'rota' => trim($linha['rota']),
                    'campo_reservado' => trim($linha['campo_reservado']),
                    'tipo_de_loja' => trim($linha['tipo_de_loja']),
                    'representatividade' => trim($linha['representatividade']),
                ]);
            }

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

            if(!empty(trim($linha['cnpj_agente_distribuicao']))){
                Vendas::insert([
                    'arquivo_referencia' => $this->nomeArquivoVendas,
                    'tipo_do_registro' => trim($linha['tipo_do_registro']),
                    'cnpj_agente_distribuicao' => trim($linha['cnpj_agente_distribuicao']),
                    'identificacao_cliente' => trim($linha['identificacao_cliente']),
                    'data_transacao' => trim($linha['data_transacao']),
                    'numero_documento' => trim($linha['numero_documento']),
                    'codigo_do_produto' => trim($linha['codigo_do_produto']),
                    'quantidade' => trim($linha['quantidade']),
                    'preco_de_venda' => trim($linha['preco_de_venda']),
                    'codigo_vendedor' => trim($linha['codigo_vendedor']),
                    'campo_reservado' => trim($linha['campo_reservado']),
                    'tipo_documento' => trim($linha['tipo_documento']),
                    'cep' => trim($linha['cep']),
                    'codigo_lote' => trim($linha['codigo_lote']),
                    'validade_lote' => trim($linha['validade_lote']) ?? null,
                    'dia_validade_lote' => trim($linha['dia_validade_lote']),
                    'pedido_sugerido' => trim($linha['pedido_sugerido']),
                    'preco_de_venda_us' => trim($linha['preco_de_venda_us']),
                    'tipo_de_unidade' => trim($linha['tipo_de_unidade']),
                ]);
            }

        }

    }

    public function calcularClientes()
    {

        $vendas = DB::table('vendas_ajustadas')
            ->selectRaw('ROUND(SUM(CAST(REPLACE(quantidade, ",", ".") AS DECIMAL(10,2)) * CAST(REPLACE(preco_de_venda, ",", ".") AS DECIMAL(10,2))), 2) AS total')
            ->first();

        $clientesAjustados = ClientesAjustados::all();


        foreach ($clientesAjustados as $clienteAjustado) {


            $vendaPorCliente = DB::table('vendas_ajustadas')
                ->select(DB::raw('ROUND(SUM(CAST(REPLACE(quantidade, ",", ".") AS DECIMAL(10,2)) * CAST(REPLACE(preco_de_venda, ",", ".") AS DECIMAL(10,2))), 2) AS total'))
                ->where('identificacao_cliente', 'LIKE', "$clienteAjustado->identificacao_do_cliente%")
                ->first();


            $representatividade = number_format(($vendaPorCliente->total / $vendas->total) * 100, 2, '.', '');
            $representatividade = str_replace('.', ',', $representatividade);
            $representatividade = str_pad($representatividade, 7, '0', STR_PAD_LEFT);

            ClientesAjustados::updateOrInsert(
                ['identificacao_do_cliente' => $clienteAjustado->identificacao_do_cliente],
                [
                    'representatividade' => $representatividade,
                ]
            );

            $clienteAjustado = json_decode(json_encode($clienteAjustado), true);
            $clienteAjustado['representatividade'] = $representatividade;

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

        $bar = $this->output->createProgressBar(count($linhas));

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

                // Acréscimo de 40
                // Acréscimo de 40
                $percentual = mt_rand(38, 42) / 100; // Gera um percentual aleatório entre 38 e 42
                $precoDeVenda = (float) $linha['preco_de_venda']; // Converte o preço de venda para float
                $precoDeVenda *= (1 + $percentual); // Calcula o preço de venda com o acréscimo
                $linha['preco_de_venda'] = sprintf('%08.2f', $precoDeVenda); // Formata o preço de venda

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
                    $linha["numero_documento"] = (int) $linha["numero_documento"] + rand(-1000, 1000);
                    $linha["numero_documento"] = $linha["numero_documento"] > 1000 ? $linha["numero_documento"] : $linha["numero_documento"] + 1000;
                    $linha["numero_documento"] = "00" . $linha["numero_documento"];

                    // Criar a linha de produtos
                    $linhaNova = $this->criarLinhaProdutos($linha);

                    // Volta o formato
                    $linha['quantidade'] = (int) explode('.', $linha['quantidade'])[0];

                    ClientesAjustados::updateOrInsert(
                        ['identificacao_do_cliente' => $linha['identificacao_cliente']],
                        [
                            'arquivo_referencia' => $this->nomeArquivoClientes,
                            'razao_social_do_cliente' => trim($clienteAlvo->nomerazao),
                            'endereco_logradouro' => trim($linha['endereco_logradouro'] ?? $clienteAlvo->logradouro),
                            'bairro' => trim($linha['bairro'] ?? $clienteAlvo->bairro),
                            'cep' => trim($linha['cep']),
                            'cidade' => trim($linha['cidade'] ?? $clienteAlvo->cidade),
                            'estado' => trim($linha['estado'] ?? $clienteAlvo->uf),
                            'nome_do_responsavel' => trim($clienteAlvo->fantasia),
                            'numeros_de_telefone' => trim($linha['numeros_de_telefone'] ?? $clienteAlvo->telefone),
                            'cnpj_cpf_do_cliente' => trim($linha['identificacao_cliente'] ?? $clienteAlvo->cnpj),
                            'rota' => trim($linha['rota'] ?? "90"),
                            'campo_reservado' => trim($linha['campo_reservado']),
                            'tipo_de_loja' => trim($linha['tipo_de_loja'] ?? $clienteAlvo->codatividadeedi),
                            'representatividade' => trim($linha['representatividade'] ?? null),
                        ]
                    );

                    VendasAjustadas::insert([
                        'arquivo_referencia' => $this->nomeArquivoVendas,
                        'tipo_do_registro' => trim($linha['tipo_do_registro']),
                        'cnpj_agente_distribuicao' => trim($linha['cnpj_agente_distribuicao']),
                        'identificacao_cliente' => trim($linha['identificacao_cliente']),
                        'data_transacao' => trim($linha['data_transacao']),
                        'numero_documento' => trim($linha['numero_documento']),
                        'codigo_do_produto' => trim($linha['codigo_do_produto']),
                        'quantidade' => trim($linha['quantidade']),
                        'preco_de_venda' => trim($linha['preco_de_venda']),
                        'codigo_vendedor' => trim($linha['codigo_vendedor']),
                        'campo_reservado' => trim($linha['campo_reservado']),
                        'tipo_documento' => trim($linha['tipo_documento']),
                        'cep' => trim($linha['cep']),
                        'codigo_lote' => trim($linha['codigo_lote']),
                        'validade_lote' => trim($linha['validade_lote']),
                        'dia_validade_lote' => trim($linha['dia_validade_lote']),
                        'pedido_sugerido' => trim($linha['pedido_sugerido']),
                        'preco_de_venda_us' => trim($linha['preco_de_venda_us']),
                        'tipo_de_unidade' => trim($linha['tipo_de_unidade']),
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
                            'arquivo_referencia' => $this->nomeArquivoClientes,
                            'razao_social_do_cliente' => trim($cliente->razao_social_do_cliente ?? null),
                            'endereco_logradouro' => trim($cliente->endereco_logradouro ?? null),
                            'bairro' => trim($cliente->bairro ?? null),
                            'cep' => trim($cliente->cep ?? null),
                            'cidade' => trim($cliente->cidade ?? null),
                            'estado' => trim($cliente->estado ?? null),
                            'nome_do_responsavel' => trim($cliente->nome_do_responsavel ?? null),
                            'numeros_de_telefone' => trim($cliente->numeros_de_telefone ?? null),
                            'cnpj_cpf_do_cliente' => trim($cliente->cnpj_cpf_do_cliente ?? null),
                            'rota' => trim($cliente->rota ?? null),
                            'campo_reservado' => trim($cliente->campo_reservado ?? null),
                            'tipo_de_loja' => trim($cliente->tipo_de_loja ?? null),
                            'representatividade' => null,
                        ]
                    );

                    VendasAjustadas::insert([
                        'arquivo_referencia' => $this->nomeArquivoVendas,
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

                File::append(storage_path("edi_changed/{$this->nomeArquivoVendas}"), $line . "\n");

            }

            $bar->advance();

        }

        $bar->finish();

    }

    public static function criarLinhaProdutos($campos)
    {
        $estrutura_campos = [
            ['tipo_do_registro', 1,'A'],
            ['cnpj_agente_distribuicao', 14,'A'],
            ['identificacao_cliente', 18,'A'],
            ['data_transacao', 8, 'D'],
            ['numero_documento', 20, 'A'],
            ['codigo_do_produto', 14, 'A'],
            ['quantidade', 20,'N'],
            ['preco_de_venda', 8,'N'],
            ['codigo_vendedor', 20,'A'],
            ['campo_reservado', 10,'A'],
            ['tipo_documento', 1,'A'],
            ['cep', 9,'N'],
            ['codigo_lote', 13,'A'],
            ['validade_lote', 6,'N'],
            ['dia_validade_lote', 2,'N'],
            ['pedido_sugerido', 1,'A'],
            ['preco_de_venda_us', 8,'N'],
            ['tipo_de_unidade', 4,'N'],
        ];

        $linha = "";

        foreach ($estrutura_campos as $esturura){

            $nomeDoCampo = $esturura[0];
            $tamanhoDoCampo = $esturura[1];
            $tipoDoCampo = $esturura[2];

            if(strlen($campos[$nomeDoCampo]) > $tamanhoDoCampo){
                $campos[$nomeDoCampo] = substr($campos[$nomeDoCampo], 0, $tamanhoDoCampo);
            }

            if($tipoDoCampo == 'A'){
                $linha .= str_pad($campos[$nomeDoCampo], $tamanhoDoCampo, ' ', STR_PAD_RIGHT);
            }elseif($tipoDoCampo == 'D'){
                $linha .= str_pad($campos[$nomeDoCampo], $tamanhoDoCampo, '0', STR_PAD_LEFT);
            }elseif($tipoDoCampo == 'N'){
                $linha .= str_pad($campos[$nomeDoCampo], $tamanhoDoCampo, '0', STR_PAD_LEFT);
            }

        }

        return $linha;
    }

    public function criar_linha_cliente($campos) {

        $estrutura_campos = array(
            array('identificacao_do_cliente', 18,'A'),
            array('razao_social_do_cliente', 40,'A'),
            array('endereco_logradouro', 40,'A'),
            array('bairro', 30, 'A'),
            array('cep', 9, 'N'),
            array('cidade', 30, 'A'),
            array('estado', 30, 'A'),
            array('nome_do_responsavel', 20, 'A'),
            array('numeros_de_telefone', 40, 'A'),
            array('cnpj_cpf_do_cliente', 18, 'A'),
            array('rota', 10, 'A'),
            array('campo_reservado', 10, 'A'),
            array('tipo_de_loja', 10, 'A'),
            array('representatividade', 6, 'N'),
        );

        $linha = "D23637077000172";

        foreach ($estrutura_campos as $esturura){

            $nomeDoCampo = $esturura[0];
            $tamanhoDoCampo = $esturura[1];
            $tipoDoCampo = $esturura[2];

            if(strlen($campos[$nomeDoCampo]) > $tamanhoDoCampo){
                $campos[$nomeDoCampo] = substr($campos[$nomeDoCampo], 0, $tamanhoDoCampo);
            }

            if($tipoDoCampo == 'A'){
                $linha .= str_pad($campos[$nomeDoCampo], $tamanhoDoCampo, ' ', STR_PAD_RIGHT);
            }elseif($tipoDoCampo == 'D'){
                $linha .= str_pad($campos[$nomeDoCampo], $tamanhoDoCampo, '0', STR_PAD_LEFT);
            }elseif($tipoDoCampo == 'N'){
                $linha .= str_pad($campos[$nomeDoCampo], $tamanhoDoCampo, '0', STR_PAD_LEFT);
            }

        }

        return $linha;

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

    public function copiarArquivosAjustados()
    {


        File::copy(storage_path("edi_changed/{$this->nomeArquivoVendas}"), storage_path("/edi_diageo/emp_01_imp/{$this->nomeArquivoVendas}"));
        File::copy(storage_path("edi_changed/{$this->nomeArquivoClientes}"), storage_path("/edi_diageo/emp_01_imp/{$this->nomeArquivoClientes}"));
    }


    protected function clearTerminal($delay = 2)
    {
        sleep($delay);
        $this->output->write("\033[2J\033[;H");
    }

}
