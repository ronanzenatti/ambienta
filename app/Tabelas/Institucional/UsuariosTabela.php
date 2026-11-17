<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use App\Models\Institucional\UsuarioModel;
use App\Models\Institucional\UsuarioPerfilModel;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Listagem de usuários, limitada ao escopo de quem consulta. Alterar/excluir aparecem só nas linhas que
 * quem consulta administra (a mesma regra do controller, recebida em $administra).
 */
final class UsuariosTabela extends Tabela
{
    /** Perfis e escopos de cada usuário, para a busca achar "Coordenação" ou o nome do campus. */
    private const VINCULOS = "(SELECT GROUP_CONCAT(CONCAT_WS(' ', p.nome, c.nome, pr.nome) SEPARATOR ' ')
        FROM usuarios_perfis up
        JOIN perfis p ON p.id_perfil = up.perfil_id AND p.deletado_em IS NULL
        LEFT JOIN campus c ON c.id_campus = up.campus_id
        LEFT JOIN predios pr ON pr.id_predio = up.predio_id
        WHERE up.usuario_id = usuarios.id_usuario AND up.deletado_em IS NULL)";

    /**
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     * @param Closure(list<array<string, mixed>>): bool                  $administra recebe os vínculos do usuário;
     *                                                                              true se quem consulta o administra
     * @param Closure(list<array<string, mixed>>): bool|null             $trocaSenha mesma ideia, para a troca rápida
     *                                                                              de senha; null sem a permissão
     */
    public function __construct(
        private readonly array $escopo,
        private readonly Closure $administra,
        private readonly ?int $idLogado,
        private readonly bool $podeEditar,
        private readonly ?Closure $trocaSenha = null,
    ) {
        helper('usuarios');
    }

    public function id(): string
    {
        return 'usuarios';
    }

    public function titulo(): string
    {
        return lang('Usuarios.title');
    }

    public function urlLista(): string
    {
        return site_url('usuarios');
    }

    public function urlExportar(): string
    {
        return site_url('usuarios/exportar');
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('usuarios')
            ->select('usuarios.id_usuario, usuarios.nome, usuarios.email, usuarios.ativo, usuarios.ultimo_acesso_em')
            ->where('usuarios.deletado_em', null);

        if (! $this->escopo['global']) {
            $builder->where((new UsuarioModel())->condicaoEscopo($this->escopo), null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'usuarios.id_usuario';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Usuarios.colName'), 'usuarios.nome')->principal(),
            Coluna::nova('email', lang('Usuarios.colEmail'), 'usuarios.email'),
            Coluna::nova('perfis', lang('Usuarios.colProfiles'))
                ->buscaEm(self::VINCULOS)
                ->texto(static fn (array $linha): string => $linha['vinculos'] === []
                    ? lang('Usuarios.noProfile')
                    : implode('; ', array_map('usuarios_rotulo_vinculo', $linha['vinculos'])))
                ->html(static function (array $linha, string $texto): string {
                    if ($linha['vinculos'] === []) {
                        return '<span class="text-ink-muted">' . esc($texto) . '</span>';
                    }

                    $itens = array_map(
                        static fn (array $v): string => '<li>' . esc(usuarios_rotulo_vinculo($v)) . '</li>',
                        $linha['vinculos']
                    );

                    return '<ul>' . implode('', $itens) . '</ul>';
                }),
            Coluna::ativo('usuarios.ativo'),
            Coluna::nova('ultimo_acesso_em', lang('Usuarios.colAccess'), 'usuarios.ultimo_acesso_em')
                ->dataHora(lang('Ui.never')),
        ];
    }

    /** O CPF não aparece na lista, mas continua pesquisável só pelos dígitos (como no login). */
    protected function buscaExtra(string $palavra, BaseConnection $db): array
    {
        $digitos = preg_replace('/\D+/', '', $palavra) ?? '';

        if ($digitos === '') {
            return [];
        }

        return ['usuarios.cpf LIKE ' . $db->escape('%' . $digitos . '%')];
    }

    protected function enriquecer(array $linhas): array
    {
        $ids      = array_map(static fn (array $u): int => (int) $u['id_usuario'], $linhas);
        $vinculos = (new UsuarioPerfilModel())->resumoPorUsuarios($ids);

        foreach ($linhas as &$linha) {
            $linha['vinculos'] = $vinculos[(int) $linha['id_usuario']] ?? [];
        }
        unset($linha);

        return $linhas;
    }

    public function temExclusao(): bool
    {
        return $this->podeEditar;
    }

    public function acoes(array $linha): array
    {
        $id         = (int) $linha['id_usuario'];
        $administra = $this->podeEditar && ($this->administra)($linha['vinculos']);

        $acoes = [
            'ver'     => site_url("usuarios/{$id}"),
            'editar'  => $administra ? site_url("usuarios/{$id}") . '#dados-titulo' : null,
            'excluir' => $administra && $id !== $this->idLogado ? site_url("usuarios/{$id}") : null,
        ];

        if ($this->trocaSenha !== null && $id !== $this->idLogado && ($this->trocaSenha)($linha['vinculos'])) {
            $rotulo = lang('Usuarios.passwordTitle');

            // Abre o formulário no modal da lista; sem JavaScript o link leva à página do formulário.
            $acoes['extra'] = ui_icon_button('chave', $rotulo, $rotulo . ': ' . $linha['nome'], 'secondary', [
                'href'   => site_url("usuarios/{$id}/senha"),
                '@click' => 'ambientaAbrirFormulario($event)',
            ]);
        }

        return $acoes;
    }

    /** Novo/Alterar são páginas; o modal é só da troca rápida de senha. */
    public function temModalDeFormulario(): bool
    {
        return $this->trocaSenha !== null;
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Usuarios.deleteText', [(string) $linha['nome']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Usuarios.deleteTitle');
    }

    public function vazio(): string
    {
        return lang('Usuarios.empty');
    }
}
