<?php

/**
 * Chamado de manutenção: dados, a mudança de status e as movimentações (modal com observação obrigatória;
 * sem JS, a página do formulário) e o histórico completo, do mais recente ao mais antigo.
 *
 * @var array<string, mixed>       $chamado
 * @var string                     $ambiente
 * @var list<array<string, mixed>> $historico
 * @var bool                       $podeMudar
 * @var bool                       $podeMovimentar
 */

use App\Tabelas\Chamados\ChamadosTabela;

$id      = (int) $chamado['id_chamado'];
$status  = (string) $chamado['status'];
$formato = lang('Institucional.dateFormat') . ' H:i';
$data    = static fn (mixed $valor): string => $valor === null ? '' : date($formato, (int) strtotime((string) $valor));
$titulo  = lang('Chamados.ticketTitle', [$id]);
$ponto   = static fn (string $s): string => ui_status_dot(ChamadosTabela::tom($s), lang('Chamados.status_' . $s));
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Chamados.ticketsTitle'), site_url('chamados')], ['#' . $id, null]]], ['saveData' => false]) ?>

    <div class="mb-space-6 flex max-w-3xl flex-wrap items-start justify-between gap-space-4">
        <h1 class="text-display"><?= esc($titulo) ?></h1>
        <div class="flex flex-wrap gap-space-2">
            <?php if ($podeMovimentar) : ?>
                <?= ui_button(lang('Chamados.addMovement'), 'edit', [
                    'href'   => site_url("chamados/{$id}/movimentacao"),
                    '@click' => 'ambientaAbrirFormulario($event)',
                ]) ?>
            <?php endif ?>
            <?php if ($podeMudar) : ?>
                <?= ui_button(lang('Chamados.changeStatus'), 'edit', [
                    'href'   => site_url("chamados/{$id}/situacao"),
                    '@click' => 'ambientaAbrirFormulario($event)',
                ]) ?>
            <?php endif ?>
        </div>
    </div>

    <section aria-labelledby="chamado-dados" class="mb-space-8 max-w-3xl rounded-md border border-border bg-surface p-space-6 shadow-xs">
        <div class="mb-space-4 flex flex-wrap items-start justify-between gap-space-4">
            <h2 id="chamado-dados" class="text-heading"><?= esc($ambiente) ?></h2>
            <?= $ponto($status) ?>
        </div>
        <dl class="mb-space-4 grid grid-cols-[auto_1fr] gap-x-space-4 gap-y-space-2 text-body">
            <dt class="text-ink-muted"><?= esc(lang('Chamados.fieldOpenedBy')) ?></dt>
            <dd><?= esc($chamado['aberto_por']) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Chamados.fieldOpenedAt')) ?></dt>
            <dd class="tabular-nums"><?= esc($data($chamado['criado_em'])) ?></dd>
            <?php if ($chamado['alocacao_semestral_id'] !== null) : ?>
                <dt class="text-ink-muted"><?= esc(lang('Chamados.fieldAllocation')) ?></dt>
                <dd class="tabular-nums">#<?= esc((string) $chamado['alocacao_semestral_id']) ?></dd>
            <?php endif ?>
        </dl>
        <h3 class="text-body-strong"><?= esc(lang('Chamados.fieldDescription')) ?></h3>
        <p class="whitespace-pre-line text-body"><?= esc($chamado['descricao']) ?></p>
    </section>

    <section aria-labelledby="chamado-historico" class="max-w-3xl">
        <h2 id="chamado-historico" class="mb-space-4 text-title"><?= esc(lang('Chamados.history')) ?></h2>
        <ol class="rounded-md border border-border bg-surface shadow-xs">
            <?php foreach ($historico as $registro) : ?>
                <li class="border-b border-border px-space-4 py-space-4 last:border-b-0">
                    <div class="mb-space-2 flex flex-wrap items-center gap-x-space-3 gap-y-space-1">
                        <?php if ($registro['status_anterior'] === null) : ?>
                            <span class="text-body-strong"><?= esc(lang('Chamados.historyOpened')) ?></span>
                            <?= $ponto((string) $registro['status_novo']) ?>
                        <?php elseif ($registro['status_anterior'] === $registro['status_novo']) : ?>
                            <span class="text-body-strong"><?= esc(lang('Chamados.historyMovement')) ?></span>
                            <?= $ponto((string) $registro['status_novo']) ?>
                        <?php else : ?>
                            <?= $ponto((string) $registro['status_anterior']) ?>
                            <span class="text-ink-muted" aria-hidden="true"><?= ui_icon('seta-direita', 'size-4') ?></span>
                            <span class="sr-only"><?= esc(lang('Chamados.historyTo')) ?></span>
                            <?= $ponto((string) $registro['status_novo']) ?>
                        <?php endif ?>
                    </div>
                    <p class="mb-space-2 text-data text-ink-muted">
                        <?= esc($registro['usuario']) ?> · <time class="tabular-nums" datetime="<?= esc(date('c', (int) strtotime((string) $registro['criado_em'])), 'attr') ?>"><?= esc($data($registro['criado_em'])) ?></time>
                    </p>
                    <p class="whitespace-pre-line text-body"><?= esc($registro['observacao']) ?></p>
                </li>
            <?php endforeach ?>
        </ol>
    </section>

    <?php if ($podeMudar || $podeMovimentar) : ?>
        <?php /* Salvou no modal: o histórico mudou, a página recarrega (a mensagem vem da sessão). */ ?>
        <div x-data @tabela-recarregar.window="window.location.reload()">
            <?= view('components/tabela/formulario_modal', [], ['saveData' => false]) ?>
        </div>
    <?php endif ?>
<?= $this->endSection() ?>
