<?php

/**
 * Nova alocação semestral (página: 6 campos). Se a disciplina já está em outro ambiente no mesmo horário,
 * a gravação só passa com a caixa de ciência marcada. Pendências de requisito do professor não bloqueiam:
 * podem virar um chamado de adequação na hora.
 *
 * As listas vêm do banco sob demanda (ui_select_fonte); "disciplina e professor" lista os vínculos
 * professor-disciplina do semestre escolhido (a alocação exige o vínculo).
 *
 * @var array<string, mixed>       $dados
 * @var list<array<string, mixed>> $outros      a disciplina em outro ambiente no mesmo horário
 * @var array<string, string>      $erros
 */

use App\Libraries\Agendamento\Alocacoes;

$titulo  = lang('Agendamento.allocationNew');
$vazio   = ['' => lang('Ui.select')];
$escolha = lang('Ui.select');
$exigido = ['required' => true];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Agendamento.allocationsTitle'), site_url('agendamento/alocacoes')], [$titulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?php if (isset($erros['geral'])) : ?>
        <?= ui_alert('critical', $erros['geral']) ?>
    <?php endif ?>

    <form method="post" action="<?= site_url('agendamento/alocacoes') ?>" class="max-w-2xl rounded-md border border-border bg-surface p-space-6 shadow-xs">
        <?= csrf_field() ?>
        <?= ui_select_fonte('semestre_id', lang('Agendamento.fieldSemester'), 'semestres-abertos', [], $dados['semestre_id'] ?? null, $escolha, $erros['semestre_id'] ?? null, null, null, $exigido) ?>
        <?= ui_select_fonte('vinculo_id', lang('Agendamento.fieldLink'), 'vinculos', [], $dados['vinculo_id'] ?? null, $escolha, $erros['vinculo_id'] ?? null, lang('Agendamento.fieldLinkHelp'), ['semestre_id', 'semestre'], $exigido) ?>
        <?= ui_select_fonte('ambiente_id', lang('Agendamento.fieldEnvironment'), 'ambientes', ['chave' => 'agendamento.alocar', 'nivel' => 'editor'], $dados['ambiente_id'] ?? null, $escolha, $erros['ambiente_id'] ?? null, null, null, $exigido) ?>
        <?= ui_select('dia_semana', lang('Agendamento.fieldWeekday'), $vazio + opcoes_traduzidas('Agendamento.weekday_', Alocacoes::DIAS), (string) ($dados['dia_semana'] ?? ''), $erros['dia_semana'] ?? null, null, ['required' => true]) ?>
        <div class="grid gap-x-space-4 sm:grid-cols-2">
            <?= ui_field('hora_inicio', lang('Agendamento.fieldStartTime'), 'time', (string) ($dados['hora_inicio'] ?? ''), $erros['hora_inicio'] ?? null, null, ['required' => true]) ?>
            <?= ui_field('hora_fim', lang('Agendamento.fieldEndTime'), 'time', (string) ($dados['hora_fim'] ?? ''), $erros['hora_fim'] ?? null, lang('Agendamento.longDurationHelp'), ['required' => true]) ?>
        </div>

        <?php if ($outros !== []) : ?>
            <div class="mb-space-4 rounded-sm border border-signal-pending/40 bg-signal-pending/5 p-space-4">
                <p class="mb-space-2 text-body"><?= ui_status_dot('pending', lang('Agendamento.sameDisciplineElsewhere')) ?></p>
                <ul class="mb-space-2 list-disc pl-space-6 text-body">
                    <?php foreach ($outros as $outro) : ?>
                        <li><?= esc($outro['numero'] . ' ' . $outro['nome']) ?> · <span class="tabular-nums"><?= esc(substr((string) $outro['hora_inicio'], 0, 5) . '–' . substr((string) $outro['hora_fim'], 0, 5)) ?></span></li>
                    <?php endforeach ?>
                </ul>
                <?= ui_checkbox('ciencia', lang('Agendamento.awarenessLabel')) ?>
            </div>
        <?php endif ?>

        <div class="flex flex-wrap gap-space-3">
            <?= ui_button(lang('Ui.save'), 'primary', ['type' => 'submit']) ?>
            <?= ui_button(lang('Ui.cancel'), 'secondary', ['href' => site_url('agendamento/alocacoes')]) ?>
        </div>
    </form>
<?= $this->endSection() ?>
