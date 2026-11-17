<?php

/**
 * Corpo do modal "Ver agenda do ambiente" (visão linha do tempo): a semana do ambiente em colunas, de segunda a
 * sábado (domingo só se houver). `dados.ambienteId` vem do evento open-modal; ao abrir um agendamento daqui, este
 * modal fecha para dar lugar ao de detalhes.
 */

?>
<div x-data="ambientaSala(() => dados.ambienteId)">
    <?= view('consulta/_sala_semana', [], ['saveData' => false]) ?>
</div>
