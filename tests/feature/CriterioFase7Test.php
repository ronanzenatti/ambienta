<?php

use App\Libraries\Notificacoes\EnvioEmails;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\Mock\MockEmail;
use Config\Email;
use Tests\Support\FeatureBancoTestCase;

/**
 * Critério de conclusão da Fase 7: uma ação crítica (aprovar um descarte) gera notificação (sistema e e-mail) e
 * fica registrada no log de auditoria, com o registro inteiro antes e depois.
 *
 * @internal
 */
final class CriterioFase7Test extends FeatureBancoTestCase
{
    private int $item;
    private int $motivo;

    /** @var array<string, mixed> pede o descarte (Auxiliares) */
    private array $auxiliar;

    /** @var array<string, mixed> aprova o descarte (Coordenação) */
    private array $coordenacao;

    /** @var array<string, mixed> superperfil: consulta o log */
    private array $direcao;

    /** @var array<string, array<string, mixed>> e-mails enviados, por destinatário */
    private array $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $campus       = $this->criarCampus('Campus A');
        $laboratorio  = $this->criarAmbiente($this->criarPredio($campus, 'Prédio 1'), '101', 'Laboratório 1');
        $this->item   = $this->criarItem($this->criarModelo('Monitor LG'), $laboratorio, null, 'FJ-0500');
        $this->motivo = (int) $this->db->table('motivos_descarte')->get()->getRow()->id_motivo_descarte;

        $this->auxiliar = $this->criarUsuario(['nome' => 'Auxiliar Caio', 'email' => 'caio@fatec.sp.gov.br']);
        $this->vincular($this->auxiliar['id_usuario'], 'Auxiliares', 'campus', $campus);
        $this->coordenacao = $this->criarUsuario(['nome' => 'Coordenadora Dora', 'email' => 'dora@fatec.sp.gov.br', 'idioma_preferido' => 'es']);
        $this->vincular($this->coordenacao['id_usuario'], 'Coordenação', 'campus', $campus);
        $this->direcao = $this->criarUsuario(['nome' => 'Diretor Eli']);
        $this->vincular($this->direcao['id_usuario'], 'Direção');

