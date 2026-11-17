<?php

use App\Libraries\AnonimizadorUsuario;
use App\Libraries\Auditoria\Auditoria;
use App\Models\Institucional\UsuarioModel;
use App\Models\Patrimonio\ItemModel;
use Tests\Support\FeatureBancoTestCase;

/**
 * Log de auditoria: registro inteiro antes/depois com os campos alterados, segredos fora do log, consulta
 * limitada ao escopo de quem tem auditoria.consultar, e expurgo por data só do superusuário, além da retenção.
 *
 * @internal
 */
final class AuditoriaTest extends FeatureBancoTestCase
{
    private int $itemA;
    private int $itemB;

    /** @var array<string, mixed> auditoria.consultar só no campus A */
    private array $auditor;

    /** @var array<string, mixed> superperfil */
    private array $direcao;

    protected function setUp(): void
    {
        parent::setUp();

        $campusA     = $this->criarCampus('Campus A');
        $campusB     = $this->criarCampus('Campus B');
        $modelo      = $this->criarModelo('Monitor LG');
        $this->itemA = $this->criarItem($modelo, $this->criarAmbiente($this->criarPredio($campusA, 'PA'), '101', 'Lab A'), null, 'FJ-A');
        $this->itemB = $this->criarItem($modelo, null, $this->criarAlmoxarifado($campusB), 'FJ-B');

        $this->criarPerfil('Auditor', ['auditoria.consultar' => 'editor']);
        $this->auditor = $this->criarUsuario(['nome' => 'Auditora']);
        $this->vincular($this->auditor['id_usuario'], 'Auditor', 'campus', $campusA);

        $this->direcao = $this->criarUsuario(['nome' => 'Diretor']);
        $this->vincular($this->direcao['id_usuario'], 'Direção');
    }

    private function ultimoLog(string $tabela, int $id): array
    {
        return $this->db->table('logs_auditoria')
            ->where('tabela_afetada', $tabela)
            ->where('registro_id', $id)
            ->orderBy('id_log_auditoria', 'DESC')
            ->get()
            ->getRowArray() ?? [];
    }

    public function testModelAuditadoGravaAntesDepoisECamposAlterados(): void
    {
        (new ItemModel())->update($this->itemA, ['numero_patrimonio' => 'FJ-A2']);

        $log = $this->ultimoLog('itens', $this->itemA);
        $this->assertSame('atualizacao', $log['acao']);
        $this->assertSame(['numero_patrimonio'], array_values(array_diff(json_decode($log['campos_alterados'], true), ['alterado_em'])));
        $this->assertSame('FJ-A', json_decode($log['dados_anteriores'], true)['numero_patrimonio']);
        $this->assertSame('FJ-A2', json_decode($log['dados_novos'], true)['numero_patrimonio']);
        $this->assertNull($log['usuario_id'], 'sem sessão: rotina automática');

        // Salvar sem mudar nada não gera registro.
        $antes = $this->db->table('logs_auditoria')->countAllResults();
        (new ItemModel())->update($this->itemA, ['numero_patrimonio' => 'FJ-A2']);
        $this->assertSame($antes, $this->db->table('logs_auditoria')->countAllResults());
    }

    public function testSenhaNuncaVaiParaOLogEAcessoNaoPoluiOLog(): void
    {
        $usuarios = new UsuarioModel();
        $id       = (int) $this->auditor['id_usuario'];

        $usuarios->update($id, ['ultimo_acesso_em' => date('Y-m-d H:i:s')]);
        $this->seeNumRecords(0, 'logs_auditoria', ['tabela_afetada' => 'usuarios', 'registro_id' => $id]);

        $usuarios->update($id, ['senha_hash' => password_hash('outra-senha-123', PASSWORD_DEFAULT)]);
        $log = $this->ultimoLog('usuarios', $id);
        $this->assertContains('senha_hash', json_decode($log['campos_alterados'], true));
        $this->assertSame('***', json_decode($log['dados_novos'], true)['senha_hash']);
        $this->assertStringNotContainsString('$2y$', $log['dados_anteriores'] . $log['dados_novos']);

        (new AnonimizadorUsuario())->anonimizar($id);
        $this->assertSame('anonimizacao', $this->ultimoLog('usuarios', $id)['acao']);
    }

