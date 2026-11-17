<?php

namespace App\Controllers\Institucional;

use App\Libraries\Arquivos;
use App\Libraries\Autorizacao;
use App\Models\Institucional\AmbienteModel;
use App\Models\Institucional\CampusModel;
use App\Models\Institucional\CategoriaAmbienteModel;
use App\Models\Institucional\PredioModel;
use App\Tabelas\Chamados\ChamadosTabela;
use App\Tabelas\Patrimonio\AmbienteItensTabela;
use App\Tabelas\Institucional\TodosAmbientesTabela;
use App\Tabelas\Patrimonio\AmbienteSoftwaresTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Ambientes de um prédio. A exigência de confirmação de reserva tem três estados: segue o campus
 * (NULL), exige (1) ou não exige (0), como no schema.
 */
class Ambientes extends ControladorInstitucional
{
    private const ARQUIVOS = ['foto' => Arquivos::IMAGEM];

    /** Valores do campo exige_confirmacao_reserva no formulário ('' = segue o campus). */
    public const CONFIRMACAO = ['', '1', '0'];

    private AmbienteModel $ambientes;
    private PredioModel $predios;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->ambientes = new AmbienteModel();
        $this->predios   = new PredioModel();
    }

    /** Listagem geral de ambientes (item do menu), com filtros de campus e prédio; a navegação continua. */
    public function index(): string
    {
        [$campusId, $predioId] = $this->filtrosCampusPredio(true);
        $tabela                = $this->tabela($this->tabelaTodos($campusId, $predioId));
        $predio                = $predioId === null ? null : $this->predios->find($predioId);
        $editor                = $this->autorizacao->escopoPara(static::CHAVE, Autorizacao::EDITOR);

        return $this->pedidoParcial() ? $tabela->fragmento() : view('institucional/ambientes/index', [
            'tabela'   => $tabela,
            'campusId' => $campusId,
            'predioId' => $predioId,
            // Novo ambiente: direto no prédio filtrado; sem filtro (ou sem edição nele), o modal pergunta o prédio.
            'podeNovo'  => $predio !== null && $this->podeEditarPredio($predio),
            'podeCriar' => $editor['global'] || $editor['campus'] !== [] || $editor['predio'] !== [],
        ]);
    }

    /** Segue do modal "Novo ambiente" para o formulário do prédio escolhido (ele confere a permissão). */
    public function escolherPredio(): RedirectResponse
    {
        $predio = trim((string) $this->request->getGet('escolha_predio'));

        if (! ctype_digit($predio)) {
            return redirect()->to(site_url('ambientes'))->with('erro', lang('Ambientes.chooseBuildingError'));
        }

        return redirect()->to(site_url("predios/{$predio}/ambientes/novo"));
    }

    public function exportar(): ResponseInterface
    {
        [$campusId, $predioId] = $this->filtrosCampusPredio(true);

        return $this->exportarTabela($this->tabelaTodos($campusId, $predioId));
    }

    private function tabelaTodos(?int $campusId, ?int $predioId): TodosAmbientesTabela
    {
        return new TodosAmbientesTabela(
            $this->autorizacao->escopoPara(static::CHAVE),
            $campusId,
            $predioId,
            fn (array $predio): bool => $this->podeEditarPredio($predio),
            $this->podeEditarCatalogo()
        );
    }

    public function novo(int $predioId): ResponseInterface|RedirectResponse|string
    {
        $predio = $this->predios->find($predioId);

        if ($predio === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Predios.notFound'));
        }

        if (! $this->podeEditarPredio($predio)) {
            return $this->negar();
        }

        return $this->formulario($predio, ['predio_id' => $predioId, 'ativo' => 1, 'capacidade' => 0]);
    }

    public function criar(int $predioId): ResponseInterface|RedirectResponse|string
    {
        $predio = $this->predios->find($predioId);

        if ($predio === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Predios.notFound'));
        }

        if (! $this->podeEditarPredio($predio)) {
            return $this->negar();
        }

        [$dados, $erros] = $this->dadosPostados([]);
        $dados['predio_id'] = $predioId;

        if ($erros !== []) {
            return $this->formulario($predio, $dados, $erros, 422);
        }

        $id = $this->ambientes->insert($dados);

        return redirect()->to(site_url("ambientes/{$id}"))->with('sucesso', lang('Ambientes.saved'));
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        $ambiente = $this->ambientes->find($id);
        $predio   = $ambiente === null ? null : $this->predios->find((int) $ambiente['predio_id']);

        if ($ambiente === null || $predio === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Ambientes.notFound'));
        }

        if (! $this->podeVerPredio($predio)) {
            return $this->negar();
        }

        $categoria = (new CategoriaAmbienteModel())->withDeleted()->find((int) $ambiente['categoria_ambiente_id']);

        // Softwares e itens são patrimônio: ver os itens exige leitura, e adicionar/remover exige editor de
        // patrimônio no prédio. Cada tabela é independente da URL da página (ver Tabela::independente).
        $patrimonio = static fn (string $nivel): bool => service('autorizacao')->pode(
            'patrimonio.acesso',
            $nivel,
            (int) $predio['campus_id'],
            (int) $predio['id_predio']
        );
        $podeEditarPatrimonio = $patrimonio(Autorizacao::EDITOR);
        $chamados             = static fn (string $nivel): bool => service('autorizacao')->pode(
            'chamados.acesso',
            $nivel,
            (int) $predio['campus_id'],
            (int) $predio['id_predio']
        );

        return view('institucional/ambientes/ver', [
            'ambiente'             => $ambiente,
            'categoria'            => $categoria,
            'predio'               => $predio,
            'campus'               => (new CampusModel())->find((int) $predio['campus_id']),
            'podeEditar'           => $this->podeEditarPredio($predio),
            'podeEditarPatrimonio' => $podeEditarPatrimonio,
            'tabelaSoftwares'      => new AmbienteSoftwaresTabela(
                $id,
                (string) $ambiente['nome'],
                $podeEditarPatrimonio
            ),
            'podeAbrirChamado'     => $chamados(Autorizacao::EDITOR),
            'tabelaChamados'       => $chamados(Autorizacao::LEITOR)
                ? new ChamadosTabela(
                    $this->autorizacao->escopoPara('chamados.acesso'),
                    (int) (service('autenticacao')->usuario()['id_usuario'] ?? 0),
                    $id
                )
                : null,
            'tabelaItens'          => $patrimonio(Autorizacao::LEITOR)
                ? new AmbienteItensTabela($id, $podeEditarPatrimonio)
                : null,
        ]);
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$ambiente, $predio, $recusa] = $this->carregarParaEditar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        return $this->formulario($predio, $ambiente);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$ambiente, $predio, $recusa] = $this->carregarParaEditar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        [$dados, $erros, $antigos] = $this->dadosPostados($ambiente);

        if ($erros !== []) {
            $dados += ['id_ambiente' => $id, 'predio_id' => $ambiente['predio_id']];

            return $this->formulario($predio, $dados, $erros, 422);
        }

        $this->ambientes->update($id, $dados);
        $this->apagarArquivos($antigos);

        return redirect()->to(site_url("ambientes/{$id}"))->with('sucesso', lang('Ambientes.saved'));
    }

    public function excluir(int $id): ResponseInterface|RedirectResponse
    {
        [$ambiente, , $recusa] = $this->carregarParaEditar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url("ambientes/{$id}"))->with('erro', lang('Ui.confirmMismatch'));
        }

        $dependentes = $this->ambientes->dependentes($id);

        if ($dependentes !== []) {
            return redirect()->to(site_url("ambientes/{$id}"))->with('erro', $this->mensagemDependentes($dependentes));
        }

        $this->ambientes->delete($id);

        return redirect()->to(site_url('predios/' . $ambiente['predio_id']))
            ->with('sucesso', lang('Ambientes.deleted'));
    }

    /**
     * Ambiente e prédio para alterar/excluir, e a recusa (redirecionamento ou 403), se houver.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: ?ResponseInterface}
     */
    private function carregarParaEditar(int $id): array
    {
        $ambiente = $this->ambientes->find($id);
        $predio   = $ambiente === null ? null : $this->predios->find((int) $ambiente['predio_id']);

        if ($ambiente === null || $predio === null) {
            return [[], [], redirect()->to(site_url('campus'))->with('erro', lang('Ambientes.notFound'))];
        }

        return [$ambiente, $predio, $this->podeEditarPredio($predio) ? null : $this->negar()];
    }

    /**
     * @param array<string, mixed> $atual
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: list<?string>}
     */
    private function dadosPostados(array $atual): array
    {
        $confirmacao = (string) $this->request->getPost('exige_confirmacao_reserva');

        $dados = [
            'categoria_ambiente_id'     => $this->texto('categoria_ambiente_id'),
            'numero'                    => $this->texto('numero'),
            'abreviacao'                => $this->texto('abreviacao'),
            'nome'                      => $this->texto('nome'),
            'descricao'                 => $this->textoOuNulo('descricao'),
            'capacidade'                => $this->texto('capacidade'),
            'tamanho_m2'                => $this->textoOuNulo('tamanho_m2'),
            'exige_confirmacao_reserva' => $confirmacao,
            'ativo'                     => $this->request->getPost('ativo') === '1' ? 1 : 0,
        ];

        $erros = $this->validar($dados, [
            'categoria_ambiente_id'     => $this->regra('Ambientes.fieldCategory', 'required|is_natural_no_zero'),
            'numero'                    => $this->regra('Institucional.fieldNumber', 'required|max_length[20]'),
            'abreviacao'                => $this->regra('Institucional.fieldAbbreviation', 'required|max_length[20]'),
            'nome'                      => $this->regra('Institucional.fieldName', 'required|max_length[255]'),
            'descricao'                 => $this->regra(
                'Institucional.fieldDescription',
                'permit_empty|max_length[5000]'
            ),
            'capacidade'                => $this->regra(
                'Ambientes.fieldCapacity',
                'required|is_natural|less_than_equal_to[65535]'
            ),
            'tamanho_m2'                => $this->regra(
                'Ambientes.fieldSize',
                'permit_empty|decimal|greater_than[0]|less_than_equal_to[9999.99]'
            ),
            'exige_confirmacao_reserva' => $this->regra('Ambientes.fieldConfirmation', 'permit_empty|in_list[1,0]'),
        ]);

        $categoriaExiste = (new CategoriaAmbienteModel())->find((int) $dados['categoria_ambiente_id']) !== null;

        if (! isset($erros['categoria_ambiente_id']) && ! $categoriaExiste) {
            $erros['categoria_ambiente_id'] = lang('Ambientes.categoryInvalid');
        }

        $dados['exige_confirmacao_reserva'] = $confirmacao === '' ? null : (int) $confirmacao;
        $dados['foto'] = $atual['foto'] ?? null;

        if ($erros !== []) {
            return [$dados, $erros, []];
        }

        [$arquivos, $antigos] = $this->arquivosPostados(self::ARQUIVOS, 'ambientes', $atual, $erros);

        return [$arquivos + $dados, $erros, $antigos];
    }

    /**
     * @param array<string, mixed>  $predio
     * @param array<string, mixed>  $ambiente
     * @param array<string, string> $erros
     */
    private function formulario(
        array $predio,
        array $ambiente,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        return $this->responder('institucional/ambientes/form', [
            'predio'        => $predio,
            'campus'        => (new CampusModel())->find((int) $predio['campus_id']),
            'ambiente'      => $ambiente,
            'temCategorias' => service('opcoes')->simples('categorias_ambientes', 'id_categoria_ambiente')->existe(),
        ], $erros, $status);
    }
}
