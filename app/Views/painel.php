<?php

/**
 * Página inicial de quem está logado: pendências que dependem da pessoa, a agenda dela e o que ela pediu.
 * Os dados vêm de App\Libraries\Painel\Painel (já no escopo de permissão do usuário).
 *
 * @var string                                                                       $nome
 * @var DateTimeImmutable                                                            $hoje
 * @var array{nome: string, data_fim: string}|null                                   $semestre
 * @var list<array{rotulo: string, url: string}>                                     $atalhos
 * @var list<array{chave: string, total: int, url: string, atrasadas: int}>          $pendencias
 * @var list<array{data: DateTimeImmutable, feriado: ?string, eventos: list<array<string, ?string>>}> $agenda
 * @var list<array{chave: string, total: int, url: ?string, linhas: list<array<string, ?string>>}>    $solicitacoes
 * @var int                                                                          $dias
 */
$idioma    = service('request')->getLocale();
$gerador   = new IntlDatePatternGenerator($idioma);
$formatar  = static function (DateTimeImmutable $data, string $esqueleto) use ($idioma, $gerador): string {
    $formatador = new IntlDateFormatter(
        $idioma,
        IntlDateFormatter::NONE,
        IntlDateFormatter::NONE,
        $data->getTimezone(),
        null,
        (string) $gerador->getBestPattern($esqueleto)
    );
    $texto = (string) $formatador->format($data);

    return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
};
$primeiroNome = explode(' ', trim($nome))[0];
$amanha       = $hoje->modify('+1 day')->format('Y-m-d');
$rotuloDoDia  = static function (DateTimeImmutable $data) use ($formatar, $hoje, $amanha): string {
    $rotulo = $formatar($data, 'EEEEdMMM');

    return match ($data->format('Y-m-d')) {
        $hoje->format('Y-m-d') => lang('Painel.today') . ' · ' . $rotulo,
        $amanha                => lang('Painel.tomorrow') . ' · ' . $rotulo,
        default                => $rotulo,
    };
};
$cartao       = 'rounded-md border border-border bg-surface shadow-xs';
$link         = 'inline-flex min-h-11 items-center underline';
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Ui.navHome')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <header class="mb-space-6 flex flex-wrap items-end justify-between gap-space-4">
        <div>
            <h1 class="text-display"><?= esc(lang('Painel.greeting', [$primeiroNome])) ?></h1>
            <p class="text-body text-ink-muted">
                <?= esc($formatar($hoje, 'EEEEdMMMMy')) ?> ·
                <?= esc($semestre === null
                    ? lang('Painel.noSemester')
                    : lang('Painel.semester', [
                        $semestre['nome'],
                        date(lang('Institucional.dateFormat'), (int) strtotime($semestre['data_fim'])),
                    ])) ?>
            </p>
        </div>

        <nav aria-label="<?= esc(lang('Painel.shortcuts'), 'attr') ?>">
            <ul class="flex flex-wrap gap-space-2">
                <?php foreach ($atalhos as $i => $atalho) : ?>
                    <li><?= ui_button($atalho['rotulo'], $i === 0 ? 'primary' : 'secondary', ['href' => $atalho['url']]) ?></li>
                <?php endforeach ?>
            </ul>
        </nav>
    </header>

    <section aria-labelledby="painel-pendencias" class="mb-space-8">
        <h2 id="painel-pendencias" class="text-title"><?= esc(lang('Painel.pendingTitle')) ?></h2>
        <p class="mb-space-4 text-body text-ink-muted"><?= esc(lang('Painel.pendingIntro')) ?></p>

        <?php if ($pendencias === []) : ?>
            <div class="<?= $cartao ?> p-space-4">
                <?= ui_status_dot('confirmed', lang('Painel.pendingEmpty')) ?>
            </div>
        <?php else : ?>
            <ul class="grid gap-space-4 sm:grid-cols-2 xl:grid-cols-4">
                <?php foreach ($pendencias as $pendencia) : ?>
                    <li>
                        <a href="<?= esc($pendencia['url'], 'attr') ?>"
                           class="<?= $cartao ?> flex h-full flex-col gap-space-1 p-space-4 text-ink no-underline hover:bg-paper">
                            <span class="text-display tabular-nums"><?= esc(ui_numero($pendencia['total'])) ?></span>
                            <span class="text-body-strong"><?= esc(lang('Painel.pending_' . $pendencia['chave'])) ?></span>
                            <?php if ($pendencia['atrasadas'] > 0) : ?>
                                <?= ui_status_dot('critical', lang('Painel.overdue', [$pendencia['atrasadas']])) ?>
                            <?php endif ?>
                            <span class="mt-auto inline-flex items-center gap-space-1 pt-space-2 text-body underline">
                                <?= esc(lang('Painel.pendingOpen')) ?><?= ui_icon('seta-direita', 'size-4') ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>

    <div class="grid items-start gap-space-6 xl:grid-cols-3">
        <section aria-labelledby="painel-agenda" class="<?= $cartao ?> xl:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-space-3 border-b border-border px-space-6 py-space-4">
                <div>
                    <h2 id="painel-agenda" class="text-title"><?= esc(lang('Painel.agendaTitle')) ?></h2>
                    <p class="text-body text-ink-muted"><?= esc(lang('Painel.agendaIntro', [$dias])) ?></p>
                </div>
                <?= ui_button(lang('Agendamento.icalMyNav'), 'secondary', ['href' => site_url('minha-agenda')]) ?>
            </div>

            <?php foreach ($agenda as $dia) : ?>
                <div class="border-b border-border px-space-6 py-space-4 last:border-b-0">
                    <h3 class="text-heading"><?= esc($rotuloDoDia($dia['data'])) ?></h3>
                    <?php if ($dia['feriado'] !== null) : ?>
                        <p class="mt-space-1"><?= ui_status_dot('neutral', lang('Painel.holiday', [$dia['feriado']])) ?></p>
                    <?php endif ?>

                    <?php if ($dia['eventos'] === []) : ?>
                        <?php if ($dia['feriado'] === null) : ?>
                            <p class="mt-space-1 text-body text-ink-muted"><?= esc(lang('Painel.agendaTodayEmpty')) ?></p>
                        <?php endif ?>
                    <?php else : ?>
                        <ul class="mt-space-2 divide-y divide-border">
                            <?php foreach ($dia['eventos'] as $evento) : ?>
                                <li class="flex flex-wrap items-baseline gap-x-space-4 py-space-2">
                                    <p class="w-32 shrink-0 text-data tabular-nums">
                                        <?= esc(lang('Painel.timeRange', [$evento['inicio'], $evento['fim']])) ?>
                                    </p>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-body-strong">
                                            <?php if ($evento['url'] !== null) : ?>
                                                <a href="<?= esc($evento['url'], 'attr') ?>" class="<?= $link ?>"><?= esc($evento['titulo']) ?></a>
                                            <?php else : ?>
                                                <?= esc($evento['titulo']) ?>
                                            <?php endif ?>
                                        </p>
                                        <p class="text-caption text-ink-muted">
                                            <?= esc(implode(' · ', array_filter([
                                                lang('Painel.type_' . $evento['tipo']),
                                                $evento['detalhe'],
                                                $evento['local'],
                                            ]))) ?>
                                        </p>
                                        <?php if ($evento['status'] !== null && $evento['tom'] !== null) : ?>
                                            <p><?= ui_status_dot($evento['tom'], $evento['status']) ?></p>
                                        <?php endif ?>
                                    </div>
                                </li>
                            <?php endforeach ?>
                        </ul>
                    <?php endif ?>
                </div>
            <?php endforeach ?>

            <?php if (count($agenda) === 1) : ?>
                <p class="px-space-6 py-space-4 text-body text-ink-muted"><?= esc(lang('Painel.agendaRestEmpty')) ?></p>
            <?php endif ?>
        </section>

        <section aria-labelledby="painel-solicitacoes" class="<?= $cartao ?>">
            <div class="border-b border-border px-space-6 py-space-4">
                <h2 id="painel-solicitacoes" class="text-title"><?= esc(lang('Painel.requestsTitle')) ?></h2>
                <p class="text-body text-ink-muted"><?= esc(lang('Painel.requestsIntro')) ?></p>
            </div>

            <?php if ($solicitacoes === []) : ?>
                <p class="px-space-6 py-space-4 text-body text-ink-muted"><?= esc(lang('Painel.requestsEmpty')) ?></p>
            <?php endif ?>

            <?php foreach ($solicitacoes as $grupo) : ?>
                <div class="border-b border-border px-space-6 py-space-4 last:border-b-0">
                    <h3 class="text-heading"><?= esc(lang('Painel.requests_' . $grupo['chave'])) ?></h3>
                    <ul class="mt-space-2 divide-y divide-border">
                        <?php foreach ($grupo['linhas'] as $linha) : ?>
                            <li class="py-space-2">
                                <p class="text-body-strong">
                                    <?php if ($linha['url'] !== null) : ?>
                                        <a href="<?= esc($linha['url'], 'attr') ?>" class="<?= $link ?>"><?= esc($linha['titulo']) ?></a>
                                    <?php else : ?>
                                        <?= esc($linha['titulo']) ?>
                                    <?php endif ?>
                                </p>
                                <p class="text-caption text-ink-muted"><?= esc($linha['detalhe']) ?></p>
                                <p><?= ui_status_dot((string) $linha['tom'], (string) $linha['status']) ?></p>
                            </li>
                        <?php endforeach ?>
                    </ul>
                    <?php if ($grupo['url'] !== null && $grupo['total'] > count($grupo['linhas'])) : ?>
                        <a href="<?= esc($grupo['url'], 'attr') ?>" class="<?= $link ?> text-body">
                            <?= esc(lang('Painel.seeAll', [$grupo['total']])) ?>
                        </a>
                    <?php endif ?>
                </div>
            <?php endforeach ?>
        </section>
    </div>
<?= $this->endSection() ?>