    public function testConsultaLimitadaAoEscopo(): void
    {
        $itens = new ItemModel();
        $itens->update($this->itemA, ['numero_patrimonio' => 'FJ-A2']);
        $itens->update($this->itemB, ['numero_patrimonio' => 'FJ-B2']);
        (new UsuarioModel())->update((int) $this->direcao['id_usuario'], ['nome' => 'Diretor Geral']);

        $logA       = (int) $this->ultimoLog('itens', $this->itemA)['id_log_auditoria'];
        $logB       = (int) $this->ultimoLog('itens', $this->itemB)['id_log_auditoria'];
        $logUsuario = (int) $this->ultimoLog('usuarios', (int) $this->direcao['id_usuario'])['id_log_auditoria'];

        $this->como($this->auditor)->get("auditoria/{$logA}")->assertOK();
        $this->como($this->auditor)->get("auditoria/{$logB}")->assertRedirectTo(site_url('auditoria'));
        $this->como($this->auditor)->get("auditoria/{$logUsuario}")->assertRedirectTo(site_url('auditoria'));

        $lista = $this->como($this->auditor)->get('auditoria?busca=Item');
        $lista->assertSee("auditoria/{$logA}");
        $lista->assertDontSee("auditoria/{$logB}");

        // O superperfil vê tudo; quem não tem a permissão não entra.
        $this->como($this->direcao)->get("auditoria/{$logB}")->assertOK();
        $this->como($this->direcao)->get("auditoria/{$logUsuario}")->assertOK();
        $docente = $this->criarUsuario();
        $this->vincular($docente['id_usuario'], 'Docentes');
        $this->como($docente)->get('auditoria')->assertStatus(403);
    }

    public function testExpurgoSoDoSuperusuarioAlemDaRetencaoEComConfirmo(): void
    {
        // O setUp já gerou registros (perfil Auditor criado pelo Model): parte do log vazio.
        $this->db->table('logs_auditoria')->emptyTable();

        $antigo = date('Y-m-d H:i:s', strtotime('-6 years'));
        $this->db->table('logs_auditoria')->insert([
            'tabela_afetada' => 'campus', 'registro_id' => 1, 'acao' => 'insercao', 'dados_novos' => '{}', 'criado_em' => $antigo,
        ]);
        (new ItemModel())->update($this->itemA, ['numero_patrimonio' => 'FJ-A2']);
        $this->seeNumRecords(2, 'logs_auditoria', []);

        $limite = Auditoria::limiteExpurgo()->format('Y-m-d');

        $this->como($this->auditor)->excluir('auditoria/expurgo', 'CONFIRMO', ['data' => $limite])->assertStatus(403);

        // Dentro da retenção, ou sem CONFIRMO: nada sai.
        $this->como($this->direcao)->excluir('auditoria/expurgo', 'CONFIRMO', ['data' => date('Y-m-d')])->assertRedirectTo(site_url('auditoria'));
        $this->como($this->direcao)->excluir('auditoria/expurgo', 'confirmo', ['data' => $limite])->assertRedirectTo(site_url('auditoria'));
        $this->seeNumRecords(2, 'logs_auditoria', []);

        $this->como($this->direcao)->excluir('auditoria/expurgo', 'CONFIRMO', ['data' => $limite])->assertRedirectTo(site_url('auditoria'));
        $this->assertSame('1 registro expurgado.', $_SESSION['sucesso']);
        $this->seeNumRecords(1, 'logs_auditoria', []);
        $this->seeInDatabase('logs_auditoria', ['tabela_afetada' => 'itens']);

        // Só o superusuário vê o botão de expurgo.
        $this->como($this->direcao)->get('auditoria')->assertSee('Expurgar log');
        $this->como($this->auditor)->get('auditoria')->assertDontSee('Expurgar log');
    }
}
