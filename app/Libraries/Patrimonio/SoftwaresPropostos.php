<?php

namespace App\Libraries\Patrimonio;

use App\Libraries\Notificacoes\Destinatarios;
use App\Libraries\Notificacoes\Notificador;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Softwares propostos por professores: nascem "pendente" e entram no requisito do professor, mas ficam fora do
 * cálculo de atendimento até alguém com patrimonio.aprovar_software decidir: aprovar, reprovar (com motivo) ou
 * mesclar num software que já existe (os requisitos passam a apontar para ele). Quem aprova é avisado da
 * proposta; o professor, da reprovação e da mesclagem.
 */
final class SoftwaresPropostos
{
    public const CHAVE = 'patrimonio.aprovar_software';

    private readonly Notificador $notificador;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->notificador = new Notificador($db);
    }

    /**
     * Cria o software pendente e o exige no requisito.
     *
     * @return int id do software
     *
     * @throws Recusa
     */
    public function propor(int $professorId, int $requisitoId, string $nome, ?string $versao, ?string $tecnicos): int
    {
        $existe = $this->db->table('softwares')
            ->where('nome', $nome)
            ->where("IFNULL(versao, '') = " . $this->db->escape($versao ?? ''), null, false)
            ->where('deletado_em', null)
            ->countAllResults() > 0;

        if ($existe) {
            throw new Recusa('Patrimonio.softwareInUse');
        }

        return $this->emTransacao(function () use ($professorId, $requisitoId, $nome, $versao, $tecnicos): int {
            $this->db->table('softwares')->insert([
                'nome'                => $nome,
                'versao'              => $versao,
                'requisitos_tecnicos' => $tecnicos,
                'status'              => 'pendente',
                'solicitado_por_id'   => $professorId,
            ]);
            $id = (int) $this->db->insertID();

            $this->db->table('requisitos_disciplina_softwares')->insert([
                'requisito_disciplina_id' => $requisitoId,
                'software_id'             => $id,
            ]);

            $this->notificador->notificar(
                (new Destinatarios($this->db))->comPermissaoEmQualquerEscopo(self::CHAVE),
                'software_pendente',
                null,
                null,
                ['autor' => $this->notificador->nome($professorId), 'software' => $this->rotulo($nome, $versao)],
                $professorId
            );

            return $id;
        });
    }

    /** @throws Recusa */
    public function aprovar(int $id): void
    {
        $this->pendente($id);
        $this->db->table('softwares')->where('id_software', $id)->update(['status' => 'aprovado']);
    }

    /** @throws Recusa */
    public function reprovar(int $id, int $usuarioId, string $motivo): void
    {
        if (trim($motivo) === '') {
            throw new Recusa('Patrimonio.softwareReasonRequired');
        }

        $software = $this->pendente($id);

        $this->emTransacao(function () use ($id, $usuarioId, $motivo, $software): void {
            $this->db->table('softwares')->where('id_software', $id)->update([
                'status'            => 'reprovado',
                'motivo_reprovacao' => trim($motivo),
            ]);
            $this->notificador->notificar(
                [$software['solicitado_por_id'] === null ? null : (int) $software['solicitado_por_id']],
                'software_reprovado',
                null,
                null,
                [
                    'autor'    => $this->notificador->nome($usuarioId),
                    'software' => $this->rotulo((string) $software['nome'], $software['versao']),
                    'motivo'   => trim($motivo),
                ],
                $usuarioId
            );
        });
    }

    /**
     * Mescla o software pendente num aprovado: os requisitos passam a exigir o destino e o pendente é excluído.
     *
     * @throws Recusa
     */
    public function mesclar(int $id, int $destinoId, int $usuarioId): void
    {
        $software = $this->pendente($id);
        $destino  = $this->db->table('softwares')
            ->where('id_software', $destinoId)
            ->where('status', 'aprovado')
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        if ($destino === null || $destinoId === $id) {
            throw new Recusa('Patrimonio.softwareMergeInvalid');
        }

        $this->emTransacao(function () use ($id, $destinoId, $destino, $usuarioId, $software): void {
            $linhas = $this->db->table('requisitos_disciplina_softwares')
                ->where('software_id', $id)
                ->where('deletado_em', null)
                ->get()
                ->getResultArray();

            foreach ($linhas as $linha) {
                $requisitoId = (int) $linha['requisito_disciplina_id'];
                $jaExige     = $this->db->table('requisitos_disciplina_softwares')
                    ->where('requisito_disciplina_id', $requisitoId)
                    ->where('software_id', $destinoId)
                    ->where('deletado_em', null)
                    ->countAllResults() > 0;

                $this->db->table('requisitos_disciplina_softwares')
                    ->where('id_requisito_disciplina_software', $linha['id_requisito_disciplina_software'])
                    ->update($jaExige
                        ? ['deletado_em' => date('Y-m-d H:i:s')]
                        : ['software_id' => $destinoId]);
            }

            $this->db->table('softwares')->where('id_software', $id)->update(['deletado_em' => date('Y-m-d H:i:s')]);

            $this->notificador->notificar(
                [$software['solicitado_por_id'] === null ? null : (int) $software['solicitado_por_id']],
                'software_mesclado',
                null,
                null,
                [
                    'autor'    => $this->notificador->nome($usuarioId),
                    'software' => $this->rotulo((string) $software['nome'], $software['versao']),
                    'destino'  => $this->rotulo((string) $destino['nome'], $destino['versao']),
                ],
                $usuarioId
            );
        });
    }

    /**
     * O software pendente (ainda sem decisão).
     *
     * @return array<string, mixed>
     *
     * @throws Recusa
     */
    private function pendente(int $id): array
    {
        $software = $this->db->table('softwares')
            ->where('id_software', $id)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        if ($software === null) {
            throw new Recusa('Patrimonio.softwareNotFound');
        }

        if ($software['status'] !== 'pendente') {
            throw new Recusa('Patrimonio.softwareNotPending');
        }

        return $software;
    }

    private function rotulo(string $nome, ?string $versao): string
    {
        return trim($nome . ' ' . ($versao ?? ''));
    }

    /**
     * @template T
     *
     * @param callable(): T $operacao
     *
     * @return T
     */
    private function emTransacao(callable $operacao): mixed
    {
        $this->db->transBegin();

        try {
            $resultado = $operacao();
            $this->db->transCommit();

            return $resultado;
        } catch (Throwable $erro) {
            $this->db->transRollback();

            throw $erro;
        }
    }
}
