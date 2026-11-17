<?php

namespace App\Models\Institucional;

use CodeIgniter\Model;

class DominioEmailPermitidoModel extends Model
{
    protected $table          = 'dominios_email_permitidos';
    protected $primaryKey     = 'id_dominio_email_permitido';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'criado_em';
    protected $updatedField   = 'alterado_em';
    protected $deletedField   = 'deletado_em';
    protected $allowedFields  = ['dominio', 'ativo'];

    /** O domínio do e-mail está na lista de permitidos e ativo. */
    public function permite(string $dominio): bool
    {
        return $this->where('dominio', strtolower(trim($dominio)))->where('ativo', 1)->countAllResults() > 0;
    }

    public function existe(string $dominio, ?int $ignorarId = null): bool
    {
        $consulta = $this->where('dominio', $dominio);

        if ($ignorarId !== null) {
            $consulta->where('id_dominio_email_permitido !=', $ignorarId);
        }

        return $consulta->countAllResults() > 0;
    }
}
