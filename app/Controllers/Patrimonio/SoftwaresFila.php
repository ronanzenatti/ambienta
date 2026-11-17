<?php

namespace App\Controllers\Patrimonio;

use App\Libraries\Patrimonio\Recusa;
use App\Libraries\Patrimonio\SoftwaresPropostos;
use App\Tabelas\Patrimonio\SoftwaresTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Fila de softwares propostos por professores (permissão patrimonio.aprovar_software): listar os pendentes e,
 * na página de revisão, aprovar, reprovar (com motivo) ou mesclar num software já aprovado.
 */
class SoftwaresFila extends ControladorPatrimonio
{
    private const FILA = 'patrimonio/softwares/pendentes';

    public function index(): string
    {
        $tabela = $this->tabela($this->novaTabela());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Patrimonio.softwaresQueueTitle'),
            'intro'  => lang('Patrimonio.softwaresQueueIntro'),
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->novaTabela());
    }

    public function revisar(int $id): ResponseInterface|RedirectResponse|string
    {
        $software = $this->pendente($id);

        if ($software === null) {
            return redirect()->to(site_url(self::FILA))->with('erro', lang('Patrimonio.softwareNotPending'));
        }

        return $this->pagina($software);
    }

    public function aprovar(int $id): RedirectResponse
    {
        return $this->decidir(
            $id,
            'Patrimonio.softwareApproved',
            static function (SoftwaresPropostos $servico, int $id): void {
                $servico->aprovar($id);
            }
        );
    }

    public function reprovar(int $id): ResponseInterface|RedirectResponse|string
    {
        $software = $this->pendente($id);

        if ($software === null) {
            return redirect()->to(site_url(self::FILA))->with('erro', lang('Patrimonio.softwareNotPending'));
        }

        $motivo = $this->texto('motivo');

        if ($motivo === '') {
            return $this->pagina($software, ['motivo' => lang('Patrimonio.softwareReasonRequired')], 422);
        }

        return $this->decidir(
            $id,
            'Patrimonio.softwareRejected',
            function (SoftwaresPropostos $servico, int $id) use ($motivo): void {
                $servico->reprovar($id, $this->usuarioId(), $motivo);
            }
        );
    }

    public function mesclar(int $id): ResponseInterface|RedirectResponse|string
    {
        $software = $this->pendente($id);

        if ($software === null) {
            return redirect()->to(site_url(self::FILA))->with('erro', lang('Patrimonio.softwareNotPending'));
        }

        $destino = $this->texto('destino_id');

        if (! service('opcoes')->softwares()->contem($destino) || (int) $destino === $id) {
            return $this->pagina($software, ['destino_id' => lang('Patrimonio.softwareMergeInvalid')], 422);
        }

        return $this->decidir(
            $id,
            'Patrimonio.softwareMerged',
            function (SoftwaresPropostos $servico, int $id) use ($destino): void {
                $servico->mesclar($id, (int) $destino, $this->usuarioId());
            }
        );
    }

    /**
     * @param callable(SoftwaresPropostos, int): void $acao
     */
    private function decidir(int $id, string $sucesso, callable $acao): RedirectResponse
    {
        try {
            $acao(new SoftwaresPropostos(db_connect()), $id);
        } catch (Recusa $recusa) {
            return redirect()->to(site_url(self::FILA))->with('erro', lang($recusa->getMessage()));
        }

        return redirect()->to(site_url(self::FILA))->with('sucesso', lang($sucesso));
    }

    private function novaTabela(): SoftwaresTabela
    {
        return new SoftwaresTabela($this->podeEditarCatalogo(), true, true);
    }

    /** @return array<string, mixed>|null */
    private function pendente(int $id): ?array
    {
        $software = db_connect()->table('softwares s')
            ->select('s.*, u.nome AS solicitante')
            ->join('usuarios u', 'u.id_usuario = s.solicitado_por_id', 'left')
            ->where('s.id_software', $id)
            ->where('s.status', 'pendente')
            ->where('s.deletado_em', null)
            ->get()
            ->getRowArray();

        return $software;
    }

    /**
     * @param array<string, mixed>  $software
     * @param array<string, string> $erros
     */
    private function pagina(array $software, array $erros = [], int $status = 200): ResponseInterface|string
    {
        return $this->responder('patrimonio/softwares/revisar', ['software' => $software], $erros, $status);
    }
}
