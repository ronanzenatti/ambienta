<?php

namespace App\Controllers\Patrimonio;

use App\Models\Patrimonio\SoftwareModel;
use App\Tabelas\Patrimonio\SoftwaresTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Softwares (catálogo global, com requisitos técnicos). O vínculo com os ambientes onde estão instalados fica
 * em AmbienteConteudo (página do ambiente).
 */
class Softwares extends ControladorPatrimonio
{
    private const LISTA = 'patrimonio/softwares';

    private SoftwareModel $softwares;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->softwares = new SoftwareModel();
    }

    public function index(): string
    {
        $tabela = $this->tabela(new SoftwaresTabela($this->podeEditarCatalogo()));

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Patrimonio.softwaresTitle'),
            'intro'  => lang('Patrimonio.softwaresIntro'),
            'novo'   => $this->podeEditarCatalogo()
                ? [lang('Patrimonio.softwareNew'), site_url(self::LISTA . '/novo')]
                : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new SoftwaresTabela($this->podeEditarCatalogo()));
    }

    public function novo(): ResponseInterface|string
    {
        return $this->telaFormulario(self::LISTA, ['software' => []]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dados = $this->dadosPostados();
        $erros = $this->validarSoftware($dados, null);

        if ($erros !== []) {
            return $this->telaFormulario(self::LISTA, ['software' => $dados], $erros, 422);
        }

        $this->softwares->insert($dados);

        return $this->salvo(site_url(self::LISTA), lang('Patrimonio.softwareSaved'));
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        $software = $this->softwares->find($id);

        if ($software === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.softwareNotFound'));
        }

        return $this->telaFormulario(self::LISTA, ['software' => $software]);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        if ($this->softwares->find($id) === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.softwareNotFound'));
        }

        $dados = $this->dadosPostados();
        $erros = $this->validarSoftware($dados, $id);

        if ($erros !== []) {
            return $this->telaFormulario(self::LISTA, ['software' => $dados + ['id_software' => $id]], $erros, 422);
        }

        $this->softwares->update($id, $dados);

        return $this->salvo(site_url(self::LISTA), lang('Patrimonio.softwareSaved'));
    }

    public function excluir(int $id): RedirectResponse
    {
        $lista = redirect()->to(site_url(self::LISTA));

        if ($this->softwares->find($id) === null) {
            return $lista->with('erro', lang('Patrimonio.softwareNotFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return $lista->with('erro', lang('Ui.confirmMismatch'));
        }

        $dependentes = $this->softwares->dependentes($id);

        if ($dependentes !== []) {
            return $lista->with('erro', $this->mensagemDependentes($dependentes));
        }

        $this->softwares->delete($id);

        return $lista->with('sucesso', lang('Patrimonio.softwareDeleted'));
    }

    /** @return array{nome: string, versao: ?string, requisitos_tecnicos: ?string} */
    private function dadosPostados(): array
    {
        return [
            'nome'                => $this->texto('nome'),
            'versao'              => $this->textoOuNulo('versao'),
            'requisitos_tecnicos' => $this->textoOuNulo('requisitos_tecnicos'),
        ];
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, string>
     */
    private function validarSoftware(array $dados, ?int $id): array
    {
        $erros = $this->validar($dados, [
            'nome'                => $this->regra('Institucional.fieldName', 'required|max_length[255]'),
            'versao'              => $this->regra('Patrimonio.fieldVersion', 'permit_empty|max_length[50]'),
            'requisitos_tecnicos' => $this->regra('Patrimonio.fieldRequirements', 'permit_empty|max_length[5000]'),
        ]);

        $versao = $dados['versao'] === null ? null : (string) $dados['versao'];

        if (! isset($erros['nome']) && $this->softwares->nomeVersaoEmUso((string) $dados['nome'], $versao, $id)) {
            $erros['nome'] = lang('Patrimonio.softwareInUse');
        }

        return $erros;
    }
}
