<?php

namespace App\Libraries\Opcoes;

use App\Libraries\Academico\GestaoAtribuicoes;
use App\Libraries\Autorizacao;
use App\Libraries\Patrimonio\Locais;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Catálogo das fontes de opções dos selects do banco (service('opcoes')). Cada método monta a consulta de
 * uma lista, já com escopo e filtros; controllers usam os métodos direto (validar o valor postado com
 * contem(), rótulo da opção escolhida) e a rota opcoes/<nome> usa porNome(), que aplica a permissão.
 *
 * Regras da rota (porNome):
 * - públicas (consulta sem login): campi-ativos, cursos, disciplinas;
 * - as demais exigem login;
 * - listas por escopo (ambientes, locais, campi, predios) recebem chave/nivel na URL e usam o escopo que o
 *   próprio usuário tem nessa permissão: nunca mostram algo fora do que ele já alcança;
 * - listas de pessoas exigem a permissão da tela que as usa (USUARIOS_CHAVES).
 */
final class Registro
{
    /** Permissões cujas telas escolhem uma pessoa entre os usuários ativos. */
    private const USUARIOS_CHAVES = [
        'agendamento.alocar',
        'agendamento.reservar_em_nome_de_terceiros',
        'chamados.resolver_cancelar',
    ];

    /** Campus nas opções de select: abreviação, ou o nome na falta dela. */
    private const CAMPUS_ROTULO = "COALESCE(NULLIF(TRIM(c.abreviacao), ''), c.nome)";

