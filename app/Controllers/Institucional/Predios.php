<?php

namespace App\Controllers\Institucional;

use App\Libraries\Arquivos;
use App\Libraries\Autorizacao;
use App\Models\Institucional\AmbienteModel;
use App\Models\Institucional\CampusModel;
use App\Models\Institucional\PredioModel;
use App\Tabelas\Institucional\AmbientesTabela;
use App\Tabelas\Institucional\TodosPrediosTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Prédios de um campus: mesma estrutura de endereço e coordenadas do campus, mais foto.
 */
class Predios extends ControladorInstitucional
{
    private const ARQUIVOS = ['foto' => Arquivos::IMAGEM, 'mapa' => Arquivos::MAPA];

    private PredioModel $predios;
    private CampusModel $campi;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->predios = new PredioModel();
        $this->campi   = new CampusModel();
    }

    /** Listagem geral de prédios (item do menu), com filtro por campus; a navegação pelo campus continua. */
    public function index(): string
    {
        [$campusId] = $this->filtrosCampusPredio(false);
        $tabela     = $this->tabela($this->tabelaTodos($campusId));
        $editor     = $this->autorizacao->escopoPara(static::CHAVE, Autorizacao::EDITOR);

        return $this->pedidoParcial() ? $tabela->fragmento() : view('institucional/predios/index', [
            'tabela'   => $tabela,
            'campusId' => $campusId,
            // Novo prédio: direto no campus filtrado; sem filtro (ou sem edição nele), o modal pergunta o campus.
            'podeNovo'  => $campusId !== null && $this->podeEditarCampus($campusId),
            'podeCriar' => $editor['global'] || $editor['campus'] !== [],
        ]);
    }

    /** Segue do modal "Novo prédio" para o formulário do campus escolhido (ele confere a permissão). */
    public function escolherCampus(): RedirectResponse
    {
        $campus = trim((string) $this->request->getGet('escolha_campus'));

        if (! ctype_digit($campus)) {
            return redirect()->to(site_url('predios'))->with('erro', lang('Predios.chooseCampusError'));
        }

        return redirect()->to(site_url("campus/{$campus}/predios/novo"));
    }

    public function exportar(): ResponseInterface
    {
        [$campusId] = $this->filtrosCampusPredio(false);

        return $this->exportarTabela($this->tabelaTodos($campusId));
    }

    private function tabelaTodos(?int $campusId): TodosPrediosTabela
    {
        return new TodosPrediosTabela(
            $this->autorizacao->escopoPara(static::CHAVE),
            $campusId,
            fn (array $predio): bool => $this->podeEditarPredio($predio),
            fn (int $campus): bool => $this->podeEditarCampus($campus),
            $this->podeEditarCatalogo()
        );
    }

    public function novo(int $campusId): ResponseInterface|RedirectResponse|string
    {
        $campus = $this->campi->find($campusId);

        if ($campus === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Campus.notFound'));
        }

        if (! $this->podeEditarCampus($campusId)) {
            return $this->negar();
        }

        return $this->formulario($campus, ['campus_id' => $campusId]);
    }

    public function criar(int $campusId): ResponseInterface|RedirectResponse|string
    {
        $campus = $this->campi->find($campusId);

        if ($campus === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Campus.notFound'));
        }

        if (! $this->podeEditarCampus($campusId)) {
            return $this->negar();
        }

        [$dados, $erros] = $this->dadosPostados([]);
        $dados['campus_id'] = $campusId;

        if ($erros !== []) {
            return $this->formulario($campus, $dados, $erros, 422);
        }

        $id = $this->predios->insert($dados);

        return redirect()->to(site_url("predios/{$id}"))->with('sucesso', lang('Predios.saved'));
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        $predio = $this->predios->find($id);

        if ($predio === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Predios.notFound'));
        }

        if (! $this->podeVerPredio($predio)) {
            return $this->negar();
        }

        $tabela = $this->tabela(new AmbientesTabela($id, $this->podeEditarPredio($predio)));

        if ($this->pedidoParcial()) {
            return $tabela->fragmento();
        }

        return view('institucional/predios/ver', [
            'predio'      => $predio,
            'campus'      => $this->campi->find((int) $predio['campus_id']),
            'tabela'      => $tabela,
            'podeEditar'  => $this->podeEditarPredio($predio),
            'podeExcluir' => $this->podeEditarCampus((int) $predio['campus_id']),
        ]);
    }

    /** Exportação da lista de ambientes da tela do prédio. */
    public function exportarAmbientes(int $id): ResponseInterface
    {
        $predio = $this->predios->find($id);

        if ($predio === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Predios.notFound'));
        }

        if (! $this->podeVerPredio($predio)) {
            return $this->negar();
        }

        return $this->exportarTabela(new AmbientesTabela($id, $this->podeEditarPredio($predio)));
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        $predio = $this->predios->find($id);

        if ($predio === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Predios.notFound'));
        }

        if (! $this->podeEditarPredio($predio)) {
            return $this->negar();
        }

        return $this->formulario((array) $this->campi->find((int) $predio['campus_id']), $predio);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        $predio = $this->predios->find($id);

        if ($predio === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Predios.notFound'));
        }

        if (! $this->podeEditarPredio($predio)) {
            return $this->negar();
        }

        [$dados, $erros, $antigos] = $this->dadosPostados($predio);

        if ($erros !== []) {
            $campus = (array) $this->campi->find((int) $predio['campus_id']);

            $dados += ['id_predio' => $id, 'campus_id' => $predio['campus_id']];

            return $this->formulario($campus, $dados, $erros, 422);
        }

        $this->predios->update($id, $dados);
        $this->apagarArquivos($antigos);

        return redirect()->to(site_url("predios/{$id}"))->with('sucesso', lang('Predios.saved'));
    }

    public function excluir(int $id): ResponseInterface|RedirectResponse
    {
        $predio = $this->predios->find($id);

        if ($predio === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Predios.notFound'));
        }

        if (! $this->podeEditarCampus((int) $predio['campus_id'])) {
            return $this->negar();
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url("predios/{$id}"))->with('erro', lang('Ui.confirmMismatch'));
        }

        $dependentes = $this->predios->dependentes($id);

        if ($dependentes !== []) {
            return redirect()->to(site_url("predios/{$id}"))->with('erro', $this->mensagemDependentes($dependentes));
        }

        $this->predios->delete($id);

        return redirect()->to(site_url('campus/' . $predio['campus_id']))->with('sucesso', lang('Predios.deleted'));
    }

    /**
     * @param array<string, mixed> $atual
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: list<?string>}
     */
    private function dadosPostados(array $atual): array
    {
        $dados = $this->enderecoPostado();
        $erros = $this->validar($dados, $this->regrasEndereco());
        $dados['foto'] = $atual['foto'] ?? null;
        $dados['mapa'] = $atual['mapa'] ?? null;

        if ($erros !== []) {
            return [$dados, $erros, []];
        }

        [$arquivos, $antigos] = $this->arquivosPostados(self::ARQUIVOS, 'predios', $atual, $erros);

        return [$arquivos + $dados, $erros, $antigos];
    }

    /**
     * @param array<string, mixed>  $campus
     * @param array<string, mixed>  $predio
     * @param array<string, string> $erros
     */
    private function formulario(
        array $campus,
        array $predio,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        $dados = ['campus' => $campus, 'predio' => $predio];

        return $this->responder('institucional/predios/form', $dados, $erros, $status);
    }
}
