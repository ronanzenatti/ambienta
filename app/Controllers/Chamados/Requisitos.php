<?php

namespace App\Controllers\Chamados;

use App\Libraries\Autorizacao;
use App\Libraries\Chamados\ValidadorRequisitos;
use App\Libraries\Patrimonio\Recusa as RecusaPatrimonio;
use App\Libraries\Patrimonio\SoftwaresPropostos;
use App\Models\Chamados\RequisitoDisciplinaModel;
use App\Models\Chamados\RequisitoItemModel;
use App\Models\Chamados\RequisitoSoftwareModel;
use App\Models\Patrimonio\CategoriaItemModel;
use App\Models\Patrimonio\SoftwareModel;
use App\Tabelas\Chamados\RequisitoItensTabela;
use App\Tabelas\Chamados\RequisitosTabela;
use App\Tabelas\Chamados\RequisitoSoftwaresTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Requisitos de disciplina por professor: professor, disciplina e capacidade mínima (formulário curto, em
 * modal), e na página do requisito os itens por categoria, os softwares e a verificação de um ambiente.
 */
class Requisitos extends ControladorChamados
{
    private const LISTA = 'requisitos';

    /** Teto das colunas SMALLINT UNSIGNED (capacidade mínima, quantidade). */
    private const MAXIMO = 'less_than_equal_to[65535]';

    protected const CHAVE = 'academico.requisitos';

