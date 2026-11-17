<?php

namespace App\Libraries\Auditoria;

/**
 * Auditoria automática de um Model (CodeIgniter\Model): insert(), update() e delete() por id gravam o log
 * de auditoria (ver Auditoria). A tabela do Model precisa estar em Auditoria::TABELAS.
 *
 * Alterações em massa sem id (where()->set()->update()) não passam por aqui: audite-as na operação.
 */
trait Auditavel
{
    /** @var array<int, array<string, mixed>|null> estado de cada registro antes da alteração em curso */
    private array $auditoriaAntes = [];

    protected function initialize(): void
    {
        parent::initialize();

        $this->afterInsert[]  = 'auditarInsercao';
        $this->beforeUpdate[] = 'auditarAntes';
        $this->afterUpdate[]  = 'auditarDepois';
        $this->beforeDelete[] = 'auditarAntes';
        $this->afterDelete[]  = 'auditarDepois';
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    protected function auditarInsercao(array $dados): array
    {
        if (($dados['result'] ?? false) !== false && is_numeric($dados['id'] ?? null)) {
            (new Auditoria($this->db))->registrarDepois($this->table, (int) $dados['id'], null);
        }

        return $dados;
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    protected function auditarAntes(array $dados): array
    {
        $auditoria = new Auditoria($this->db);

        foreach ($this->idsDoEvento($dados) as $id) {
            $this->auditoriaAntes[$id] = $auditoria->ler($this->table, $id);
        }

        return $dados;
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    protected function auditarDepois(array $dados): array
    {
        $auditoria = new Auditoria($this->db);

        foreach ($this->idsDoEvento($dados) as $id) {
            $antes = $this->auditoriaAntes[$id] ?? null;
            unset($this->auditoriaAntes[$id]);

            if ($antes !== null && ($dados['result'] ?? false) !== false) {
                $auditoria->registrarDepois($this->table, $id, $antes);
            }
        }

        return $dados;
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return list<int>
     */
    private function idsDoEvento(array $dados): array
    {
        $ids = $dados['id'] ?? [];

        return array_values(array_map('intval', array_filter((array) $ids, 'is_numeric')));
    }
}