        Events::on('email', function (array $email): void {
            $this->enviados[implode(',', (array) $email['recipients'])] = $email;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');

        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notificacoesDe(array $usuario): array
    {
        return $this->db->query(
            'SELECT n.*, t.chave FROM notificacoes n JOIN tipos_notificacoes t ON t.id_tipo_notificacao = n.tipo_notificacao_id
             WHERE n.usuario_id = ? ORDER BY n.id_notificacao',
            [$usuario['id_usuario']]
        )->getResultArray();
    }

    public function testAprovarDescarteGeraNotificacaoERegistroDeAuditoria(): void
    {
        // Pedido: avisa quem aprova no local do item (Coordenação), não quem pediu nem o superperfil.
        $this->como($this->auxiliar)->post("patrimonio/itens/{$this->item}/descartar", [
            'motivo_descarte_id' => (string) $this->motivo, 'justificativa' => 'Tela quebrada',
        ])->assertRedirect();
        $descarte = (int) $this->db->table('descartes')->get()->getRow()->id_descarte;

        $this->assertSame(['descarte_solicitado'], array_column($this->notificacoesDe($this->coordenacao), 'chave'));
        $this->assertSame([], $this->notificacoesDe($this->auxiliar));
        $this->assertSame([], $this->notificacoesDe($this->direcao));

        // Aprovação (CONFIRMO): avisa quem pediu; quem aprovou não é avisado da própria ação.
        $this->como($this->coordenacao)->excluir("patrimonio/descartes/{$descarte}/aprovar")->assertRedirect();

        $avisos = $this->notificacoesDe($this->auxiliar);
        $this->assertCount(1, $avisos);
        $this->assertSame('descarte_aprovado', $avisos[0]['chave']);
        $this->assertSame('descarte', $avisos[0]['referencia_tipo']);
        $this->assertSame($descarte, (int) $avisos[0]['referencia_id']);
        $this->assertSame(
            ['item' => 'FJ-0500 — Monitor LG', 'autor' => 'Coordenadora Dora'],
            json_decode((string) $avisos[0]['parametros'], true),
            'parâmetros, nunca o texto pronto'
        );
        $this->assertCount(1, $this->notificacoesDe($this->coordenacao));

        // Log de auditoria: o descarte (antes pendente, depois aprovado) e a baixa do item (exclusão lógica).
        $log = $this->db->table('logs_auditoria')->where('tabela_afetada', 'descartes')->where('acao', 'atualizacao')->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame($descarte, (int) $log['registro_id']);
        $this->assertSame((int) $this->coordenacao['id_usuario'], (int) $log['usuario_id']);
        $this->assertNotEmpty($log['ip_origem']);
        $this->assertContains('status', json_decode((string) $log['campos_alterados'], true));
        $this->assertSame('pendente', json_decode((string) $log['dados_anteriores'], true)['status']);
        $this->assertSame('aprovado', json_decode((string) $log['dados_novos'], true)['status']);
        $this->seeInDatabase('logs_auditoria', ['tabela_afetada' => 'descartes', 'registro_id' => $descarte, 'acao' => 'insercao']);
        $this->seeInDatabase('logs_auditoria', [
            'tabela_afetada' => 'itens', 'registro_id' => $this->item, 'acao' => 'exclusao_logica',
            'usuario_id' => $this->coordenacao['id_usuario'],
        ]);

        // Na tela: o sino conta a não lida; a caixa mostra o texto montado no idioma de quem lê.
        $caixa = $this->como($this->auxiliar)->get('notificacoes');
        $caixa->assertSee('Notificações, 1 não lida');
        $caixa->assertSee('Descarte aprovado');
        $caixa->assertSee('Coordenadora Dora aprovou o descarte do item FJ-0500 — Monitor LG.');

        // Abrir marca como lida e leva ao descarte.
        $id = (int) $avisos[0]['id_notificacao'];
        $this->como($this->auxiliar)->get("notificacoes/{$id}")->assertRedirectTo(site_url("patrimonio/descartes/{$descarte}"));
        $this->seeInDatabase('notificacoes', ['id_notificacao' => $id, 'lida' => 1]);
        $this->como($this->auxiliar)->get('notificacoes')->assertSee('Notificações, nenhuma não lida');

        // E-mail: um por evento, no idioma de cada destinatário; a fila não reenvia.
        $resultado = (new EnvioEmails($this->db))->enviar(new MockEmail(config(Email::class)));
        $this->assertSame(['enviadas' => 2, 'falhas' => 0], $resultado);
        $this->assertSame('Ambienta: Descarte aprovado', $this->enviados['caio@fatec.sp.gov.br']['subject']);
        $this->assertStringContainsString(site_url("patrimonio/descartes/{$descarte}"), (string) $this->enviados['caio@fatec.sp.gov.br']['body']);
        $this->assertSame('Ambienta: Baja pendiente de aprobación', $this->enviados['dora@fatec.sp.gov.br']['subject']);
        $this->assertSame(['enviadas' => 0, 'falhas' => 0], (new EnvioEmails($this->db))->enviar(new MockEmail(config(Email::class))));

        // O superperfil consulta o registro, com o antes e o depois.
        $lista = $this->como($this->direcao)->get('auditoria');
        $lista->assertOK();
        $lista->assertSee('Descarte');
        $lista->assertSee('Coordenadora Dora');

        $detalhe = $this->como($this->direcao)->get('auditoria/' . $log['id_log_auditoria']);
        $detalhe->assertOK();
        $detalhe->assertSee('Alteração');
        $detalhe->assertSee('pendente');
        $detalhe->assertSee('aprovado');
    }
}
