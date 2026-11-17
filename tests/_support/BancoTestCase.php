<?php

namespace Tests\Support;

use App\Database\Seeds\DatabaseSeeder;
use App\Models\Institucional\PerfilModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Base dos testes que precisam do banco de testes (hoje o próprio `ambienta`) (migrations + dados fixos dos seeders).
 *
 * As migrations e os seeders rodam uma vez por processo; a cada teste as tabelas que os testes
 * alteram (usuários, vínculos, domínios, campus, prédios e perfis criados) são limpas, então um
 * teste nunca enxerga o que o anterior criou.
 */
abstract class BancoTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    public const SENHA = 'senha-correta-123';

    /** Mesmo grupo (e histórico de migrations) do `php spark migrate`: o banco de testes é o próprio ambienta. */
    protected $DBGroup     = 'default';
    protected $namespace   = 'App';
    protected $seed        = DatabaseSeeder::class;
    protected $refresh     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;

    private static int $sequencia = 0;

    protected function setUp(): void
    {
        $_SERVER['admin.password'] = 'senha-de-teste-123';

        parent::setUp();

        $this->limparDadosMutaveis();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['admin.password']);

        parent::tearDown();
    }

    private function limparDadosMutaveis(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        $tabelas = [
            'usuarios_perfis', 'usuarios', 'dominios_email_permitidos', 'alocacoes_semestrais', 'ambientes',
            'categorias_ambientes', 'predios', 'campus', 'professores_disciplinas', 'disciplinas', 'cursos', 'semestres',
            'descartes', 'movimentacoes_itens', 'itens',
            'modelos_itens', 'categorias_itens', 'almoxarifados', 'ambientes_softwares', 'softwares',
            'configuracoes_campus', 'requisitos_disciplina_itens', 'requisitos_disciplina_softwares',
            'requisitos_disciplina', 'chamados_historico', 'chamados', 'conflitos_agendamento',
            'alocacoes_semestrais_excecoes', 'reservas_itens_extras', 'reservas_softwares_extras', 'reservas_pontuais',
            'recorrencias', 'bloqueios_ambientes', 'feriados', 'notificacoes', 'preferencias_notificacao',
            'logs_auditoria',
        ];

        foreach ($tabelas as $tabela) {
            $this->db->query("TRUNCATE TABLE {$tabela}");
        }

        // Perfis criados por testes (os quatro de partida ficam).
        $padrao = "'Direção', 'Coordenação', 'Auxiliares', 'Docentes'";
        $this->db->query(
            "DELETE FROM perfis_permissoes WHERE perfil_id IN (SELECT id_perfil FROM perfis WHERE nome NOT IN ({$padrao}))"
        );
        $this->db->query("DELETE FROM perfis WHERE nome NOT IN ({$padrao})");

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed> o usuário criado, com id_usuario
     */
    protected function criarUsuario(array $campos = []): array
    {
        $n = ++self::$sequencia;

        $dados = $campos + [
            'nome'             => "Usuário {$n}",
            'email'            => "usuario{$n}@fatec.sp.gov.br",
            'senha_hash'       => password_hash(self::SENHA, PASSWORD_DEFAULT),
            'idioma_preferido' => 'pt-BR',
            'ativo'            => 1,
        ];

        $this->db->table('usuarios')->insert($dados);

        return $dados + ['id_usuario' => (int) $this->db->insertID()];
    }

    /**
     * Perfil personalizado com a matriz dada (chave => leitor|editor); some no próximo teste.
     *
     * @param array<string, string> $matriz
     */
    protected function criarPerfil(string $nome, array $matriz): int
    {
        $perfis = new PerfilModel();
        $id     = (int) $perfis->insert(['nome' => $nome, 'super_perfil' => 0]);
        $perfis->sincronizarPermissoes($id, $matriz);

        return $id;
    }

    protected function perfilId(string $nome): int
    {
        return (int) $this->db->table('perfis')->where('nome', $nome)->get()->getRow()->id_perfil;
    }

    protected function vincular(
        int $usuarioId,
        string $perfil,
        string $escopo = 'global',
        ?int $campusId = null,
        ?int $predioId = null
    ): int {
        $this->db->table('usuarios_perfis')->insert([
            'usuario_id'  => $usuarioId,
            'perfil_id'   => $this->perfilId($perfil),
            'escopo_tipo' => $escopo,
            'campus_id'   => $campusId,
            'predio_id'   => $predioId,
        ]);

        return (int) $this->db->insertID();
    }

    protected function criarCampus(string $nome): int
    {
        $this->db->table('campus')->insert(['nome' => $nome, 'abreviacao' => substr($nome, 0, 20)]);

        return (int) $this->db->insertID();
    }

    protected function criarPredio(int $campusId, string $nome): int
    {
        $this->db->table('predios')->insert([
            'campus_id'  => $campusId,
            'nome'       => $nome,
            'abreviacao' => substr($nome, 0, 20),
        ]);

        return (int) $this->db->insertID();
    }

    protected function criarCategoria(string $nome = 'Sala de aula'): int
    {
        $this->db->table('categorias_ambientes')->insert(['nome' => $nome]);

        return (int) $this->db->insertID();
    }

    protected function criarAmbiente(int $predioId, string $numero, string $nome, ?int $categoriaId = null): int
    {
        $this->db->table('ambientes')->insert([
            'predio_id'             => $predioId,
            'categoria_ambiente_id' => $categoriaId ?? $this->criarCategoria('Categoria ' . $numero),
            'numero'                => $numero,
            'abreviacao'            => $numero,
            'nome'                  => $nome,
            'capacidade'            => 40,
        ]);

        return (int) $this->db->insertID();
    }

    /** Sem $responsavelId, o responsável é um usuário novo (cursos.responsavel_id é obrigatório). */
    protected function criarCurso(string $nome, string $tipo = 'graduacao', ?int $responsavelId = null): int
    {
        $this->db->table('cursos')->insert([
            'nome'           => $nome,
            'abreviacao'     => substr($nome, 0, 20),
            'tipo'           => $tipo,
            'responsavel_id' => $responsavelId ?? (int) $this->criarUsuario()['id_usuario'],
        ]);

        return (int) $this->db->insertID();
    }

    /** Sem $cursoId, a disciplina vai para um curso padrão de teste (criado uma vez por teste). */
    protected function criarDisciplina(string $sigla, string $nome, ?int $cursoId = null): int
    {
        if ($cursoId === null) {
            $padrao  = $this->db->table('cursos')->where('nome', 'Curso padrão de teste')->get()->getRow();
            $cursoId = $padrao !== null ? (int) $padrao->id_curso : $this->criarCurso('Curso padrão de teste');
        }

        $this->db->table('disciplinas')->insert(['curso_id' => $cursoId, 'sigla' => $sigla, 'nome' => $nome]);

        return (int) $this->db->insertID();
    }

    /** Vínculo professor-disciplina no semestre (exigido pela alocação semestral). */
    protected function vincularProfessor(int $semestreId, int $disciplinaId, int $professorId): int
    {
        $this->db->table('professores_disciplinas')->insert([
            'semestre_id'   => $semestreId,
            'disciplina_id' => $disciplinaId,
            'professor_id'  => $professorId,
        ]);

        return (int) $this->db->insertID();
    }

    protected function criarCategoriaItem(string $nome = 'Informática'): int
    {
        $this->db->table('categorias_itens')->insert(['nome' => $nome]);

        return (int) $this->db->insertID();
    }

    protected function criarModelo(string $nome, bool $patrimoniavel = true, ?int $categoriaId = null): int
    {
        $this->db->table('modelos_itens')->insert([
            'categoria_item_id' => $categoriaId ?? $this->criarCategoriaItem('Categoria de ' . $nome),
            'nome'              => $nome,
            'patrimoniavel'     => $patrimoniavel ? 1 : 0,
        ]);

        return (int) $this->db->insertID();
    }

    protected function criarAlmoxarifado(int $campusId, string $nome = 'Almoxarifado Central'): int
    {
        $this->db->table('almoxarifados')->insert(['campus_id' => $campusId, 'nome' => $nome]);

        return (int) $this->db->insertID();
    }

    /**
     * Item num ambiente ou num almoxarifado (um dos dois), com os dados copiados do modelo (nome, categoria,
     * marca, especificações e se é patrimoniável). Sem número, um patrimoniável fica pendente.
     */
    protected function criarItem(
        int $modeloId,
        ?int $ambienteId,
        ?int $almoxarifadoId = null,
        ?string $numero = null,
        int $quantidade = 1
    ): int {
        $modelo = $this->db->table('modelos_itens')->where('id_modelo_item', $modeloId)->get()->getRowArray();

        $this->db->table('itens')->insert([
            'modelo_item_id'    => $modeloId,
            'categoria_item_id' => $modelo['categoria_item_id'],
            'nome'              => $modelo['nome'],
            'marca'             => $modelo['marca'],
            'especificacoes'    => $modelo['especificacoes'],
            'patrimoniavel'     => $modelo['patrimoniavel'],
            'numero_patrimonio' => $numero,
            'quantidade'        => (int) $modelo['patrimoniavel'] === 1 ? 1 : $quantidade,
            'ambiente_id'       => $ambienteId,
            'almoxarifado_id'   => $almoxarifadoId,
            'status'            => $almoxarifadoId === null ? 'em_uso' : 'almoxarifado',
        ]);

        return (int) $this->db->insertID();
    }

    protected function criarSemestre(string $nome, string $status = 'aberto'): int
    {
        $this->db->table('semestres')->insert([
            'nome'        => $nome,
            'data_inicio' => '2026-08-01',
            'data_fim'    => '2026-12-15',
            'status'      => $status,
        ]);

        return (int) $this->db->insertID();
    }
}
