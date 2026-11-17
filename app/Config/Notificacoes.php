<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Notificações (sistema + e-mail) e log de auditoria.
 */
class Notificacoes extends BaseConfig
{
    /**
     * Versão do termo de ciência aceito ao desativar o e-mail. O texto fica nos arquivos de idioma
     * (Notificacoes.emailOptOutTerm); ao mudar o texto, mude a versão.
     */
    public string $versaoTermo = '1';

    /**
     * Janela de envio do e-mail (horas): notificação mais antiga que isso não sai mais por e-mail (evita
     * uma rajada de avisos velhos quando o cron fica parado ou quando alguém reativa o e-mail).
     */
    public int $janelaEmailHoras = 48;

    /** Máximo de e-mails por execução de notificacoes:enviar (limite da hospedagem compartilhada). */
    public int $loteEmail = 100;

    /** Retenção mínima do log de auditoria (anos): o expurgo só alcança registros mais antigos que isso. */
    public int $retencaoAuditoriaAnos = 5;
}
