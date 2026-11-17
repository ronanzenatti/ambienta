<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Parâmetros do módulo de patrimônio que não são por campus.
 */
class Patrimonio extends BaseConfig
{
    /**
     * Prazo de resposta de uma movimentação (horas) quando o campus de destino ainda não tem
     * configuracoes_campus.prazo_resposta_movimentacao_horas preenchido (a tela de configuração é da Fase 6).
     */
    public int $prazoPadraoMovimentacaoHoras = 48;

    /** Máximo de unidades geradas numa replicação de modelo (um POST, uma transação). */
    public int $limiteReplicacao = 500;

    /** Máximo de linhas lidas de uma planilha de números de patrimônio. */
    public int $limiteImportacao = 5000;
}
