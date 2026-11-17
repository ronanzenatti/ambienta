<?php

/**
 * Faixa com a identidade do campus (fundo brand, texto brand-ink, logo). Deve ficar DENTRO do contêiner
 * com style="<?= campus_estilo($campus) ?>", que troca só brand/brand-ink; sem identidade própria,
 * vale a padrão. Dentro desse contêiner, brand nunca é usado como cor de texto sobre surface.
 *
 * As ações do registro (Alterar, Excluir) ocupam o canto direito da faixa, de ponta a ponta na altura,
 * em fundo claro (surface, texto ink) com o título "Ações": sem azul em volta e sem aumentar a faixa.
 * Os botões nunca ficam direto sobre a cor do campus, que muda por campus. No celular a região desce
 * para baixo da parte azul. Os modais de exclusão ficam fora da faixa (senão herdariam o texto brand-ink).
 *
 * @var array<string, mixed> $campus
 * @var string               $titulo  título da página (h1)
 * @var string|null          $sub     linha abaixo do título (ex.: o campus, na página de um prédio)
 * @var string|null          $acoes   HTML confiável dos botões de ação; null/vazio = sem região de ações
 */
$acoes = trim((string) ($acoes ?? ''));
?>
<div class="mb-space-6 flex flex-wrap overflow-hidden rounded-md border border-border shadow-xs">
    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-space-4 bg-brand px-space-6 py-space-4 text-brand-ink">
        <?php if (! empty($campus['logo'])) : ?>
            <img src="<?= esc(arquivo_url((string) $campus['logo']), 'attr') ?>"
                 alt="<?= esc(lang('Campus.logoAlt', [(string) $campus['nome']]), 'attr') ?>"
                 class="max-h-14 max-w-40 rounded-sm bg-surface object-contain p-space-1">
        <?php endif ?>
        <div class="min-w-0">
            <h1 class="text-display"><?= esc($titulo) ?></h1>
            <?php if (($sub ?? null) !== null) : ?>
                <p class="text-body"><?= esc($sub) ?></p>
            <?php endif ?>
        </div>
    </div>
    <?php if ($acoes !== '') : ?>
        <section aria-labelledby="faixa-acoes"
                 class="flex w-full flex-col justify-center gap-space-1 border-t border-border bg-surface px-space-4 py-space-2 text-ink sm:w-auto sm:border-l sm:border-t-0">
            <h2 id="faixa-acoes" class="text-caption text-ink-muted"><?= esc(lang('Ui.actions')) ?></h2>
            <div class="flex flex-wrap gap-space-2"><?= $acoes ?></div>
        </section>
    <?php endif ?>
</div>