    private RequisitoDisciplinaModel $requisitos;
    private RequisitoItemModel $itens;
    private RequisitoSoftwareModel $softwares;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->requisitos = new RequisitoDisciplinaModel();
        $this->itens      = new RequisitoItemModel();
        $this->softwares  = new RequisitoSoftwareModel();
    }

    /** Aqui o editor é o de academico.requisitos (a tela é do grupo Acadêmico), não o de chamados.acesso. */
    protected function ehEditor(): bool
    {
        return $this->autorizacao->pode(static::CHAVE, Autorizacao::EDITOR);
    }

    public function index(): string
    {
        $tabela = $this->tabela($this->novaTabela());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Chamados.requirementsTitle'),
            'intro'  => lang('Chamados.requirementsIntro'),
            'novo'   => $this->ehEditor() ? [lang('Chamados.requirementNew'), site_url(self::LISTA . '/novo')] : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->novaTabela());
    }

    public function novo(): ResponseInterface|string
    {
        return $this->formulario(['professor_id' => $this->usuarioId()]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dados = $this->dadosPostados();
        $erros = $this->validarRequisito($dados);

        if ($erros !== []) {
            return $this->formulario($dados, $erros, 422);
        }

        $id = $this->requisitos->insert($dados);

        // Recém-criado: vai para a página do requisito, onde se informam os itens e softwares exigidos.
        return $this->salvo(site_url(self::LISTA . "/{$id}"), lang('Chamados.requirementSaved'), true);
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        $requisito = $this->requisitos->completo($id);

        if ($requisito === null) {
            return $this->naoEncontrado();
        }

        return $this->pagina($requisito);
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$requisito, $recusa] = $this->carregarParaAlterar($id);

        return $recusa ?? $this->formulario($requisito);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        [, $recusa] = $this->carregarParaAlterar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = $this->dadosPostados();
        $erros = $this->validarRequisito($dados, $id);

        if ($erros !== []) {
            return $this->formulario($dados + ['id_requisito_disciplina' => $id], $erros, 422);
        }

        $this->requisitos->update($id, $dados);

        return $this->salvo(site_url(self::LISTA . "/{$id}"), lang('Chamados.requirementSaved'));
    }

    public function excluir(int $id): ResponseInterface|RedirectResponse
    {
        [, $recusa] = $this->carregarParaAlterar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url(self::LISTA . "/{$id}"))->with('erro', lang('Ui.confirmMismatch'));
        }

        $this->requisitos->excluirComDetalhes($id);

        return redirect()->to(site_url(self::LISTA))->with('sucesso', lang('Chamados.requirementDeleted'));
    }

    public function adicionarItem(int $id): ResponseInterface|RedirectResponse|string
    {
        [$requisito, $recusa] = $this->carregarParaAlterar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = ['categoria_item_id' => $this->texto('categoria_item_id'), 'quantidade' => $this->texto('quantidade')];
        $erros = $this->validar($dados, [
            'categoria_item_id' => $this->regra('Chamados.fieldItemCategory', 'required|is_natural_no_zero'),
            'quantidade'        => $this->regra(
                'Chamados.fieldQuantity',
                'required|is_natural_no_zero|' . self::MAXIMO
            ),
        ]);

        $categoria = (int) $dados['categoria_item_id'];

        if (! isset($erros['categoria_item_id']) && (new CategoriaItemModel())->find($categoria) === null) {
            $erros['categoria_item_id'] = lang('Chamados.categoryInvalid');
        }

        if ($erros !== []) {
            return $this->formularioDetalhe('item', $requisito, $erros, 422, $dados);
        }

        // Categoria já exigida: atualiza a quantidade (uma linha por categoria, como no schema).
        $existente = $this->itens->daCategoria($id, $categoria);

        if ($existente !== null) {
            $this->itens->update(
                (int) $existente['id_requisito_disciplina_item'],
                ['quantidade' => (int) $dados['quantidade']]
            );
        } else {
            $this->itens->insert([
                'requisito_disciplina_id' => $id,
                'categoria_item_id'       => $categoria,
                'quantidade'              => (int) $dados['quantidade'],
            ]);
        }

        return $this->salvo(
            site_url(self::LISTA . "/{$id}"),
            lang($existente === null ? 'Chamados.itemAdded' : 'Chamados.itemUpdated')
        );
    }

    /** Cartão de itens da página do requisito (fragmento da tabela; sem ?parcial=1 volta à página). */
    public function itens(int $id): ResponseInterface|RedirectResponse|string
    {
        return $this->fragmentoDetalhe($id, 'itens');
    }

    public function exportarItens(int $id): ResponseInterface|RedirectResponse
    {
        return $this->exportarDetalhe($id, 'itens');
    }

    public function softwares(int $id): ResponseInterface|RedirectResponse|string
    {
        return $this->fragmentoDetalhe($id, 'softwares');
    }

    public function exportarSoftwares(int $id): ResponseInterface|RedirectResponse
    {
        return $this->exportarDetalhe($id, 'softwares');
    }

    public function novoItem(int $id): ResponseInterface|RedirectResponse|string
    {
        [$requisito, $recusa] = $this->carregarParaAlterar($id);

        return $recusa ?? $this->formularioDetalhe('item', $requisito);
    }

    public function novoSoftware(int $id): ResponseInterface|RedirectResponse|string
    {
        [$requisito, $recusa] = $this->carregarParaAlterar($id);

        return $recusa ?? $this->formularioDetalhe('software', $requisito);
    }

    public function novaProposta(int $id): ResponseInterface|RedirectResponse|string
    {
        [$requisito, $recusa] = $this->carregarParaAlterar($id);

        return $recusa ?? $this->formularioDetalhe('proposta', $requisito);
    }

    /** Verificação de um ambiente (modal da página): só o resultado, que apenas sinaliza o que falta. */
    public function verificar(int $id): ResponseInterface|RedirectResponse|string
    {
        if ($this->requisitos->find($id) === null) {
            return $this->naoEncontrado();
        }

        $ambientes  = service('opcoes')->ambientes($this->escopo());
        $ambienteId = (int) $this->request->getGet('ambiente_id');

        if ($ambienteId === 0 || ! $ambientes->contem($ambienteId)) {
            return $this->response->setStatusCode(422)->setBody('');
        }

        return view('chamados/requisitos/_verificacao', [
            'ambiente'   => (string) $ambientes->rotuloDe($ambienteId),
            'pendencias' => (new ValidadorRequisitos(db_connect()))->verificarRequisito($id, $ambienteId),
        ]);
    }

    public function removerItem(int $id, int $itemId): ResponseInterface|RedirectResponse
    {
        return $this->remover($id, $itemId, $this->itens, 'Chamados.itemRemoved');
    }

    public function adicionarSoftware(int $id): ResponseInterface|RedirectResponse|string
    {
        [$requisito, $recusa] = $this->carregarParaAlterar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $softwareId = (int) $this->request->getPost('software_id');
        $erro       = null;

        if ((new SoftwareModel())->find($softwareId) === null) {
            $erro = lang('Chamados.softwareInvalid');
        } elseif ($this->softwares->vinculado($id, $softwareId)) {
            $erro = lang('Chamados.softwareAlreadyRequired');
        }

        if ($erro !== null) {
            return $this->formularioDetalhe('software', $requisito, ['software_id' => $erro], 422);
        }

        $this->softwares->insert(['requisito_disciplina_id' => $id, 'software_id' => $softwareId]);

        return $this->salvo(site_url(self::LISTA . "/{$id}"), lang('Chamados.softwareAdded'));
    }

    /** O professor propõe um software que não existe no catálogo: nasce pendente e já entra no requisito. */
    public function proporSoftware(int $id): ResponseInterface|RedirectResponse|string
    {
        [$requisito, $recusa] = $this->carregarParaAlterar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = [
            'nome'                => $this->texto('nome'),
            'versao'              => $this->textoOuNulo('versao'),
            'requisitos_tecnicos' => $this->textoOuNulo('requisitos_tecnicos'),
        ];
        $erros = $this->validar($dados, [
            'nome'                => $this->regra('Institucional.fieldName', 'required|max_length[255]'),
            'versao'              => $this->regra('Patrimonio.fieldVersion', 'permit_empty|max_length[50]'),
            'requisitos_tecnicos' => $this->regra('Patrimonio.fieldRequirements', 'permit_empty|max_length[5000]'),
        ]);

        if ($erros === []) {
            try {
                (new SoftwaresPropostos(db_connect()))->propor(
                    $this->usuarioId(),
                    $id,
                    $dados['nome'],
                    $dados['versao'],
                    $dados['requisitos_tecnicos']
                );
            } catch (RecusaPatrimonio $recusa) {
                $erros['nome'] = lang($recusa->getMessage());
            }
        }

        if ($erros !== []) {
            return $this->formularioDetalhe('proposta', $requisito, $erros, 422, $dados);
        }

        return $this->salvo(site_url(self::LISTA . "/{$id}"), lang('Chamados.softwareProposed'));
    }

    public function removerSoftware(int $id, int $vinculoId): ResponseInterface|RedirectResponse
    {
        return $this->remover($id, $vinculoId, $this->softwares, 'Chamados.softwareRemoved');
    }

    private function remover(
        int $id,
        int $detalheId,
        RequisitoItemModel|RequisitoSoftwareModel $modelo,
        string $mensagem
    ): ResponseInterface|RedirectResponse {
        [, $recusa] = $this->carregarParaAlterar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $pagina  = redirect()->to(site_url(self::LISTA . "/{$id}"));
        $detalhe = $modelo->find($detalheId);

        if ($detalhe === null || (int) $detalhe['requisito_disciplina_id'] !== $id) {
            return $pagina->with('erro', lang('Chamados.detailNotFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return $pagina->with('erro', lang('Ui.confirmMismatch'));
        }

        $modelo->delete($detalheId);

        return $pagina->with('sucesso', lang($mensagem));
    }

    private function novaTabela(): RequisitosTabela
    {
        return new RequisitosTabela($this->ehEditor(), $this->gereTodos(), $this->usuarioId());
    }

    /** Quem gere requisitos de outros professores (não só os próprios). */
    private function gereTodos(): bool
    {
        return $this->autorizacao->pode(self::RESOLVER, Autorizacao::EDITOR);
    }

    /** @param array<string, mixed> $requisito */
    private function podeAlterar(array $requisito): bool
    {
        return $this->ehEditor() && ($this->gereTodos() || (int) $requisito['professor_id'] === $this->usuarioId());
    }

    /**
     * @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null}
     */
    private function carregarParaAlterar(int $id): array
    {
        $requisito = $this->requisitos->find($id);

        if ($requisito === null) {
            return [[], $this->naoEncontrado()];
        }

        return [$requisito, $this->podeAlterar($requisito) ? null : $this->negar()];
    }

    private function naoEncontrado(): RedirectResponse
    {
        return redirect()->to(site_url(self::LISTA))->with('erro', lang('Chamados.requirementNotFound'));
    }

    /** @return array{professor_id: int, disciplina_id: int, capacidade_minima: string} */
    private function dadosPostados(): array
    {
        return [
            // Sem permissão para gerir os de outros, o requisito é sempre do próprio usuário.
            'professor_id'      => $this->gereTodos()
                ? (int) $this->request->getPost('professor_id')
                : $this->usuarioId(),
            'disciplina_id'     => (int) $this->request->getPost('disciplina_id'),
            'capacidade_minima' => $this->texto('capacidade_minima') === '' ? '0' : $this->texto('capacidade_minima'),
        ];
    }

    /**
     * @param array{professor_id: int, disciplina_id: int, capacidade_minima: string} $dados
     *
     * @return array<string, string>
     */
    private function validarRequisito(array $dados, ?int $ignorarId = null): array
    {
        $erros = $this->validar($dados, [
            'capacidade_minima' => $this->regra('Chamados.fieldMinCapacity', 'required|is_natural|' . self::MAXIMO),
        ]);

        if (! service('opcoes')->usuariosAtivos()->contem($dados['professor_id'])) {
            $erros['professor_id'] = lang('Chamados.teacherInvalid');
        }

        if (! service('opcoes')->disciplinasDoProfessor($dados['professor_id'])->contem($dados['disciplina_id'])) {
            $erros['disciplina_id'] = lang('Chamados.disciplineInvalid');
        } elseif (
            ! isset($erros['professor_id'])
            && $this->requisitos->existe($dados['professor_id'], $dados['disciplina_id'], $ignorarId)
        ) {
            $erros['disciplina_id'] = lang('Chamados.requirementDuplicate');
        }

        return $erros;
    }

    /**
     * @param array<string, mixed>  $requisito
     * @param array<string, string> $erros
     */
    private function formulario(array $requisito, array $erros = [], int $status = 200): ResponseInterface|string
    {
        return $this->telaFormulario('chamados/requisitos', [
            'requisito'   => $requisito,
            'gereTodos'   => $this->gereTodos(),
            'professorNome' => (string) service('opcoes')->usuariosAtivos()
                ->rotuloDe((int) ($requisito['professor_id'] ?? 0)),
        ], $erros, $status);
    }

    private function fragmentoDetalhe(int $id, string $tipo): ResponseInterface|RedirectResponse|string
    {
        $tabela = $this->tabelaDetalhe($id, $tipo);

        if ($tabela === null) {
            return $this->naoEncontrado();
        }

        return $this->pedidoParcial()
            ? $this->tabela($tabela)->fragmento()
            : redirect()->to(site_url(self::LISTA . "/{$id}"));
    }

    private function exportarDetalhe(int $id, string $tipo): ResponseInterface|RedirectResponse
    {
        $tabela = $this->tabelaDetalhe($id, $tipo);

        return $tabela === null ? $this->naoEncontrado() : $this->exportarTabela($tabela);
    }

    private function tabelaDetalhe(int $id, string $tipo): RequisitoItensTabela|RequisitoSoftwaresTabela|null
    {
        $requisito = $this->requisitos->completo($id);

        if ($requisito === null) {
            return null;
        }

        $nome = $requisito['sigla'] . ' · ' . $requisito['disciplina'];

        return $tipo === 'itens'
            ? new RequisitoItensTabela($id, $nome, $this->podeAlterar($requisito))
            : new RequisitoSoftwaresTabela($id, $nome, $this->podeAlterar($requisito));
    }

    /**
     * Formulário de adicionar item, adicionar software ou propor software novo (modal da página do requisito).
     *
     * @param array<string, mixed>  $requisito
     * @param array<string, string> $erros
     * @param array<string, mixed>  $dados
     */
    private function formularioDetalhe(
        string $tipo,
        array $requisito,
        array $erros = [],
        int $status = 200,
        array $dados = []
    ): ResponseInterface|string {
        return $this->telaFormulario('chamados/requisitos/detalhe', [
            'tipo'      => $tipo,
            'requisito' => $this->requisitos->completo((int) $requisito['id_requisito_disciplina']) ?? $requisito,
            'dados'     => $dados,
        ], $erros, $status);
    }

    /** @param array<string, mixed> $requisito com professor e disciplina (completo()) */
    private function pagina(array $requisito): string
    {
        $id     = (int) $requisito['id_requisito_disciplina'];
        $nome   = $requisito['sigla'] . ' · ' . $requisito['disciplina'];
        $altera = $this->podeAlterar($requisito);

        return view('chamados/requisitos/ver', [
            'requisito'       => $requisito,
            'tabelaItens'     => (new RequisitoItensTabela($id, $nome, $altera))->comRequisicao($this->request),
            'tabelaSoftwares' => (new RequisitoSoftwaresTabela($id, $nome, $altera))->comRequisicao($this->request),
            'temAmbientes'    => service('opcoes')->ambientes($this->escopo())->existe(),
            'podeAlterar'     => $altera,
        ]);
    }
}
