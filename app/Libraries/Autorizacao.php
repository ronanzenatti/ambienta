<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;

/**
 * Permissões e escopos de um usuário, a partir de usuarios_perfis (global / campus / prédio).
 *
 * Cada vínculo (perfil + escopo) é uma "concessão": o perfil dá permissões (leitor < editor)
 * e o escopo delimita onde valem. O superperfil (perfis.super_perfil) tem todas as permissões
 * dentro do escopo do seu vínculo (na prática, global).
 *
 * Um vínculo de campus cobre o campus e todos os seus prédios; um de prédio cobre só o prédio.
 */
class Autorizacao
{
    public const LEITOR = 'leitor';
    public const EDITOR = 'editor';

    private const NIVEIS = [self::LEITOR => 1, self::EDITOR => 2];

    /** @var list<array{escopo: string, campus: ?int, predio: ?int, super: bool, permissoes: array<string, int>}>|null */
    private ?array $concessoes = null;

    /** @var array<int, ?int> */
    private array $campusDoPredio = [];

    public function __construct(private readonly ?int $usuarioId, private readonly BaseConnection $db)
    {
    }

    /** Acesso irrestrito: superperfil em escopo global. */
    public function ehSuper(): bool
    {
        foreach ($this->concessoes() as $concessao) {
            if ($concessao['super'] && $concessao['escopo'] === 'global') {
                return true;
            }
        }

        return false;
    }

