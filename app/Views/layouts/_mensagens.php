<?php

/**
 * Mensagens de retorno guardadas na sessão (sucesso, aviso, erro).
 */

$autenticacao = service('autenticacao');

foreach (['sucesso' => 'confirmed', 'aviso' => 'pending', 'erro' => 'critical'] as $chave => $tom) {
    $mensagem = $autenticacao->mensagem($chave);
    if ($mensagem !== null) {
        echo ui_alert($tom, $mensagem);
    }
}
