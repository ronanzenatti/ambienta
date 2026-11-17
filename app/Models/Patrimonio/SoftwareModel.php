<?php

namespace App\Models\Patrimonio;

use App\Models\Institucional\ModeloInstitucional;

/** Softwares (catálogo global), únicos por nome + versão entre os ativos. */
class SoftwareModel extends ModeloInstitucional
{
    protected $table         = 'softwares';
    protected $primaryKey    = 'id_software';
    protected $allowedFields = [
        'nome', 'versao', 'requisitos_tecnicos', 'status', 'solicitado_por_id', 'motivo_reprovacao',
    ];

    protected const DEPENDENTES = [
        'ambientes_softwares'             => 'software_id',
        'requisitos_disciplina_softwares' => 'software_id',
        'reservas_softwares_extras'       => 'software_id',
    ];

    public function nomeVersaoEmUso(string $nome, ?string $versao, ?int $ignorarId = null): bool
    {
        // Mesma regra da coluna nome_versao_unico: versão vazia conta como ''.
        $consulta = $this->where('nome', $nome)
            ->where("IFNULL(versao, '') = " . $this->db->escape($versao ?? ''), null, false);

        if ($ignorarId !== null) {
            $consulta->where('id_software !=', $ignorarId);
        }

        return $consulta->countAllResults() > 0;
    }

    /** @return array<int, string> id => "nome versão" */
    public function opcoes(): array
    {
        $opcoes = [];

        foreach ($this->where('status', 'aprovado')->orderBy('nome')->orderBy('versao')->findAll() as $software) {
            $opcoes[(int) $software['id_software']] = trim($software['nome'] . ' ' . ($software['versao'] ?? ''));
        }

        return $opcoes;
    }
}
