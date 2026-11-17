<?php

namespace App\Controllers\Agendamento;

use App\Libraries\Agendamento\Feriados as FeriadosServico;
use App\Libraries\Agendamento\Recusa;
use App\Libraries\Autorizacao;
use App\Libraries\Opcoes\Fonte;
use App\Tabelas\Agendamento\FeriadosTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Feriados: listagem (os gerais e os dos campi visíveis) e cadastro manual em modal. Feriado de um campus:
 * quem gere o campus (institucional.acesso editor); para todos os campi: só o superperfil. Os nacionais vêm
 * da API pelo comando `php spark feriados:sincronizar`.
 */
class Feriados extends ControladorAgendamento
{
    private const LISTA = 'agendamento/feriados';

    public function index(): string
    {
        $tabela = $this->tabela($this->novaTabela());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Agendamento.holidaysTitle'),
            'intro'  => lang('Agendamento.holidaysIntro'),
            'novo'   => $this->podeCadastrar()
                ? [lang('Agendamento.holidayNew'), site_url(self::LISTA . '/novo')]
                : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->novaTabela());
    }

    public function novo(): ResponseInterface|string
    {
        return $this->podeCadastrar() ? $this->formulario([]) : $this->negar();
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        if (! $this->podeCadastrar()) {
            return $this->negar();
        }

        [$dados, $erros] = $this->postados();

        if ($erros === []) {
            try {
                (new FeriadosServico(db_connect()))->criar(
                    $this->campusDe($dados),
                    $dados['data_feriado'],
                    $dados['descricao'],
                    $dados['abrangencia']
                );
            } catch (Recusa $recusa) {
                $erros['data_feriado'] = $recusa->texto();
            }
        }

        return $erros === []
            ? $this->salvo(site_url(self::LISTA), lang('Agendamento.holidaySaved'))
            : $this->formulario($dados, $erros, 422);
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$feriado, $recusa] = $this->carregar($id);

        return $recusa ?? $this->formulario([
            'id_feriado'   => $id,
            'campus_id'    => $feriado['campus_id'] === null ? '' : (string) $feriado['campus_id'],
            'data_feriado' => $feriado['data_feriado'],
            'descricao'    => $feriado['descricao'],
            'abrangencia'  => $feriado['abrangencia'],
        ]);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        [, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        [$dados, $erros] = $this->postados();
        $dados['id_feriado'] = $id;

        if ($erros === []) {
            try {
                (new FeriadosServico(db_connect()))->alterar(
                    $id,
                    $this->campusDe($dados),
                    $dados['data_feriado'],
                    $dados['descricao'],
                    $dados['abrangencia']
                );
            } catch (Recusa $recusa) {
                $erros['data_feriado'] = $recusa->texto();
            }
        }

        return $erros === []
            ? $this->salvo(site_url(self::LISTA), lang('Agendamento.holidaySaved'))
            : $this->formulario($dados, $erros, 422);
    }

    public function excluir(int $id): RedirectResponse|ResponseInterface
    {
        [, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $lista = redirect()->to(site_url(self::LISTA));

        if (! $this->exclusaoConfirmada()) {
            return $lista->with('erro', lang('Ui.confirmMismatch'));
        }

        db_connect()->query('UPDATE feriados SET deletado_em = NOW() WHERE id_feriado = ?', [$id]);

        return $lista->with('sucesso', lang('Agendamento.holidayDeleted'));
    }

    private function novaTabela(): FeriadosTabela
    {
        $escopo = $this->escopo();
        $campi  = $escopo['global'] ? null : array_values(array_unique([
            ...$escopo['campus'],
            ...array_map(fn (int $p): int => $this->campusDoPredio($p), $escopo['predio']),
        ]));

        return new FeriadosTabela($campi, fn (?int $campusId): bool => $this->podeGerir($campusId));
    }

    private function campusDoPredio(int $predioId): int
    {
        $predio = db_connect()->table('predios')->select('campus_id')->where('id_predio', $predioId)->get()->getRow();

        return (int) ($predio->campus_id ?? 0);
    }

    /** Pode cadastrar/alterar/excluir um feriado do campus (null = para todos os campi). */
    private function podeGerir(?int $campusId): bool
    {
        return $campusId === null
            ? $this->autorizacao->ehSuper()
            : $this->autorizacao->pode(self::GESTAO, Autorizacao::EDITOR, $campusId);
    }

    /** Campi onde o usuário cadastra feriado: a mesma fonte do select do formulário. */
    private function campiGeridos(): Fonte
    {
        return service('opcoes')->campi($this->escopo(Autorizacao::EDITOR, self::GESTAO));
    }

    /** Pode cadastrar em algum lugar: um campus que gere, ou todos os campi (superperfil). */
    private function podeCadastrar(): bool
    {
        return $this->autorizacao->ehSuper() || $this->campiGeridos()->existe();
    }

    /** @param array<string, mixed> $dados */
    private function campusDe(array $dados): ?int
    {
        return $dados['campus_id'] === '' ? null : (int) $dados['campus_id'];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function postados(): array
    {
        $dados = [
            'campus_id'    => $this->texto('campus_id'),
            'data_feriado' => $this->texto('data_feriado'),
            'descricao'    => $this->texto('descricao'),
            'abrangencia'  => $this->texto('abrangencia'),
        ];
        $erros = $this->validar($dados, [
            'data_feriado' => $this->regra('Agendamento.fieldDate', 'required|valid_date[Y-m-d]'),
            'descricao'    => $this->regra('Agendamento.fieldDescription', 'required|max_length[255]'),
            'abrangencia'  => $this->regra(
                'Agendamento.fieldScope',
                'required|in_list[' . implode(',', FeriadosServico::ABRANGENCIAS) . ']'
            ),
        ]);

        $campusValido = $dados['campus_id'] === ''
            ? $this->autorizacao->ehSuper()
            : $this->campiGeridos()->contem($dados['campus_id']);

        if (! $campusValido) {
            $erros['campus_id'] = lang('Agendamento.optionInvalid');
        }

        return [$dados, $erros];
    }

    /** @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null} */
    private function carregar(int $id): array
    {
        $feriado = db_connect()->table('feriados')
            ->where('id_feriado', $id)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        if ($feriado === null) {
            return [[], redirect()->to(site_url(self::LISTA))->with('erro', lang('Agendamento.holidayNotFound'))];
        }

        $campus = $feriado['campus_id'] === null ? null : (int) $feriado['campus_id'];

        return [$feriado, $this->podeGerir($campus) ? null : $this->negar()];
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(array $dados, array $erros = [], int $status = 200): ResponseInterface|string
    {
        return $this->telaFormulario('agendamento/feriados', [
            'dados' => $dados,
            'todos' => $this->autorizacao->ehSuper(),
        ], $erros, $status);
    }
}
