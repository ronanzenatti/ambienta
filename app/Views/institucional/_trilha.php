<?php

/**
 * Trilha campus → prédio → ambiente. O último item é a página atual (sem link).
 *
 * @var list<array{0: string, 1: ?string}> $trilha [rótulo, URL ou null]
 */
?>
<nav aria-label="<?= esc(lang('Institucional.breadcrumb'), 'attr') ?>" class="mb-space-4">
    <ol class="flex flex-wrap items-center gap-x-space-2 text-data text-ink-muted">
        <?php foreach ($trilha as $i => [$rotulo, $url]) : ?>
            <li class="flex items-center gap-x-space-2">
                <?php if ($i > 0) : ?>
                    <?= ui_icon('seta-direita', 'size-4') ?>
                <?php endif ?>
                <?php if ($url !== null) : ?>
                    <a href="<?= esc($url, 'attr') ?>" class="inline-flex min-h-11 items-center text-ink-muted underline hover:text-ink"><?= esc($rotulo) ?></a>
                <?php else : ?>
                    <span aria-current="page" class="text-ink"><?= esc($rotulo) ?></span>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
</nav>
