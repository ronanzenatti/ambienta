<?php

namespace App\Models\Institucional;

use CodeIgniter\Model;

/**
 * Base dos cadastros institucionais: soft delete e timestamps no padrão do schema, e a contagem de
 * registros ativos que dependem de uma linha (a exclusão é bloqueada enquanto houver algum).
 */
abstract class ModeloInstitucional extends Model
{
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'criado_em';
    protected $updatedField   = 'alterado_em';
    protected $deletedField   = 'deletado_em';

    /**
     * Tabelas que referenciam esta: tabela => coluna da FK. Todas têm deletado_em (convenção do schema).
     *
     * @var array<string, string>
     */
    protected const DEPENDENTES = [];

    /**
     * Registros ativos que impedem a exclusão, por tabela (só as que têm algum).
     *
     * @return array<string, int>
     */
    public function dependentes(int $id): array
    {
        $contagem = [];

        foreach (static::DEPENDENTES as $tabela => $coluna) {
            $total = $this->db->table($tabela)->where($coluna, $id)->where('deletado_em', null)->countAllResults();

            if ($total > 0) {
                $contagem[$tabela] = $total;
            }
        }

        return $contagem;
    }

    /** Já existe outro registro ativo com este valor na coluna? (Mensagem amigável antes da UNIQUE do banco.) */
    public function valorEmUso(string $coluna, string $valor, ?int $ignorarId = null): bool
    {
        $consulta = $this->where($coluna, $valor);

        if ($ignorarId !== null) {
            $consulta->where($this->primaryKey . ' !=', $ignorarId);
        }

        return $consulta->countAllResults() > 0;
    }
}
