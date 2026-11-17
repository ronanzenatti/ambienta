<?php

namespace App\Libraries\Agendamento;

use App\Libraries\Auditoria\Auditoria;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Configurações por campus (configuracoes_campus, uma linha ativa por campus). Todo campus tem a sua: a linha
 * nasce com o campus e, para os que já existiam, na primeira leitura (os valores padrão são os do schema).
 */
final class Configuracoes
{
    /** Campos editáveis na tela de configuração, na ordem do formulário. */
    public const BOOLEANOS = [
        'exige_confirmacao_reserva', 'confirmador_pode_confirmar_propria', 'fila_confirmacao_ativa',
        'permite_agendamento_feriado',
    ];

    public const INTEIROS = [
        'tempo_minimo_antecedencia_reserva_minutos', 'tempo_maximo_antecedencia_reserva_dias',
        'tempo_minimo_cancelamento_minutos', 'prazo_resposta_movimentacao_horas', 'prazo_resposta_devolucao_horas',
    ];

    public const HORARIOS = ['horario_fim_manha', 'horario_fim_tarde', 'horario_fim_noite'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @return array<string, mixed> a linha de configuração do campus (criada se ainda não existir) */
    public function doCampus(int $campusId): array
    {
        $linha = $this->ler($campusId);

        if ($linha !== null) {
            return $linha;
        }

        $this->garantir($campusId);

        return $this->ler($campusId)
            ?? throw new \RuntimeException("Configuração do campus {$campusId} não criada.");
    }

    /** @return array<string, mixed> a configuração do campus do ambiente */
    public function doAmbiente(int $ambienteId): array
    {
        $campus = $this->db->query(
            'SELECT p.campus_id FROM ambientes a JOIN predios p ON p.id_predio = a.predio_id WHERE a.id_ambiente = ?',
            [$ambienteId]
        )->getRow();

        if ($campus === null) {
            throw new Recusa('Agendamento.environmentInvalid');
        }

        return $this->doCampus((int) $campus->campus_id);
    }

    /** Cria a linha do campus se ainda não houver (a unicidade do banco segura duas criações simultâneas). */
    public function garantir(int $campusId): void
    {
        if ($this->ler($campusId) !== null) {
            return;
        }

        try {
            $this->db->table('configuracoes_campus')->insert(['campus_id' => $campusId]);
        } catch (DatabaseException) {
            // Outra requisição criou no meio do caminho: a linha existe, é o que importa.
        }
    }

    /** @param array<string, mixed> $dados só as colunas de BOOLEANOS, INTEIROS e HORARIOS */
    public function salvar(int $campusId, array $dados): void
    {
        $this->garantir($campusId);

        $permitidas = [...self::BOOLEANOS, ...self::INTEIROS, ...self::HORARIOS];
        $antes      = $this->doCampus($campusId);
        $id         = (int) $antes['id_configuracao_campus'];

        $this->db->table('configuracoes_campus')
            ->where('id_configuracao_campus', $id)
            ->update(array_intersect_key($dados, array_flip($permitidas)));

        (new Auditoria($this->db))->registrarDepois('configuracoes_campus', $id, $antes);
    }

    /** @return array<string, mixed>|null */
    private function ler(int $campusId): ?array
    {
        return $this->db->table('configuracoes_campus')
            ->where('campus_id', $campusId)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();
    }
}