    private const ORDEM_AMBIENTE = "CONCAT(c.nome, '\\t', p.nome, '\\t', LPAD(am.numero, 20, '0'))";

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Autorizacao $autorizacao,
        private readonly bool $logado,
        private readonly int $usuarioId = 0,
    ) {
    }

    /**
     * Fonte pedida pela rota opcoes/<nome>, com os parâmetros da URL; null = inexistente ou sem permissão.
     *
     * @param array<string, mixed> $params
     */
    public function porNome(string $nome, array $params): ?Fonte
    {
        $inteiro = static fn (string $campo): ?int => isset($params[$campo]) && ctype_digit((string) $params[$campo])
            ? (int) $params[$campo]
            : null;

        switch ($nome) {
            case 'campi-ativos':
                return $this->campiAtivos();

            case 'cursos':
                return $this->cursos();

            case 'disciplinas':
                return $this->disciplinas($inteiro('curso'));
        }

        if (! $this->logado) {
            return null;
        }

        $chave = (string) ($params['chave'] ?? '');
        $chave = preg_match('/^[a-z_]+\.[a-z_]+$/', $chave) === 1 ? $chave : '';
        $nivel = ($params['nivel'] ?? '') === Autorizacao::EDITOR ? Autorizacao::EDITOR : Autorizacao::LEITOR;
        $escopo = $chave === ''
            ? ['global' => false, 'campus' => [], 'predio' => []]
            : $this->autorizacao->escopoPara($chave, $nivel);

        return match ($nome) {
            'ambientes'           => $this->ambientes($escopo),
            'locais'              => $this->locais($escopo),
            'locais-destino'      => $this->autorizacao->pode('patrimonio.acesso', Autorizacao::EDITOR)
                ? $this->locais(self::global(), array_map('strval', (array) ($params['exceto'] ?? [])))
                : null,
            'campi'               => $this->campi(
                $escopo,
                ($params['predios'] ?? '') === '1',
                ($params['sem_almoxarifado'] ?? '') === '1'
            ),
            'predios'             => $this->predios($escopo, $inteiro('campus')),
            'usuarios'            => $this->podeEscolherPessoa($chave, $nivel) ? $this->usuariosAtivos() : null,
            'pessoas'             => $this->podeEscolherPessoaAcademica() ? $this->pessoas() : null,
            'disciplinas-do-professor' => $this->disciplinasDoProfessor(
                $this->podeEscolherPessoa($chave, $nivel) ? ($inteiro('professor') ?? 0) : $this->usuarioId
            ),
            'disciplinas-atribuiveis' => $this->gestao()->gereAlgum() ? $this->disciplinasAtribuiveis() : null,
            'destinatarios'       => $this->autorizacao->pode('patrimonio.acesso', Autorizacao::EDITOR)
                ? $this->destinatarios()
                : null,
            'perfis'              => $this->autorizacao->pode('usuarios.acesso', Autorizacao::EDITOR)
                ? $this->perfis($this->autorizacao->ehSuper())
                : null,
            'vinculos'            => $this->autorizacao->pode('agendamento.alocar', Autorizacao::EDITOR)
                ? $this->vinculos($inteiro('semestre') ?? 0)
                : null,
            'semestres'           => $this->semestres(),
            'semestres-abertos'   => $this->semestresAbertos(),
            'categorias-ambiente' => $this->simples('categorias_ambientes', 'id_categoria_ambiente'),
            'categorias-item'     => $this->simples('categorias_itens', 'id_categoria_item'),
            'motivos-descarte'    => $this->simples('motivos_descarte', 'id_motivo_descarte'),
            'modelos'             => $this->modelos(),
            'softwares'           => $this->softwares($inteiro('fora_ambiente'), $inteiro('fora_requisito')),
            'itens-para-ambiente' => $this->autorizacao->pode('patrimonio.acesso', Autorizacao::EDITOR)
                ? $this->itensParaAmbiente(
                    $inteiro('ambiente') ?? 0,
                    $this->autorizacao->escopoPara('patrimonio.acesso', Autorizacao::EDITOR)
                )
                : null,
            default               => null,
        };
    }

    /** A tela que escolhe uma pessoa exige a permissão dela (e só as telas que escolhem pessoas contam). */
    private function podeEscolherPessoa(string $chave, string $nivel): bool
    {
        return in_array($chave, self::USUARIOS_CHAVES, true) && $this->autorizacao->pode($chave, $nivel);
    }

    /** Responsável por curso e quem gere atribuições escolhem pessoas (curso, atribuição de aula). */
    private function podeEscolherPessoaAcademica(): bool
    {
        return $this->autorizacao->pode('academico.acesso', Autorizacao::EDITOR) || $this->gestao()->gereAlgum();
    }

    private function gestao(): GestaoAtribuicoes
    {
        return new GestaoAtribuicoes($this->db, $this->autorizacao, $this->usuarioId);
    }

    /** @return array{global: bool, campus: list<int>, predio: list<int>} */
    public static function global(): array
    {
        return ['global' => true, 'campus' => [], 'predio' => []];
    }

    /**
     * Ambientes ativos que o escopo alcança: "Campus › Prédio › número nome".
     *
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     */
    public function ambientes(array $escopo): Fonte
    {
        return new Fonte(function () use ($escopo): BaseBuilder {
            $builder = $this->db->table('ambientes am')
                ->select('am.id_ambiente AS valor', false)
                ->select(
                    "CONCAT(" . self::CAMPUS_ROTULO . ", ' › ', p.nome, ' › ', am.numero, ' ', am.nome) AS rotulo",
                    false
                )
                ->select(self::ORDEM_AMBIENTE . ' AS ordem', false)
                ->join('predios p', 'p.id_predio = am.predio_id AND p.deletado_em IS NULL')
                ->join('campus c', 'c.id_campus = p.campus_id AND c.deletado_em IS NULL')
                ->where('am.deletado_em', null)
                ->where('am.ativo', 1);

            return $this->noEscopo($builder, $escopo, 'p.campus_id', 'am.predio_id');
        });
    }

    /**
     * Locais do patrimônio que o escopo alcança: almoxarifados ("nome — campus") e depois ambientes ativos.
     * Valor "almoxarifado:<id>" ou "ambiente:<id>" (App\Libraries\Patrimonio\Locais).
     *
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     * @param list<string>                                              $exceto fora da lista (onde o item já está)
     */
    public function locais(array $escopo, array $exceto = []): Fonte
    {
        return new Fonte(function () use ($escopo, $exceto): BaseBuilder {
            $almoxarifados = $this->db->table('almoxarifados a')
                ->select("CONCAT('almoxarifado:', a.id_almoxarifado) AS valor", false)
                ->select("CONCAT(a.nome, ' — ', " . self::CAMPUS_ROTULO . ') AS rotulo', false)
                ->select("CONCAT('0\\t', c.nome, '\\t', a.nome) AS ordem", false)
                ->join('campus c', 'c.id_campus = a.campus_id AND c.deletado_em IS NULL')
                ->where('a.deletado_em', null);

            if (! $escopo['global']) {
                $almoxarifados->whereIn('a.campus_id', $escopo['campus'] === [] ? [0] : $escopo['campus']);
            }

            $ambientes = $this->db->table('ambientes am')
                ->select("CONCAT('ambiente:', am.id_ambiente) AS valor", false)
                ->select(
                    "CONCAT(" . self::CAMPUS_ROTULO . ", ' › ', p.nome, ' › ', am.numero, ' ', am.nome) AS rotulo",
                    false
                )
                ->select("CONCAT('1\\t', " . self::ORDEM_AMBIENTE . ') AS ordem', false)
                ->join('predios p', 'p.id_predio = am.predio_id AND p.deletado_em IS NULL')
                ->join('campus c', 'c.id_campus = p.campus_id AND c.deletado_em IS NULL')
                ->where('am.deletado_em', null)
                ->where('am.ativo', 1);

            $this->noEscopo($ambientes, $escopo, 'p.campus_id', 'am.predio_id');

            $uniao = $this->db->newQuery()->fromSubquery($almoxarifados->union($ambientes), 'l')
                ->select('l.valor, l.rotulo, l.ordem');

            if ($exceto !== []) {
                $uniao->whereNotIn('l.valor', $exceto);
            }

            return $uniao;
        });
    }

    /**
     * Campi que o escopo alcança. $dePredios: também o campus de quem só tem escopo de prédio (relatórios);
     * $semAlmoxarifado: só campi ainda sem almoxarifado.
     *
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     */
    public function campi(array $escopo, bool $dePredios = false, bool $semAlmoxarifado = false): Fonte
    {
        return new Fonte(function () use ($escopo, $dePredios, $semAlmoxarifado): BaseBuilder {
            $builder = $this->db->table('campus c')
                ->select('c.id_campus AS valor', false)
                ->select(self::CAMPUS_ROTULO . ' AS rotulo', false)
                ->select('c.nome AS ordem')
                ->where('c.deletado_em', null);

            if (! $escopo['global']) {
                $builder->groupStart()
                    ->whereIn('c.id_campus', $escopo['campus'] === [] ? [0] : $escopo['campus']);

                if ($dePredios && $escopo['predio'] !== []) {
                    $builder->orWhere(
                        'c.id_campus IN (SELECT campus_id FROM predios WHERE id_predio IN ('
                        . implode(', ', array_map('intval', $escopo['predio'])) . '))',
                        null,
                        false
                    );
                }

                $builder->groupEnd();
            }

            if ($semAlmoxarifado) {
                $builder->where(
                    'NOT EXISTS (SELECT 1 FROM almoxarifados a'
                    . ' WHERE a.campus_id = c.id_campus AND a.deletado_em IS NULL)',
                    null,
                    false
                );
            }

            return $builder;
        });
    }

    /** Campi ativos (consulta pública). */
    public function campiAtivos(): Fonte
    {
        return new Fonte(fn (): BaseBuilder => $this->db->table('campus c')
            ->select('c.id_campus AS valor', false)
                ->select(self::CAMPUS_ROTULO . ' AS rotulo', false)
                ->select('c.nome AS ordem')
            ->where('c.deletado_em', null)
            ->where('c.ativo', 1));
    }

    /**
     * Prédios que o escopo alcança, "Campus › Prédio"; com $campusId, só os desse campus.
     *
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     */
    public function predios(array $escopo, ?int $campusId = null): Fonte
    {
        return new Fonte(function () use ($escopo, $campusId): BaseBuilder {
            $builder = $this->db->table('predios p')
                ->select('p.id_predio AS valor', false)
                ->select("CONCAT(" . self::CAMPUS_ROTULO . ", ' › ', p.nome) AS rotulo", false)
                ->select("CONCAT(c.nome, '\\t', p.nome) AS ordem", false)
                ->join('campus c', 'c.id_campus = p.campus_id AND c.deletado_em IS NULL')
                ->where('p.deletado_em', null);

            if ($campusId !== null) {
                $builder->where('p.campus_id', $campusId);
            }

            return $this->noEscopo($builder, $escopo, 'p.campus_id', 'p.id_predio');
        });
    }

    /** Usuários ativos (professor, beneficiário de reserva). */
    public function usuariosAtivos(): Fonte
    {
        return new Fonte(fn (): BaseBuilder => $this->db->table('usuarios u')
            ->select('u.id_usuario AS valor, u.nome AS rotulo, u.nome AS ordem')
            ->where('u.deletado_em', null)
            ->where('u.ativo', 1));
    }

    /**
     * Pessoas ativas (não anonimizadas), "Nome · e-mail · CPF". A pesquisa vale para qualquer um dos três, o
     * CPF com ou sem pontuação.
     */
    public function pessoas(): Fonte
    {
        $cpf = "CONCAT(SUBSTR(u.cpf, 1, 3), '.', SUBSTR(u.cpf, 4, 3), '.', SUBSTR(u.cpf, 7, 3), '-',"
            . " SUBSTR(u.cpf, 10, 2))";

        return new Fonte(fn (): BaseBuilder => $this->db->table('usuarios u')
            ->select('u.id_usuario AS valor, u.nome AS ordem', false)
            ->select("CONCAT_WS(' · ', u.nome, u.email, IF(u.cpf IS NULL, NULL, {$cpf})) AS rotulo", false)
            ->select("CONCAT_WS(' ', u.nome, u.email, u.cpf, IF(u.cpf IS NULL, NULL, {$cpf})) AS busca", false)
            ->where('u.deletado_em', null)
            ->where('u.anonimizado_em', null)
            ->where('u.ativo', 1), 'busca');
    }

    /** Disciplinas dos cursos que o usuário gere (todos, com a permissão), "SIGLA · Nome (Curso)". */
    public function disciplinasAtribuiveis(): Fonte
    {
        return new Fonte(function (): BaseBuilder {
            $builder = $this->db->table('disciplinas d')
                ->select('d.id_disciplina AS valor', false)
                ->select("CONCAT(d.sigla, ' · ', d.nome, ' (', cu.abreviacao, ')') AS rotulo", false)
                ->select("CONCAT(cu.nome, '\t', d.nome) AS ordem", false)
                ->join('cursos cu', 'cu.id_curso = d.curso_id AND cu.deletado_em IS NULL')
                ->where('d.deletado_em', null);

            if (! $this->gestao()->gereTodos()) {
                $builder->where('cu.responsavel_id', $this->usuarioId);
            }

            return $builder;
        });
    }

    /** Quem pode confirmar recebimento de patrimônio em algum escopo (destinatário de uma movimentação). */
    public function destinatarios(): Fonte
    {
        return new Fonte(fn (): BaseBuilder => $this->db->table('usuarios u')
            ->distinct()
            ->select('u.id_usuario AS valor, u.nome AS rotulo, u.nome AS ordem')
            ->join('usuarios_perfis up', 'up.usuario_id = u.id_usuario AND up.deletado_em IS NULL')
            ->join('perfis pf', 'pf.id_perfil = up.perfil_id AND pf.deletado_em IS NULL')
            ->join('perfis_permissoes pp', 'pp.perfil_id = pf.id_perfil AND pp.deletado_em IS NULL', 'left')
            ->join('permissoes pe', 'pe.id_permissao = pp.permissao_id AND pe.deletado_em IS NULL', 'left')
            ->where('u.deletado_em', null)
            ->where('u.ativo', 1)
            ->groupStart()
                ->where('pf.super_perfil', 1)
                ->orWhere('pe.chave', 'patrimonio.confirmar_recebimento')
            ->groupEnd());
    }

    /** Perfis atribuíveis: o superperfil só aparece para quem é superperfil. */
    public function perfis(bool $comSuper): Fonte
    {
        return new Fonte(function () use ($comSuper): BaseBuilder {
            $builder = $this->db->table('perfis pf')
                ->select('pf.id_perfil AS valor, pf.nome AS rotulo, pf.nome AS ordem')
                ->where('pf.deletado_em', null);

            if (! $comSuper) {
                $builder->where('pf.super_perfil', 0);
            }

            return $builder;
        });
    }

    /** Cursos ativos. */
    public function cursos(): Fonte
    {
        return new Fonte(fn (): BaseBuilder => $this->db->table('cursos cu')
            ->select('cu.id_curso AS valor, cu.nome AS rotulo, cu.nome AS ordem')
            ->where('cu.deletado_em', null));
    }

    /** Disciplinas, "SIGLA · Nome (Curso)"; com $cursoId, só as desse curso (e sem o sufixo, que seria redundante). */
    public function disciplinas(?int $cursoId = null): Fonte
    {
        return new Fonte(function () use ($cursoId): BaseBuilder {
            $builder = $this->db->table('disciplinas d')
                ->select('d.id_disciplina AS valor', false)
                ->join('cursos cu', 'cu.id_curso = d.curso_id AND cu.deletado_em IS NULL')
                ->where('d.deletado_em', null);

            if ($cursoId !== null) {
                $builder->select("CONCAT(d.sigla, ' · ', d.nome) AS rotulo", false)
                    ->select('d.nome AS ordem')
                    ->where('d.curso_id', $cursoId);
            } else {
                $builder->select("CONCAT(d.sigla, ' · ', d.nome, ' (', cu.abreviacao, ')') AS rotulo", false)
                    ->select("CONCAT(cu.nome, '\t', d.nome) AS ordem", false);
            }

            return $builder;
        });
    }

    /** Disciplinas atribuídas ao professor (em qualquer semestre), "SIGLA · Nome (Curso)". */
    public function disciplinasDoProfessor(int $professorId): Fonte
    {
        return new Fonte(fn (): BaseBuilder => $this->db->table('disciplinas d')
            ->distinct()
            ->select('d.id_disciplina AS valor', false)
            ->select("CONCAT(d.sigla, ' · ', d.nome, ' (', cu.abreviacao, ')') AS rotulo", false)
            ->select("CONCAT(cu.nome, '\t', d.nome) AS ordem", false)
            ->join('cursos cu', 'cu.id_curso = d.curso_id AND cu.deletado_em IS NULL')
            ->join('professores_disciplinas pd', 'pd.disciplina_id = d.id_disciplina AND pd.deletado_em IS NULL')
            ->where('d.deletado_em', null)
            ->where('pd.professor_id', $professorId));
    }

    /** Vínculos professor-disciplina do semestre, "SIGLA · Disciplina — Professor". */
    public function vinculos(int $semestreId): Fonte
    {
        return new Fonte(fn (): BaseBuilder => $this->db->table('professores_disciplinas pd')
            ->select('pd.id_professor_disciplina AS valor', false)
            ->select("CONCAT(d.sigla, ' · ', d.nome, ' — ', u.nome) AS rotulo", false)
            ->select("CONCAT(d.nome, '\t', u.nome) AS ordem", false)
            ->join('disciplinas d', 'd.id_disciplina = pd.disciplina_id AND d.deletado_em IS NULL')
            ->join('usuarios u', 'u.id_usuario = pd.professor_id AND u.deletado_em IS NULL')
            ->where('pd.deletado_em', null)
            ->where('pd.semestre_id', $semestreId));
    }

    /** Todos os semestres, do mais recente ao mais antigo. */
    public function semestres(): Fonte
    {
        return new Fonte(fn (): BaseBuilder => $this->db->table('semestres s')
            ->select('s.id_semestre AS valor, s.nome AS rotulo')
            ->select('(0 - TO_DAYS(s.data_inicio)) AS ordem', false)
            ->where('s.deletado_em', null));
    }

    /** Semestres ainda não encerrados, do mais recente ao mais antigo. */
    public function semestresAbertos(): Fonte
    {
        return new Fonte(fn (): BaseBuilder => $this->db->table('semestres s')
            ->select('s.id_semestre AS valor, s.nome AS rotulo')
            ->select('(0 - TO_DAYS(s.data_inicio)) AS ordem', false)
            ->where('s.deletado_em', null)
            ->where('s.status <>', 'encerrado'));
    }

    /** Modelos de item (moldes para criar itens), "Nome · marca". */
    public function modelos(): Fonte
    {
        return new Fonte(fn (): BaseBuilder => $this->db->table('modelos_itens m')
            ->select('m.id_modelo_item AS valor, m.nome AS ordem', false)
            ->select(
                "TRIM(CONCAT(m.nome, IF(m.marca IS NULL OR m.marca = '', '', CONCAT(' · ', m.marca)))) AS rotulo",
                false
            )
            ->where('m.deletado_em', null));
    }

    /** Softwares, "Nome versão"; sem os já instalados no ambiente ou já exigidos pelo requisito informados. */
    public function softwares(?int $foraDoAmbiente = null, ?int $foraDoRequisito = null): Fonte
    {
        return new Fonte(function () use ($foraDoAmbiente, $foraDoRequisito): BaseBuilder {
            $builder = $this->db->table('softwares s')
                ->select('s.id_software AS valor', false)
                ->select("TRIM(CONCAT(s.nome, ' ', COALESCE(s.versao, ''))) AS rotulo", false)
                ->select("CONCAT(s.nome, '\\t', COALESCE(s.versao, '')) AS ordem", false)
                ->where('s.status', 'aprovado')
                ->where('s.deletado_em', null);

            if ($foraDoAmbiente !== null) {
                $builder->where(
                    's.id_software NOT IN (SELECT software_id FROM ambientes_softwares WHERE deletado_em IS NULL'
                    . ' AND ambiente_id = ' . $foraDoAmbiente . ')',
                    null,
                    false
                );
            }

            if ($foraDoRequisito !== null) {
                $builder->where(
                    's.id_software NOT IN (SELECT software_id FROM requisitos_disciplina_softwares'
                    . ' WHERE deletado_em IS NULL AND requisito_disciplina_id = ' . $foraDoRequisito . ')',
                    null,
                    false
                );
            }

            return $builder;
        });
    }

    /**
     * Itens que podem ser enviados a um ambiente: ativos, parados num local (nem em trânsito, nem descartados),
     * fora do ambiente, num local onde o usuário é editor de patrimônio e sem pendência de número (patrimoniável
     * sem número não pode ser movido). "Nº patrimônio · nome · local atual"; item sem patrimônio leva a quantidade.
     *
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     */
    public function itensParaAmbiente(int $ambienteId, array $escopo): Fonte
    {
        return new Fonte(function () use ($ambienteId, $escopo): BaseBuilder {
            $numero = "COALESCE(i.numero_patrimonio, {$this->db->escape(lang('Patrimonio.noAsset'))})";

            $builder = $this->db->table('itens i')
                ->select('i.id_item AS valor', false)
                ->select(
                    "CONCAT({$numero}, ' · ', i.nome, "
                    . "IF(i.quantidade > 1, CONCAT(' (×', i.quantidade, ')'), ''), ' · ', "
                    . Locais::rotuloSql('l') . ') AS rotulo',
                    false
                )
                ->select("CONCAT({$numero}, '\\t', i.id_item) AS ordem", false)
                ->whereIn('i.status', ['em_uso', 'almoxarifado'])
                ->where('(i.patrimoniavel = 0 OR i.numero_patrimonio IS NOT NULL)', null, false)
                ->where('i.deletado_em', null)
                ->where('(i.ambiente_id IS NULL OR i.ambiente_id <> ' . $ambienteId . ')', null, false)
                ->where(
                    "i.id_item NOT IN (SELECT item_id FROM descartes
                     WHERE status = 'pendente' AND deletado_em IS NULL)",
                    null,
                    false
                );

            Locais::juntar($builder, 'l', 'i.ambiente_id', 'i.almoxarifado_id');

            $condicao = Locais::condicaoEscopo($escopo, 'l');

            if ($condicao !== null) {
                $builder->where($condicao, null, false);
            }

            return $builder;
        });
    }

    /** Catálogo simples por nome (categorias, motivos de descarte). */
    public function simples(string $tabela, string $chavePrimaria): Fonte
    {
        return new Fonte(fn (): BaseBuilder => $this->db->table($tabela . ' t')
            ->select("t.{$chavePrimaria} AS valor, t.nome AS rotulo, t.nome AS ordem")
            ->where('t.deletado_em', null));
    }

    /**
     * Restringe ao escopo: global, ou campus da lista, ou prédio da lista.
     *
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     */
    private function noEscopo(
        BaseBuilder $builder,
        array $escopo,
        string $colunaCampus,
        string $colunaPredio
    ): BaseBuilder {
        if ($escopo['global']) {
            return $builder;
        }

        return $builder->groupStart()
            ->whereIn($colunaCampus, $escopo['campus'] === [] ? [0] : $escopo['campus'])
            ->orWhereIn($colunaPredio, $escopo['predio'] === [] ? [0] : $escopo['predio'])
            ->groupEnd();
    }
}
