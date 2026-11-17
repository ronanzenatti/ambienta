<?php

/**
 * Formulário de curso: na página (form.php) ou no modal da listagem ($modal).
 *
 * @var array<string, mixed>  $curso
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$novo   = ! isset($curso['id_curso']);
$titulo = lang($novo ? 'Cursos.new' : 'Cursos.edit');
$acao   = $novo ? site_url('cursos') : site_url('cursos/' . $curso['id_curso']);
$tipos  = ['' => lang('Ui.select')] + opcoes_traduzidas('Cursos.type_', \App\Models\Institucional\CursoModel::TIPOS);
?>
<form <?= ui_form_attrs($acao, $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?= ui_field('nome', lang('Institucional.fieldName'), 'text', (string) ($curso['nome'] ?? ''), $erros['nome'] ?? null, null, ['required' => true, 'maxlength' => '255']) ?>
    <div class="grid gap-x-space-4 sm:grid-cols-2">
        <?= ui_field('abreviacao', lang('Institucional.fieldAbbreviation'), 'text', (string) ($curso['abreviacao'] ?? ''), $erros['abreviacao'] ?? null, null, ['required' => true, 'maxlength' => '20']) ?>
        <?= ui_select('tipo', lang('Cursos.fieldType'), $tipos, (string) ($curso['tipo'] ?? ''), $erros['tipo'] ?? null, null, ['required' => true]) ?>
    </div>
    <?= ui_select_fonte('responsavel_id', lang('Cursos.fieldManager'), 'pessoas', [], $curso['responsavel_id'] ?? null, lang('Ui.select'), $erros['responsavel_id'] ?? null, lang('Cursos.managerHelp'), null, ['required' => true]) ?>
    <div class="mb-space-4">
        <label for="campo-descricao" class="mb-space-1 block text-body-strong"><?= esc(lang('Institucional.fieldDescription')) ?></label>
        <textarea id="campo-descricao" name="descricao" rows="3"
                  <?= isset($erros['descricao']) ? 'aria-invalid="true" aria-describedby="campo-descricao-erro"' : '' ?>
                  class="w-full rounded-sm border border-border-strong bg-surface px-space-3 py-space-2 text-body"><?= esc((string) ($curso['descricao'] ?? '')) ?></textarea>
        <?php if (isset($erros['descricao'])) : ?>
            <p id="campo-descricao-erro" class="mt-space-1 text-data text-signal-critical"><?= esc($erros['descricao']) ?></p>
        <?php endif ?>
    </div>
    <?= ui_form_buttons($novo, site_url('cursos'), $modal) ?>
</form>
