<?php

namespace App\Controllers\Patrimonio;

use App\Libraries\Autorizacao;
use App\Libraries\Patrimonio\ImportadorNovosItens;
use App\Libraries\Patrimonio\ImportadorNumeros;
use App\Libraries\Tabela\Abrangencia;
use App\Libraries\Tabela\Exportador;
use App\Models\Patrimonio\ModeloItemModel;
use App\Tabelas\Patrimonio\ItensSemNumeroTabela;
use App\Tabelas\Patrimonio\ModeloEmBrancoTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Patrimonio;

/**
 * Números de patrimônio em massa, de duas formas:
 * - itens já lançados e sem número (opcionalmente de um modelo): a listagem exporta a planilha de preenchimento e a
 *   mesma planilha, preenchida, volta pela importação e atualiza os itens;
 * - itens novos de um modelo: escolhe-se modelo, quantidade e local, baixa-se a planilha em branco com essas
 *   linhas e, preenchida, ela volta pela importação, que cria um item numerado por linha.
 */
class Numeros extends ControladorPatrimonio
{
    private const LISTA = 'patrimonio/numeros';

    /** Planilhas maiores que isso não são lidas (hospedagem compartilhada). */
    private const TAMANHO_MAXIMO = 5 * 1024 * 1024;

    public function index(): string
    {
        $tabela = $this->tabela($this->tabelaPendentes());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Patrimonio.numbersTitle'),
            'intro'  => lang('Patrimonio.numbersIntro'),
            'antes'  => view('patrimonio/numeros/_importar', [
                'resultado'  => session()->getFlashdata('importacao'),
                'modelo'     => $this->modeloDoFiltro(),
                'limite'     => config(Patrimonio::class)->limiteReplicacao,
                'erros'      => (array) session()->getFlashdata('erros'),
                'valores'    => (array) session()->getFlashdata('valores'),
            ], ['saveData' => false]),
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->tabelaPendentes());
    }

    /** Planilha em branco de itens novos de um modelo: X linhas com o modelo e o local escolhidos. */
    public function planilhaModelo(): ResponseInterface|RedirectResponse
    {
        $limite = config(Patrimonio::class)->limiteReplicacao;
        $dados  = [
            'modelo'     => (string) $this->request->getGet('modelo_novo'),
            'quantidade' => (string) $this->request->getGet('quantidade'),
            'local'      => (string) $this->request->getGet('local'),
        ];
        $erros  = $this->validar($dados, [
            'modelo'     => $this->regra('Patrimonio.fieldModel', 'required|is_natural_no_zero'),
            'quantidade' => $this->regra(
                'Patrimonio.fieldQuantity',
                "required|is_natural_no_zero|less_than_equal_to[{$limite}]"
            ),
            'local'      => $this->regra('Patrimonio.fieldLocation', 'required'),
        ]);

        $modelo = isset($erros['modelo']) ? null : (new ModeloItemModel())->find((int) $dados['modelo']);
        $local  = $this->locais->interpretar($dados['local']);

        if (! isset($erros['modelo']) && ($modelo === null || ! (bool) $modelo['patrimoniavel'])) {
            $erros['modelo'] = lang('Patrimonio.importModelNotAsset');
        }

        if (! isset($erros['local']) && ! $this->podeNoLocal($local, Autorizacao::EDITOR)) {
            $erros['local'] = lang('Patrimonio.locationInvalid');
        }

        if ($erros !== [] || $modelo === null || $local === null) {
            return redirect()->to(site_url(self::LISTA))->with('erros', $erros)->with('valores', $dados);
        }

        return (new Exportador(new ModeloEmBrancoTabela(
            (int) $modelo['id_modelo_item'],
            (string) $modelo['nome'],
            (int) $dados['quantidade'],
            $dados['local'],
            $this->locais->rotulo($local['ambiente_id'], $local['almoxarifado_id'])
        )))->excel(Abrangencia::Tudo);
    }

    /** Atualiza os itens já lançados com os números da planilha. */
    public function importar(): RedirectResponse
    {
        return $this->importarPlanilha(
            'planilha',
            fn (string $arquivo, callable $podeEditar): ?array => (new ImportadorNumeros(db_connect()))
                ->importar($arquivo, $podeEditar)
        );
    }

    /** Cria um item numerado por linha preenchida da planilha de itens novos de um modelo. */
    public function importarNovos(): RedirectResponse
    {
        return $this->importarPlanilha(
            'planilha_novos',
            fn (string $arquivo, callable $podeEditar): ?array => (new ImportadorNovosItens(db_connect()))
                ->importar($arquivo, $podeEditar)
        );
    }

    /**
     * @param callable(string, callable(array<string, mixed>): bool): ?array<string, mixed> $importar
     */
    private function importarPlanilha(string $campo, callable $importar): RedirectResponse
    {
        $lista   = redirect()->to(site_url(self::LISTA));
        $arquivo = $this->request->getFile($campo);

        if ($arquivo === null || ! $arquivo->isValid() || $arquivo->getSize() > self::TAMANHO_MAXIMO) {
            return $lista->with('erro', lang('Patrimonio.importFileInvalid'));
        }

        $resultado = $importar(
            $arquivo->getTempName(),
            fn (array $registro): bool => $this->podeNoLocal($this->localDe($registro), Autorizacao::EDITOR)
        );

        if ($resultado === null) {
            return $lista->with('erro', lang('Patrimonio.importFileInvalid'));
        }

        return $lista->with('importacao', $resultado);
    }

    private function tabelaPendentes(): ItensSemNumeroTabela
    {
        $modelo = $this->modeloDoFiltro();

        return new ItensSemNumeroTabela(
            $this->escopo(Autorizacao::EDITOR),
            $modelo === null ? null : (int) $modelo['id_modelo_item']
        );
    }

    /**
     * Modelo do filtro da listagem (?modelo=), se existir.
     *
     * @return array<string, mixed>|null
     */
    private function modeloDoFiltro(): ?array
    {
        $valor = (string) $this->request->getGet('modelo');

        return ctype_digit($valor) && $valor !== '0' ? (new ModeloItemModel())->find((int) $valor) : null;
    }
}
