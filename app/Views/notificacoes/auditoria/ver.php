<?php

/**
 * Um registro do log de auditoria: quem, quando, de onde, e o registro inteiro antes e depois, campo a campo,
 * com os alterados marcados (ponto + rótulo, nunca só a cor).
 *
 * @var array<string, mixed> $log linha de logs_auditoria + usuario (nome)
 */
$antes    = json_decode((string) ($log['dados_anteriores'] ?? ''), true);
$depois   = json_decode((string) ($log['dados_novos'] ?? ''), true);
$alterados = json_decode((string) ($log['campos_alterados'] ?? ''), true);
$antes     = is_array($antes) ? $antes : [];
$depois    = is_array($depois) ? $depois : [];
$alterados = is_array($alterados) ? $alterados : [];
$campos    = array_keys($depois + $antes);
$titulo    = lang('Auditoria.detailTitle', [(string) $log['id_log_auditoria']]);
$formato   = lang('Institucional.dateFormat') . ' H:i:s';

$valor = static function (array $linha, string $campo): string {
    if (! array_key_exists($campo, $linha) || $linha[$campo] === null) {
        return lang('Auditoria.nullValue');
    }

    return is_scalar($linha[$campo]) ? (string) $linha[$campo] : (string) json_encode($linha[$campo], JSON_UNESCAPED_UNICODE);
};
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Auditoria.title'), site_url('auditoria')], [$titulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <section aria-labelledby="log-dados" class="mb-space-8 max-w-3xl rounded-md border border-border bg-surface p-space-6 shadow-xs">
        <h2 id="log-dados" class="sr-only"><?= esc(lang('Ui.recordDetails')) ?></h2>
        <dl class="grid gap-x-space-6 gap-y-space-4 sm:grid-cols-2">
            <div>
                <dt class="text-caption text-ink-muted"><?= esc(lang('Auditoria.fieldTable')) ?></dt>
                <dd class="text-body-strong"><?= esc(lang('Auditoria.table_' . $log['tabela_afetada'])) ?> · <?= esc((string) $log['registro_id']) ?></dd>
            </div>
            <div>
                <dt class="text-caption text-ink-muted"><?= esc(lang('Auditoria.fieldAction')) ?></dt>
                <dd class="text-body"><?= esc(lang('Auditoria.action_' . $log['acao'])) ?></dd>
            </div>
            <div>
                <dt class="text-caption text-ink-muted"><?= esc(lang('Auditoria.fieldWhen')) ?></dt>
                <dd class="text-body tabular-nums"><?= esc(date($formato, (int) strtotime((string) $log['criado_em']))) ?></dd>
            </div>
            <div>
                <dt class="text-caption text-ink-muted"><?= esc(lang('Auditoria.fieldUser')) ?></dt>
                <dd class="text-body"><?= esc((string) ($log['usuario'] ?? lang('Auditoria.system'))) ?></dd>
            </div>
            <div>
                <dt class="text-caption text-ink-muted"><?= esc(lang('Auditoria.fieldIp')) ?></dt>
                <dd class="text-body"><?= esc((string) ($log['ip_origem'] ?? '—')) ?></dd>
            </div>
            <?php if ($alterados !== []) : ?>
                <div>
                    <dt class="text-caption text-ink-muted"><?= esc(lang('Auditoria.changedFields')) ?></dt>
                    <dd class="text-body"><?= esc(implode(', ', $alterados)) ?></dd>
                </div>
            <?php endif ?>
        </dl>
    </section>

    <div class="overflow-x-auto rounded-md border border-border bg-surface shadow-xs">
        <table class="w-full text-left text-body">
            <caption class="sr-only"><?= esc($titulo) ?></caption>
            <thead class="border-b border-border bg-paper text-caption uppercase tracking-wide text-ink-muted">
                <tr>
                    <th scope="col" class="px-space-4 py-space-3"><?= esc(lang('Auditoria.fieldField')) ?></th>
                    <th scope="col" class="px-space-4 py-space-3"><?= esc(lang('Auditoria.fieldBefore')) ?></th>
                    <th scope="col" class="px-space-4 py-space-3"><?= esc(lang('Auditoria.fieldAfter')) ?></th>
                    <th scope="col" class="px-space-4 py-space-3"><?= esc(lang('Ui.status')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($campos as $campo) : ?>
                    <?php $mudou = in_array($campo, $alterados, true); ?>
                    <tr class="border-b border-border last:border-b-0<?= $mudou ? ' bg-signal-pending/5' : '' ?>">
                        <th scope="row" class="px-space-4 py-space-3 text-data font-normal"><?= esc((string) $campo) ?></th>
                        <td class="break-all px-space-4 py-space-3"><?= $antes === [] ? '—' : esc($valor($antes, (string) $campo)) ?></td>
                        <td class="break-all px-space-4 py-space-3"><?= esc($valor($depois, (string) $campo)) ?></td>
                        <td class="whitespace-nowrap px-space-4 py-space-3">
                            <?= $mudou ? ui_status_dot('pending', lang('Auditoria.changed')) : ui_status_dot('neutral', lang('Auditoria.unchanged')) ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <p class="mt-space-4 text-caption text-ink-muted"><?= esc(lang('Auditoria.hiddenValue')) ?></p>
<?= $this->endSection() ?>