    /**
     * Tem a permissão em pelo menos o nível pedido?
     *
     * Sem alvo (campus e prédio nulos) pergunta "em algum escopo?", útil para filtros de rota
     * e menus. Com alvo, o escopo de alguma concessão precisa cobri-lo. Só o prédio informado:
     * o campus dele é resolvido no banco.
     */
    public function pode(
        string $chave,
        string $nivel = self::LEITOR,
        ?int $campusId = null,
        ?int $predioId = null
    ): bool {
        $exigido = $this->exigido($nivel);

        if ($predioId !== null && $campusId === null) {
            $campusId = $this->campusDoPredio($predioId);
        }

        foreach ($this->concessoes() as $concessao) {
            if (! $this->concede($concessao, $chave, $exigido)) {
                continue;
            }

            if ($this->cobre($concessao, $campusId, $predioId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Onde a permissão vale, para filtrar consultas: global, ou listas de ids de campus e de prédios.
     * Um vínculo de prédio entra em 'predio'; o de campus, em 'campus' (que já cobre os prédios dele).
     *
     * @return array{global: bool, campus: list<int>, predio: list<int>}
     */
    public function escopoPara(string $chave, string $nivel = self::LEITOR): array
    {
        $exigido = $this->exigido($nivel);
        $escopo  = ['global' => false, 'campus' => [], 'predio' => []];

        foreach ($this->concessoes() as $concessao) {
            if (! $this->concede($concessao, $chave, $exigido)) {
                continue;
            }

            if ($concessao['escopo'] === 'global') {
                $escopo['global'] = true;
            } elseif ($concessao['escopo'] === 'campus' && $concessao['campus'] !== null) {
                $escopo['campus'][] = $concessao['campus'];
            } elseif ($concessao['predio'] !== null) {
                $escopo['predio'][] = $concessao['predio'];
            }
        }

        $escopo['campus'] = array_values(array_unique($escopo['campus']));
        $escopo['predio'] = array_values(array_unique($escopo['predio']));

        return $escopo;
    }

    /**
     * A permissão cobre TODOS os vínculos informados? Vale para decidir se alguém pode gerir outro
     * usuário: sem nenhum vínculo, só quem tem a permissão em escopo global.
     *
     * @param list<array<string, mixed>> $vinculos linhas com escopo_tipo, campus_id, predio_id
     */
    public function cobreVinculos(string $chave, string $nivel, array $vinculos): bool
    {
        if ($this->escopoPara($chave, $nivel)['global']) {
            return true;
        }

        if ($vinculos === []) {
            return false;
        }

        foreach ($vinculos as $vinculo) {
            $escopo = (string) $vinculo['escopo_tipo'];

            if ($escopo === 'global') {
                return false;
            }

            $campusId = $vinculo['campus_id'] === null ? null : (int) $vinculo['campus_id'];
            $predioId = $vinculo['predio_id'] === null ? null : (int) $vinculo['predio_id'];

            if ($escopo === 'campus' && ! $this->pode($chave, $nivel, $campusId)) {
                return false;
            }

            if ($escopo === 'predio' && ! $this->pode($chave, $nivel, null, $predioId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Quem concede um perfil não pode entregar mais do que tem: o perfil só cabe se cada permissão
     * dele, no nível dele, for exercida pelo concedente no escopo do vínculo. Superperfil só é
     * concedido por superperfil.
     *
     * @param array<string, string> $permissoesDoPerfil chave => nível
     */
    public function podeConceder(
        array $permissoesDoPerfil,
        bool $superPerfil,
        string $escopo,
        ?int $campusId,
        ?int $predioId
    ): bool {
        if ($superPerfil) {
            return $this->ehSuper();
        }

        if ($escopo === 'global' && ! $this->escopoPara('usuarios.acesso', self::EDITOR)['global']) {
            return false;
        }

        foreach ($permissoesDoPerfil as $chave => $nivel) {
            $cabe = $escopo === 'global'
                ? $this->escopoPara($chave, $nivel)['global']
                : $this->pode($chave, $nivel, $campusId, $predioId);

            if (! $cabe) {
                return false;
            }
        }

        return true;
    }

    private function exigido(string $nivel): int
    {
        return self::NIVEIS[$nivel] ?? throw new \InvalidArgumentException("Nível de acesso desconhecido: {$nivel}");
    }

    /**
     * @param array{escopo: string, campus: ?int, predio: ?int, super: bool, permissoes: array<string, int>} $concessao
     */
    private function concede(array $concessao, string $chave, int $exigido): bool
    {
        return $concessao['super'] || ($concessao['permissoes'][$chave] ?? 0) >= $exigido;
    }

    /**
     * @param array{escopo: string, campus: ?int, predio: ?int, super: bool, permissoes: array<string, int>} $concessao
     */
    private function cobre(array $concessao, ?int $campusId, ?int $predioId): bool
    {
        if ($campusId === null && $predioId === null) {
            return true;
        }

        return match ($concessao['escopo']) {
            'global' => true,
            'campus' => $campusId !== null && $concessao['campus'] === $campusId,
            default  => $predioId !== null && $concessao['predio'] === $predioId,
        };
    }

    private function campusDoPredio(int $predioId): ?int
    {
        if (! array_key_exists($predioId, $this->campusDoPredio)) {
            $linha = $this->db->table('predios')->select('campus_id')->where('id_predio', $predioId)->get()->getRow();

            $this->campusDoPredio[$predioId] = $linha === null ? null : (int) $linha->campus_id;
        }

        return $this->campusDoPredio[$predioId];
    }

    /**
     * @return list<array{escopo: string, campus: ?int, predio: ?int, super: bool, permissoes: array<string, int>}>
     */
    private function concessoes(): array
    {
        if ($this->concessoes !== null) {
            return $this->concessoes;
        }

        if ($this->usuarioId === null) {
            return $this->concessoes = [];
        }

        $linhas = $this->db->query(
            'SELECT up.id_usuario_perfil, up.escopo_tipo, up.campus_id, up.predio_id,
                    pr.campus_id AS predio_campus_id, p.super_perfil, pe.chave, pp.tipo_acesso
             FROM usuarios_perfis up
             JOIN perfis p ON p.id_perfil = up.perfil_id AND p.deletado_em IS NULL
             LEFT JOIN predios pr ON pr.id_predio = up.predio_id
             LEFT JOIN perfis_permissoes pp ON pp.perfil_id = p.id_perfil AND pp.deletado_em IS NULL
             LEFT JOIN permissoes pe ON pe.id_permissao = pp.permissao_id AND pe.deletado_em IS NULL
             WHERE up.usuario_id = ? AND up.deletado_em IS NULL',
            [$this->usuarioId]
        )->getResultArray();

        $porVinculo = [];

        foreach ($linhas as $linha) {
            $id = (int) $linha['id_usuario_perfil'];

            $porVinculo[$id] ??= [
                'escopo'     => (string) $linha['escopo_tipo'],
                'campus'     => $linha['campus_id'] === null ? null : (int) $linha['campus_id'],
                'predio'     => $linha['predio_id'] === null ? null : (int) $linha['predio_id'],
                'super'      => (bool) $linha['super_perfil'],
                'permissoes' => [],
            ];

            if ($linha['chave'] !== null) {
                $porVinculo[$id]['permissoes'][(string) $linha['chave']] = self::NIVEIS[$linha['tipo_acesso']] ?? 0;
            }
        }

        return $this->concessoes = array_values($porVinculo);
    }
}
