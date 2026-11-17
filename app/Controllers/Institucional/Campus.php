<?php

namespace App\Controllers\Institucional;

use App\Libraries\Agendamento\Configuracoes;
use App\Libraries\Arquivos;
use App\Libraries\Contraste;
use App\Models\Institucional\CampusModel;
use App\Models\Institucional\PredioModel;
use App\Tabelas\Institucional\CampusTabela;
use App\Tabelas\Institucional\PrediosTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Campus: dados, endereço, coordenadas, foto, mapa (imagem ou PDF) e identidade visual (logo, cor
 * primária e cor de texto). A combinação de cores só é salva se atender 4.5:1 (WCAG 2.1 AA).
 */
class Campus extends ControladorInstitucional
{
    /** @var array<string, string> campo de arquivo => uso (Arquivos::IMAGEM ou Arquivos::MAPA) */
    private const ARQUIVOS = ['logo' => Arquivos::IMAGEM, 'foto' => Arquivos::IMAGEM, 'mapa' => Arquivos::MAPA];

    private CampusModel $campi;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->campi = new CampusModel();
    }

    public function index(): string
    {
        $tabela = $this->tabela($this->tabelaCampi());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('institucional/campus/index', [
            'tabela'   => $tabela,
            'podeNovo' => $this->ehEditorGlobal(),
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->tabelaCampi());
    }

    /** Exportação da lista de prédios da tela do campus. */
    public function exportarPredios(int $id): ResponseInterface
    {
        if ($this->campi->find($id) === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Campus.notFound'));
        }

        if (! $this->podeVerCampus($id)) {
            return $this->negar();
        }

        return $this->exportarTabela($this->tabelaPredios($id));
    }

    private function tabelaCampi(): CampusTabela
    {
        return new CampusTabela(
            $this->campiVisiveis(),
            fn (int $id): bool => $this->podeEditarCampus($id),
            $this->ehEditorGlobal()
        );
    }

    private function tabelaPredios(int $campusId): PrediosTabela
    {
        return new PrediosTabela(
            $campusId,
            $this->prediosVisiveis($campusId),
            fn (array $predio): bool => $this->podeEditarPredio($predio),
            $this->podeEditarCampus($campusId)
        );
    }

    public function novo(): ResponseInterface|string
    {
        if (! $this->ehEditorGlobal()) {
            return $this->negar();
        }

        return $this->formulario(['ativo' => 1]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        if (! $this->ehEditorGlobal()) {
            return $this->negar();
        }

        [$dados, $erros] = $this->dadosPostados([]);

        if ($erros !== []) {
            return $this->formulario($dados, $erros, 422);
        }

        $id = $this->campi->insert($dados);
        (new Configuracoes(db_connect()))->garantir((int) $id);

        return redirect()->to(site_url("campus/{$id}"))->with('sucesso', lang('Campus.saved'));
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        $campus = $this->campi->find($id);

        if ($campus === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Campus.notFound'));
        }

        if (! $this->podeVerCampus($id)) {
            return $this->negar();
        }

        $tabela = $this->tabela($this->tabelaPredios($id));

        if ($this->pedidoParcial()) {
            return $tabela->fragmento();
        }

        return view('institucional/campus/ver', [
            'campus'        => $campus,
            'tabela'        => $tabela,
            'podeEditar'    => $this->podeEditarCampus($id),
            'podeExcluir'   => $this->ehEditorGlobal(),
        ]);
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        $campus = $this->campi->find($id);

        if ($campus === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Campus.notFound'));
        }

        if (! $this->podeEditarCampus($id)) {
            return $this->negar();
        }

        return $this->formulario($campus);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        $campus = $this->campi->find($id);

        if ($campus === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Campus.notFound'));
        }

        if (! $this->podeEditarCampus($id)) {
            return $this->negar();
        }

        [$dados, $erros, $antigos] = $this->dadosPostados($campus);

        if ($erros !== []) {
            return $this->formulario($dados + ['id_campus' => $id], $erros, 422);
        }

        $this->campi->update($id, $dados);
        $this->apagarArquivos($antigos);

        return redirect()->to(site_url("campus/{$id}"))->with('sucesso', lang('Campus.saved'));
    }

    public function excluir(int $id): ResponseInterface|RedirectResponse
    {
        $campus = $this->campi->find($id);

        if ($campus === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Campus.notFound'));
        }

        if (! $this->ehEditorGlobal()) {
            return $this->negar();
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url("campus/{$id}"))->with('erro', lang('Ui.confirmMismatch'));
        }

        $dependentes = $this->campi->dependentes($id);

        if ($dependentes !== []) {
            return redirect()->to(site_url("campus/{$id}"))->with('erro', $this->mensagemDependentes($dependentes));
        }

        // A configuração é parte do campus (não um dependente): sai junto com ele.
        db_connect()->query(
            'UPDATE configuracoes_campus SET deletado_em = NOW() WHERE campus_id = ? AND deletado_em IS NULL',
            [$id]
        );
        $this->campi->delete($id);

        return redirect()->to(site_url('campus'))->with('sucesso', lang('Campus.deleted'));
    }

    /**
     * Lê e valida o formulário. Os arquivos só são gravados se todo o resto estiver válido.
     *
     * @param array<string, mixed> $atual registro atual (vazio ao criar)
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: list<?string>}
     *         [dados, erros, arquivos antigos a apagar]
     */
    private function dadosPostados(array $atual): array
    {
        $dados = $this->enderecoPostado() + [
            'cor_primaria' => $this->textoOuNulo('cor_primaria'),
            'cor_texto'    => $this->textoOuNulo('cor_texto'),
            'ativo'        => $this->request->getPost('ativo') === '1' ? 1 : 0,
        ];

        if ($dados['cor_primaria'] !== null) {
            $dados['cor_primaria'] = strtoupper($dados['cor_primaria']);
        }

        $erros = $this->validar($dados, $this->regrasEndereco())
            + $this->validarIdentidade($dados['cor_primaria'], $dados['cor_texto']);

        foreach (array_keys(self::ARQUIVOS) as $campo) {
            $dados[$campo] = $atual[$campo] ?? null;
        }

        if ($erros !== []) {
            return [$dados, $erros, []];
        }

        [$arquivos, $antigos] = $this->arquivosPostados(self::ARQUIVOS, 'campus', $atual, $erros);

        return [$arquivos + $dados, $erros, $antigos];
    }

    /**
     * Cor primária e cor de texto andam juntas (as duas ou nenhuma, que é a identidade padrão) e o par
     * precisa de 4.5:1. Quando só a outra opção de texto passa, a mensagem sugere essa opção.
     *
     * @return array<string, string>
     */
    private function validarIdentidade(?string $cor, ?string $texto): array
    {
        if ($cor === null && $texto === null) {
            return [];
        }

        if ($cor === null || ! Contraste::corValida($cor)) {
            return ['cor_primaria' => lang('Campus.colorInvalid')];
        }

        if (! in_array($texto, [Contraste::TEXTO_CLARO, Contraste::TEXTO_ESCURO], true)) {
            return ['cor_texto' => lang('Campus.textColorRequired')];
        }

        $falha = Contraste::avaliarIdentidade($cor, $texto);

        if ($falha === null) {
            return [];
        }

        $sep = lang('Institucional.decimalSeparator');

        if ($falha['motivo'] === 'outra') {
            return ['cor_texto' => lang('Campus.contrastUseOther', [
                Contraste::formatar((float) $falha['razao'], $sep),
                lang('Campus.text_' . $falha['sugerida']),
                Contraste::formatar((float) $falha['razaoSugerida'], $sep),
            ])];
        }

        return ['cor_primaria' => lang('Campus.contrastNone', [
            Contraste::formatar((float) $falha['claro'], $sep),
            Contraste::formatar((float) $falha['escuro'], $sep),
        ])];
    }

    /**
     * @param array<string, mixed>  $campus
     * @param array<string, string> $erros
     */
    private function formulario(array $campus, array $erros = [], int $status = 200): ResponseInterface|string
    {
        return $this->responder('institucional/campus/form', [
            'campus' => $campus,
            'cores'  => [
                Contraste::TEXTO_CLARO  => Contraste::corDoTexto(Contraste::TEXTO_CLARO),
                Contraste::TEXTO_ESCURO => Contraste::corDoTexto(Contraste::TEXTO_ESCURO),
            ],
        ], $erros, $status);
    }
}
