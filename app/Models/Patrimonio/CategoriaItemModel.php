<?php

namespace App\Models\Patrimonio;

use App\Models\Institucional\ModeloInstitucional;

/** Categorias de item (catálogo global: informática, mobiliário...). Nome único entre as ativas. */
class CategoriaItemModel extends ModeloInstitucional
{
    protected $table         = 'categorias_itens';
    protected $primaryKey    = 'id_categoria_item';
    protected $allowedFields = ['nome', 'descricao'];

    protected const DEPENDENTES = [
        'modelos_itens'               => 'categoria_item_id',
        'itens'                       => 'categoria_item_id',
        'requisitos_disciplina_itens' => 'categoria_item_id',
    ];

    /** @return array<int, string> id => nome */
    public function opcoes(): array
    {
        $opcoes = [];

        foreach ($this->orderBy('nome')->findAll() as $categoria) {
            $opcoes[(int) $categoria['id_categoria_item']] = (string) $categoria['nome'];
        }

        return $opcoes;
    }
}
