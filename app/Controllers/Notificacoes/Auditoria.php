<?php

namespace App\Controllers\Notificacoes;

use App\Controllers\ControladorCadastro;
use App\Libraries\Auditoria\Auditoria as AuditoriaServico;
use App\Libraries\Autorizacao;
use App\Tabelas\Notificacoes\LogsAuditoriaTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Log de auditoria (REQUISITOS §13): consulta com auditoria.consultar, limitada ao escopo de quem consulta
 * (ver LogsAuditoriaTabela); expurgo definitivo por data, exclusivo do superusuário, só além da retenção
 * mínima e com CONFIRMO.
 */
class Auditoria extends ControladorCadastro
{
    private const CONSULTAR = 'auditoria.consultar';
    private const LISTA     = 'auditoria';

    private Autorizacao $autorizacao;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        helper('institucional');
        $this->autorizacao = service('autorizacao');
    }

    public function index(): string
    {
        $tabela = $this->tabela($this->novaTabela());

        if ($this->pedidoParcial()) {
            return $tabela->fragmento();
        }

        $super  = $this->autorizacao->ehSuper();
        $abrir  = ['@click' => "\$dispatch('open-modal', 'expurgar-log')"];

        return view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Auditoria.title'),
            'intro'  => lang('Auditoria.intro', [$this->retencao()]),
            'extra'  => $super ? ui_button(lang('Auditoria.purgeButton'), 'delete', $abrir) : null,
            'antes'  => $super ? view('notificacoes/auditoria/_expurgo', [
                'limite'   => AuditoriaServico::limiteExpurgo(),
                'retencao' => $this->retencao(),
            ], ['saveData' => false]) : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->novaTabela());
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        $condicao = AuditoriaServico::condicaoEscopo($this->escopo());
        $consulta = db_connect()->table('logs_auditoria la')
            ->select('la.*, u.nome AS usuario')
            ->join('usuarios u', 'u.id_usuario = la.usuario_id', 'left')
            ->where('la.id_log_auditoria', $id);

        if ($condicao !== null) {
            $consulta->where($condicao, null, false);
        }

        $log = $consulta->get()->getRowArray();

        if ($log === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Auditoria.notFound'));
        }

        return view('notificacoes/auditoria/ver', ['log' => $log]);
    }

    public function expurgar(): RedirectResponse|ResponseInterface
    {
        $lista = redirect()->to(site_url(self::LISTA));

        if (! $this->exclusaoConfirmada()) {
            return $lista->with('erro', lang('Ui.confirmMismatch'));
        }

        $limite = AuditoriaServico::limiteExpurgo();
        $data   = (string) $this->request->getPost('data');
        $ate    = DateTimeImmutable::createFromFormat('!Y-m-d', $data);

        if ($ate === false || $ate->format('Y-m-d') !== $data || $ate > $limite) {
            return $lista->with('erro', lang('Auditoria.purgeDateInvalid', [
                $limite->format((string) lang('Institucional.dateFormat')),
            ]));
        }

        $total = (new AuditoriaServico(db_connect()))->expurgar($ate);

        log_message('notice', 'Expurgo do log de auditoria: {total} registros antes de {data}, usuário {usuario}.', [
            'total'   => $total,
            'data'    => $data,
            'usuario' => service('autenticacao')->id(),
        ]);

        return $lista->with('sucesso', lang('Auditoria.purgeDone', [$total]));
    }

    private function novaTabela(): LogsAuditoriaTabela
    {
        return new LogsAuditoriaTabela($this->escopo());
    }

    /** @return array{global: bool, campus: list<int>, predio: list<int>} */
    private function escopo(): array
    {
        return $this->autorizacao->escopoPara(self::CONSULTAR, Autorizacao::EDITOR);
    }

    private function retencao(): int
    {
        return config(\Config\Notificacoes::class)->retencaoAuditoriaAnos;
    }
}
