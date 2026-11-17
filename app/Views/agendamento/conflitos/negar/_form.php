<?php

/**
 * Negar o conflito: a reserva é cancelada por falta de aprovação (a justificativa cita quem negou; a
 * observação, opcional, completa). Na página (form.php) ou em modal ($modal).
 *
 * @var array<string, mixed>  $conflito
 * @var array<string, mixed>  $dados
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$id     = (int) $conflito['id_conflito_agendamento'];
$titulo = lang('Agendamento.denyTitle');
?>
<form <?= ui_form_attrs(site_url("agendamento/conflitos/{$id}/negar"), $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="voltar" value="<?= esc((string) ($dados['voltar'] ?? ''), 'attr') ?>">
    <p class="mb-space-4 text-body"><?= esc(lang('Agendamento.denyText', [(string) $conflito['titulo']])) ?></p>
    <?= ui_textarea('observacao', lang('Agendamento.fieldNote'), (string) ($dados['observacao'] ?? ''), $erros['observacao'] ?? null, null, 3, ['maxlength' => '2000']) ?>
    <?php
    $cancelar = ui_button(lang('Ui.cancel'), 'secondary', $modal ? ['@click' => 'fechar()'] : ['href' => site_url(ltrim((string) ($dados['voltar'] ?? '/agendamento/conflitos'), '/'))]);
    $negar    = ui_button(lang('Agendamento.deny'), 'primary', ['type' => 'submit', ':disabled' => $modal ? 'enviando' : null]);
    ?>
    <div class="flex flex-wrap justify-end gap-space-2"><?= $cancelar . $negar ?></div>
</form>
