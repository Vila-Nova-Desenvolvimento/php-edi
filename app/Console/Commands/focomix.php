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

class focomix extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'edi:focomix';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Processa os arquivos EDI da Focomix.';

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

        $this->info('Processando arquivos EDI da Facomix...');
        $this->clearTerminal(2);

        $this->info('Copiando arquivos...');

        // Copia os arquivos para uma pasta intermediária
        $this->copiarArquivos();

        $this->clearTerminal(0);
        $this->info('Precessando vendas ...');


        // Le o arquivo e grava na tabela vendas
        $this->importarVendas();

        $this->clearTerminal(0);
        $this->info('Pulverizando vendas...');

        // Pulveriza a venda entre novos clientes
        $this->pulverizarVendas();

        $this->clearTerminal(0);
        $this->info('Diponibilizando os arquivos...');

        $this->copiarArquivosAjustados();

        $this->clearTerminal(0);
        $this->info('Edi criado com sucesso!');

    }

    public function copiarArquivos()
    {

        // Escaneie a pasta /storage/edi_diageo e pegue todos os arquivos
        $files = File::allFiles(storage_path('/edi_diageo/emp_501'));


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

            // Obter a quantidade do produto como um número inteiro
            $quantidade = (int) explode('.', $linha['quantidade'])[0];

            $quantidade = $quantidade * 2;

            if($quantidade < 0){
                $linha['quantidade'] = "-" . str_pad(str_replace('-','',$quantidade), 14, '0', STR_PAD_LEFT) . '.0000';
            }else{
                $linha['quantidade'] = str_pad($quantidade, 15, '0', STR_PAD_LEFT) . '.0000';
            }



            if(!empty($linha['cnpj'])){
                $linha = self::criarLinhaProdutos($linha);
                File::append(storage_path("edi_changed/{$this->nomeArquivoVendas}"), $linha . "\n");

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

    public function copiarArquivosAjustados()
    {


        File::copy(storage_path("edi_changed/{$this->nomeArquivoVendas}"), storage_path("/edi_diageo/emp_501_imp/{$this->nomeArquivoVendas}"));
        //File::copy(storage_path("edi_changed/{$this->nomeArquivoClientes}"), storage_path("/edi_diageo/emp_501_imp/{$this->nomeArquivoClientes}"));
    }

    protected function clearTerminal($delay = 2)
    {
        sleep($delay);
        $this->output->write("\033[2J\033[;H");
    }

}
