<?php

namespace App\Controllers\Patrimonio;

use App\Libraries\Autorizacao;
use App\Libraries\Opcoes\Fonte;
use App\Models\Institucional\CampusModel;
use App\Models\Patrimonio\AlmoxarifadoModel;
use App\Tabelas\Patrimonio\AlmoxarifadosTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Almoxarifados: um por campus (o banco garante com campus_unico). Criar, renomear e excluir exigem
 * editor de patrimônio no campus; o campus não muda depois de criado.
 */
class Almoxarifados extends ControladorPatrimonio
{
    private const LISTA = 'patrimonio/almoxarifados';

    private AlmoxarifadoModel $almoxarifados;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->almoxarifados = new AlmoxarifadoModel();
    }

    public function index(): string
    {
        $tabela = $this->tabela($this->novaTabela());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Patrimonio.warehousesTitle'),
            'intro'  => lang('Patrimonio.warehousesIntro'),
            'novo'   => $this->campiSemAlmoxarifado()->existe()
                ? [lang('Patrimonio.warehouseNew'), site_url(self::LISTA . '/novo')]
                : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->novaTabela());
    }

    public function novo(): ResponseInterface|string
    {
        if (! $this->campiSemAlmoxarifado()->existe()) {
            return $this->negar();
        }

        return $this->formulario(['nome' => lang('Patrimonio.warehouseDefaultName')]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dados = ['campus_id' => $this->texto('campus_id'), 'nome' => $this->texto('nome')];
        $erros = $this->validar($dados, [
            'campus_id' => $this->regra('Patrimonio.fieldCampus', 'required|is_natural_no_zero'),
            'nome'      => $this->regra('Institucional.fieldName', 'required|max_length[255]'),
        ]);

        if (! isset($erros['campus_id']) && ! $this->campiSemAlmoxarifado()->contem((int) $dados['campus_id'])) {
            $erros['campus_id'] = lang('Patrimonio.warehouseCampusInvalid');
        }

        if ($erros !== []) {
            return $this->formulario($dados, $erros, 422);
        }

        $this->almoxarifados->insert($dados);

        return $this->salvo(site_url(self::LISTA), lang('Patrimonio.warehouseSaved'));
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$almoxarifado, $recusa] = $this->carregar($id);

        return $recusa ?? $this->formulario($almoxarifado);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$almoxarifado, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = ['nome' => $this->texto('nome')];
        $erros = $this->validar($dados, [
            'nome' => $this->regra('Institucional.fieldName', 'required|max_length[255]'),
        ]);

        if ($erros !== []) {
            return $this->formulario($dados + $almoxarifado, $erros, 422);
        }

        $this->almoxarifados->update($id, $dados);

        return $this->salvo(site_url(self::LISTA), lang('Patrimonio.warehouseSaved'));
    }

    public function excluir(int $id): ResponseInterface|RedirectResponse
    {
        [, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $lista = redirect()->to(site_url(self::LISTA));

        if (! $this->exclusaoConfirmada()) {
            return $lista->with('erro', lang('Ui.confirmMismatch'));
        }

        $dependentes = $this->almoxarifados->dependentes($id);

        if ($dependentes !== []) {
            return $lista->with('erro', $this->mensagemDependentes($dependentes));
        }

        $this->almoxarifados->delete($id);

        return $lista->with('sucesso', lang('Patrimonio.warehouseDeleted'));
    }

    private function novaTabela(): AlmoxarifadosTabela
    {
        return new AlmoxarifadosTabela($this->escopo(), $this->escopo(Autorizacao::EDITOR));
    }

    /**
     * Almoxarifado para alterar/excluir, e a recusa (não encontrado ou fora do escopo), se houver.
     *
     * @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null}
     */
    private function carregar(int $id): array
    {
        $almoxarifado = $this->almoxarifados->find($id);

        if ($almoxarifado === null) {
            return [[], redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.warehouseNotFound'))];
        }

        $pode = $this->autorizacao->pode(self::CHAVE, Autorizacao::EDITOR, (int) $almoxarifado['campus_id']);

        return [$almoxarifado, $pode ? null : $this->negar()];
    }

    /** Campi em que o usuário é editor e que ainda não têm almoxarifado: a mesma fonte do select. */
    private function campiSemAlmoxarifado(): Fonte
    {
        return service('opcoes')->campi($this->escopo(Autorizacao::EDITOR), false, true);
    }

    /**
     * @param array<string, mixed>  $almoxarifado
     * @param array<string, string> $erros
     */
    private function formulario(array $almoxarifado, array $erros = [], int $status = 200): ResponseInterface|string
    {
        helper('institucional');

        $campus = isset($almoxarifado['id_almoxarifado'])
            ? (new CampusModel())->withDeleted()->find((int) $almoxarifado['campus_id'])
            : null;

        return $this->telaFormulario(self::LISTA, [
            'almoxarifado' => $almoxarifado,
            'campusNome'   => $campus === null ? '' : campus_rotulo($campus),
        ], $erros, $status);
    }
}
