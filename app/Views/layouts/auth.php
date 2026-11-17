<?php

/**
 * Layout das telas de login e de senha: formulário à esquerda, painel da marca à direita (só em telas
 * largas; no celular fica só o formulário). Views filhas definem 'title' e 'content'.
 */

$titulo = $this->renderSection('title');
?>
<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>">
<head>
    <?= view('layouts/_head', ['titulo' => $titulo], ['saveData' => false]) ?>
</head>
<body x-data class="bg-surface text-ink">
    <a href="#conteudo"
       class="sr-only focus:not-sr-only focus:absolute focus:left-space-4 focus:top-space-4 focus:z-50 focus:rounded-sm focus:bg-surface focus:px-space-4 focus:py-space-2 focus:text-body-strong">
        <?= esc(lang('Ui.skipToContent')) ?>
    </a>

    <div class="flex min-h-screen">
        <div class="flex w-full flex-col lg:w-1/2">
            <header class="flex items-center justify-between gap-space-3 px-space-4 py-space-4 sm:px-space-8">
                <a href="<?= site_url('/') ?>" class="flex min-h-11 items-center gap-space-3 no-underline">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-sm bg-brand text-heading text-brand-ink" aria-hidden="true">A</span>
                    <span class="text-title text-ink"><?= esc(lang('App.name')) ?></span>
                </a>
                <?= view('layouts/_idiomas', [], ['saveData' => false]) ?>
            </header>

            <main id="conteudo" class="flex flex-1 items-center justify-center px-space-4 py-space-8 sm:px-space-8">
                <div class="w-full max-w-md">
                    <?= view('layouts/_mensagens', [], ['saveData' => false]) ?>
                    <?= $this->renderSection('content') ?>
                </div>
            </main>

            <footer class="px-space-4 py-space-4 text-caption text-ink-muted sm:px-space-8">
                <?= esc(lang('App.institution')) ?>
            </footer>
        </div>

        <aside class="relative hidden overflow-hidden bg-brand text-brand-ink lg:flex lg:w-1/2 lg:items-center lg:justify-center" aria-hidden="true">
            <?php /* Grade decorativa, como na tela de login do TailAdmin, na própria cor de texto da marca. */ ?>
            <svg class="absolute inset-0 size-full opacity-10" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <pattern id="grade-auth" width="48" height="48" patternUnits="userSpaceOnUse">
                        <path d="M48 0H0V48" fill="none" stroke="currentColor" stroke-width="1"/>
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#grade-auth)"/>
            </svg>
            <div class="relative max-w-sm px-space-8 text-center">
                <span class="mx-auto mb-space-6 flex size-16 items-center justify-center rounded-md border border-current text-display">A</span>
                <p class="mb-space-2 text-display"><?= esc(lang('App.name')) ?></p>
                <p class="text-body"><?= esc(lang('App.tagline')) ?></p>
            </div>
        </aside>
    </div>
</body>
</html>
