<?php

/**
 * Formulário do requisito de disciplina: na página (form.php) ou no modal da listagem ($modal). Itens e
 * softwares exigidos ficam na página do requisito.
 *
 * @var array<string, mixed>  $requisito
 * @var bool                  $gereTodos   escolhe o professor (senão o requisito é do próprio usuário)
 * @var string                $professorNome nome do professor (quem não gere todos só vê o próprio)
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$novo      = ! isset($requisito['id_requisito_disciplina']);
$titulo    = lang($novo ? 'Chamados.requirementNew' : 'Chamados.requirementEdit');
$acao      = $novo ? site_url('requisitos') : site_url('requisitos/' . $requisito['id_requisito_disciplina']);
$voltar    = $novo ? site_url('requisitos') : site_url('requisitos/' . $requisito['id_requisito_disciplina']);
$professor = (int) ($requisito['professor_id'] ?? 0);
?>
<form <?= ui_form_attrs($acao, $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?php if ($gereTodos) : ?>
        <?= ui_select_fonte('professor_id', lang('Chamados.fieldTeacher'), 'usuarios', ['chave' => 'chamados.resolver_cancelar', 'nivel' => 'editor'], $professor === 0 ? null : $professor, lang('Ui.select'), $erros['professor_id'] ?? null, null, null, ['required' => true]) ?>
    <?php else : ?>
        <p class="mb-space-4 text-body"><span class="text-body-strong"><?= esc(lang('Chamados.fieldTeacher')) ?>:</span> <?= esc($professorNome) ?></p>
        <?php if (isset($erros['professor_id'])) : ?>
            <?= ui_alert('critical', $erros['professor_id']) ?>
        <?php endif ?>
    <?php endif ?>
    <?= ui_select_fonte('disciplina_id', lang('Chamados.fieldDiscipline'), 'disciplinas-do-professor', ['chave' => 'chamados.resolver_cancelar', 'nivel' => 'editor', 'professor' => $professor], (int) ($requisito['disciplina_id'] ?? 0) ?: null, lang('Ui.select'), $erros['disciplina_id'] ?? null, null, $gereTodos ? ['professor_id', 'professor'] : null, ['required' => true]) ?>
    <?= ui_field('capacidade_minima', lang('Chamados.fieldMinCapacity'), 'number', (string) ($requisito['capacidade_minima'] ?? '0'), $erros['capacidade_minima'] ?? null, lang('Chamados.minCapacityHelp'), ['required' => true, 'min' => '0', 'max' => '65535', 'inputmode' => 'numeric']) ?>
    <?= ui_form_buttons($novo, $voltar, $modal) ?>
</form>
