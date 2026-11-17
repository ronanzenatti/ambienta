<?php

namespace App\Libraries\Notificacoes;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Cria notificações (REQUISITOS §11): uma linha por destinatário com o tipo e os parâmetros, nunca o texto
 * pronto. O texto é montado na leitura, no idioma de quem lê (ver Mensagem); o e-mail sai depois, pelo
 * comando notificacoes:enviar (ver EnvioEmails).
 *
 * Chamado de dentro das operações de domínio, na mesma transação: se a operação desfaz, a notificação some junto.
 */
final class Notificador
{
    /** Tipos de notificação (tipos_notificacoes): chave => módulo. Os textos ficam em Language/.../Notificacoes.php. */
    public const TIPOS = [
        'movimentacao_enviada'    => 'patrimonio',
        'movimentacao_confirmada' => 'patrimonio',
        'movimentacao_cancelada'  => 'patrimonio',
        'descarte_solicitado'     => 'patrimonio',
        'descarte_aprovado'       => 'patrimonio',
        'descarte_rejeitado'      => 'patrimonio',
        'chamado_aberto'          => 'chamados',
        'chamado_status'          => 'chamados',
        'software_pendente'       => 'patrimonio',
        'software_reprovado'      => 'patrimonio',
        'software_mesclado'       => 'patrimonio',
        'reserva_em_seu_nome'     => 'reservas',
        'reserva_pendente'        => 'reservas',
        'reserva_confirmada'      => 'reservas',
        'reserva_cancelada'       => 'reservas',
        'reserva_cancelada_fila'  => 'reservas',
        'extra_deferido'          => 'reservas',
        'extra_indeferido'        => 'reservas',
        'conflito_pendente'       => 'agendamento',
        'conflito_autorizado'     => 'agendamento',
        'conflito_negado'         => 'agendamento',
        'bloqueio_ambiente'       => 'agendamento',
        'alocacao_criada'         => 'agendamento',
        'alocacao_cancelada'      => 'agendamento',
        'alocacao_longa'          => 'agendamento',
    ];

    /** Valores de notificacoes.referencia_tipo (ENUM do schema). */
    public const REFERENCIAS = [
        'movimentacao_item', 'descarte', 'chamado', 'conflito_agendamento', 'reserva_pontual', 'reserva_item_extra',
        'bloqueio_ambiente', 'alocacao_semestral',
    ];

    /** @var array<string, int> */
    private array $tipos = [];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<int|null>       $usuarios   destinatários (nulos, repetidos e inativos são ignorados)
     * @param array<string, mixed> $parametros valores para o texto; datas no formato do banco (Y-m-d H:i:s)
     * @param int|null             $exceto     quem fez a ação: não é avisado do que ele mesmo fez
     *
     * @return int quantas notificações foram criadas
     */
    public function notificar(
        array $usuarios,
        string $chave,
        ?string $referenciaTipo,
        ?int $referenciaId,
        array $parametros = [],
        ?int $exceto = null
    ): int {
        if ($referenciaTipo !== null && ! in_array($referenciaTipo, self::REFERENCIAS, true)) {
            throw new InvalidArgumentException("Referência de notificação desconhecida: {$referenciaTipo}");
        }

        $destinatarios = $this->ativos($usuarios, $exceto);

        if ($destinatarios === []) {
            return 0;
        }

        $tipoId = $this->tipo($chave);
        $json   = $parametros === [] ? null : json_encode($parametros, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ($destinatarios as $usuarioId) {
            $this->db->table('notificacoes')->insert([
                'usuario_id'          => $usuarioId,
                'tipo_notificacao_id' => $tipoId,
                'referencia_tipo'     => $referenciaTipo,
                'referencia_id'       => $referenciaTipo === null ? null : $referenciaId,
                'parametros'          => $json,
            ]);
        }

        return count($destinatarios);
    }

    /** Nome de um usuário, para os parâmetros ("quem fez"). */
    public function nome(?int $usuarioId): string
    {
        if ($usuarioId === null) {
            return '';
        }

        return (string) ($this->db->query('SELECT nome FROM usuarios WHERE id_usuario = ?', [$usuarioId])
            ->getRow()->nome ?? '');
    }

    /** Rótulo curto de um ambiente ("101 Laboratório 1 · P1 · FJ"), inclusive soft-deletado. */
    public function ambiente(int $ambienteId): string
    {
        $linha = $this->db->query(
            "SELECT CONCAT(a.numero, ' ', a.nome, ' · ', p.abreviacao, ' · ', c.abreviacao) AS rotulo
             FROM ambientes a JOIN predios p ON p.id_predio = a.predio_id JOIN campus c ON c.id_campus = p.campus_id
             WHERE a.id_ambiente = ?",
            [$ambienteId]
        )->getRow();

        return (string) ($linha->rotulo ?? '');
    }

    /** Identificação de um item ("FJ-0500 — Monitor LG"; sem número, só o nome). */
    public function item(int $itemId): string
    {
        $linha = $this->db->query(
            'SELECT i.numero_patrimonio, i.nome FROM itens i WHERE i.id_item = ?',
            [$itemId]
        )->getRowArray();

        if ($linha === null) {
            return '';
        }

        return $linha['numero_patrimonio'] === null
            ? (string) $linha['nome']
            : $linha['numero_patrimonio'] . ' — ' . $linha['nome'];
    }

    /**
     * @param list<int|null> $usuarios
     *
     * @return list<int>
     */
    private function ativos(array $usuarios, ?int $exceto): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $usuarios),
            static fn (int $id): bool => $id > 0 && $id !== $exceto
        )));

        if ($ids === []) {
            return [];
        }

        $linhas = $this->db->table('usuarios')
            ->select('id_usuario')
            ->whereIn('id_usuario', $ids)
            ->where('deletado_em', null)
            ->where('ativo', 1)
            ->get()
            ->getResultArray();

        return array_map('intval', array_column($linhas, 'id_usuario'));
    }

    /** Id do tipo; o catálogo é fixo (TIPOS) e o seeder o grava, mas um tipo que falte é criado aqui. */
    private function tipo(string $chave): int
    {
        if (! array_key_exists($chave, self::TIPOS)) {
            throw new InvalidArgumentException("Tipo de notificação desconhecido: {$chave}");
        }

        if (isset($this->tipos[$chave])) {
            return $this->tipos[$chave];
        }

        $linha = $this->db->table('tipos_notificacoes')
            ->select('id_tipo_notificacao')
            ->where('chave', $chave)
            ->where('deletado_em', null)
            ->get()
            ->getRow();

        if ($linha === null) {
            $this->db->table('tipos_notificacoes')->insert(['chave' => $chave, 'modulo' => self::TIPOS[$chave]]);

            return $this->tipos[$chave] = (int) $this->db->insertID();
        }

        return $this->tipos[$chave] = (int) $linha->id_tipo_notificacao;
    }
}
