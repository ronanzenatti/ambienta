<?php

/**
 * Layout mestre. Views filhas fazem $this->extend('layouts/main') e definem
 * as seções 'title' (texto simples) e 'content'.
 *
 * Logado: painel administrativo (menu lateral recolhível + barra superior com idioma e conta).
 * Sem login: layout público, sem menu lateral (consulta pública, página inicial).
 * Telas de login e senha usam layouts/auth.
 */

$usuario = service('autenticacao')->usuario();
$titulo  = $this->renderSection('title');
?>
<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>">
<head>
    <?= view('layouts/_head', ['titulo' => $titulo], ['saveData' => false]) ?>
</head>
<?php if ($usuario === null) : ?>
<body x-data class="flex min-h-screen flex-col bg-paper text-ink">
    <a href="#conteudo"
       class="sr-only focus:not-sr-only focus:absolute focus:left-space-4 focus:top-space-4 focus:z-50 focus:rounded-sm focus:bg-surface focus:px-space-4 focus:py-space-2 focus:text-body-strong">
        <?= esc(lang('Ui.skipToContent')) ?>
    </a>

    <header class="sticky top-0 z-30 border-b border-border bg-surface">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-space-6 gap-y-space-2 px-space-4 py-space-3">
            <a href="<?= site_url('/') ?>" class="flex min-h-11 items-center gap-space-3 no-underline">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-sm bg-brand text-heading text-brand-ink" aria-hidden="true">A</span>
                <span class="text-title text-ink"><?= esc(lang('App.name')) ?></span>
            </a>

            <nav aria-label="<?= esc(lang('Ui.mainNav'), 'attr') ?>">
                <ul class="flex flex-wrap gap-space-1">
                    <li>
                        <a href="<?= site_url('consulta') ?>"<?= url_is('consulta*') ? ' aria-current="page"' : '' ?>
                           class="inline-flex min-h-11 items-center gap-space-2 rounded-sm px-space-3 text-body no-underline <?= url_is('consulta*') ? 'bg-brand/10 font-semibold text-brand' : 'text-ink hover:bg-paper' ?>">
                            <?= ui_icon('busca', 'size-4') ?>
                            <?= esc(lang('Ui.navConsulta')) ?>
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="ml-auto flex items-center gap-space-3">
                <?= view('layouts/_idiomas', [], ['saveData' => false]) ?>
                <?php if (! url_is('entrar')) : ?>
                    <?= ui_button(lang('Ui.login'), 'primary', ['href' => site_url('entrar')]) ?>
                <?php endif ?>
            </div>
        </div>
    </header>

    <main id="conteudo" class="mx-auto w-full max-w-6xl flex-1 px-space-4 py-space-8">
        <?= view('layouts/_mensagens', [], ['saveData' => false]) ?>
        <?= $this->renderSection('content') ?>
    </main>

    <footer class="border-t border-border bg-surface">
        <div class="mx-auto max-w-6xl px-space-4 py-space-4 text-caption text-ink-muted">
            <?= esc(lang('App.institution')) ?>
        </div>
    </footer>
</body>
<?php else : ?>
    <?php $iniciais = mb_strtoupper(implode('', array_map(static fn (string $p): string => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim((string) $usuario['nome'])) ?: [], 0, 2)))); ?>
<body x-data="ambientaShell" @keydown.escape.window="menuAberto = false" class="bg-paper text-ink">
    <a href="#conteudo"
       class="sr-only focus:not-sr-only focus:absolute focus:left-space-4 focus:top-space-4 focus:z-[60] focus:rounded-sm focus:bg-surface focus:px-space-4 focus:py-space-2 focus:text-body-strong">
        <?= esc(lang('Ui.skipToContent')) ?>
    </a>

    <div class="flex h-screen overflow-hidden">
        <?= view('layouts/_menu_lateral', [], ['saveData' => false]) ?>

        <?php /* Fundo do menu no celular: é navegação, não modal; tocar fora fecha o menu. */ ?>
        <div x-show="menuAberto" x-cloak @click="menuAberto = false" class="fixed inset-0 z-40 bg-ink/50 xl:hidden" aria-hidden="true"></div>

        <div class="relative flex min-w-0 flex-1 flex-col overflow-y-auto overflow-x-hidden">
            <header class="sticky top-0 z-30 flex min-h-18 items-center gap-space-3 border-b border-brand bg-brand text-brand-ink px-space-4 md:px-space-6">
                <button type="button" @click="alternarMenu()"
                        :aria-expanded="(telaLarga() ? ! recolhido : menuAberto).toString()" aria-controls="menu-lateral"
                        aria-label="<?= esc(lang('Ui.toggleMenu'), 'attr') ?>" title="<?= esc(lang('Ui.toggleMenu'), 'attr') ?>"
                        class="inline-flex size-11 items-center justify-center rounded-sm border border-border bg-surface text-ink-muted shadow-xs hover:bg-paper hover:text-ink">
                    <span class="xl:hidden"><?= ui_icon('menu') ?></span>
                    <span class="hidden xl:inline"><?= ui_icon('painel') ?></span>
                </button>

                <a href="<?= site_url('/') ?>" class="flex min-h-11 items-center gap-space-2 no-underline xl:hidden">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-sm bg-brand-ink text-body-strong text-brand" aria-hidden="true">A</span>
                    <span class="text-heading text-brand-ink max-sm:sr-only"><?= esc(lang('App.name')) ?></span>
                </a>

                <div class="ml-auto flex items-center gap-space-3">
                    <?= view('layouts/_sino', ['usuarioId' => (int) $usuario['id_usuario']], ['saveData' => false]) ?>
                    <?= view('layouts/_idiomas', [], ['saveData' => false]) ?>

                    <div class="relative" x-data="{ aberto: false }"
                         @keydown.escape.window="aberto = false"
                         @click.window="if (! $el.contains($event.target)) aberto = false">
                        <button type="button" @click="aberto = ! aberto" :aria-expanded="aberto.toString()" aria-expanded="false"
                                aria-controls="menu-conta" aria-label="<?= esc(lang('Ui.accountMenu') . ': ' . $usuario['nome'], 'attr') ?>"
                                class="flex min-h-11 items-center gap-space-2 rounded-sm px-space-1 text-brand-ink hover:bg-brand-ink/10">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-ink text-data font-semibold text-brand" aria-hidden="true"><?= esc($iniciais) ?></span>
                            <span class="max-w-48 truncate text-body-strong max-md:sr-only"><?= esc($usuario['nome']) ?></span>
                            <?= ui_icon('seta-baixo', 'size-4') ?>
                        </button>
                        <div id="menu-conta" x-show="aberto" x-cloak
                             class="absolute right-0 z-50 mt-space-2 w-64 rounded-sm border border-border bg-surface p-space-2 shadow-md">
                            <div class="border-b border-border px-space-3 pb-space-3 pt-space-2">
                                <p class="truncate text-body-strong text-ink"><?= esc($usuario['nome']) ?></p>
                                <?php if (! empty($usuario['email'])) : ?>
                                    <p class="truncate text-caption text-ink-muted"><?= esc($usuario['email']) ?></p>
                                <?php endif ?>
                            </div>
                            <a href="<?= site_url('notificacoes/preferencias') ?>"
                               class="mt-space-2 flex min-h-11 w-full items-center gap-space-3 rounded-sm px-space-3 text-body text-ink no-underline hover:bg-paper">
                                <?= ui_icon('sino', 'size-5 text-ink-muted') ?>
                                <?= esc(lang('Notificacoes.navPreferences')) ?>
                            </a>
                            <a href="<?= site_url('minha-agenda') ?>"
                               class="flex min-h-11 w-full items-center gap-space-3 rounded-sm px-space-3 text-body text-ink no-underline hover:bg-paper">
                                <?= ui_icon('calendario', 'size-5 text-ink-muted') ?>
                                <?= esc(lang('Agendamento.icalMyNav')) ?>
                            </a>
                            <form method="post" action="<?= site_url('sair') ?>" class="pt-space-2">
                                <?= csrf_field() ?>
                                <button type="submit" class="flex min-h-11 w-full items-center gap-space-3 rounded-sm px-space-3 text-left text-body text-ink hover:bg-paper">
                                    <?= ui_icon('sair', 'size-5 text-ink-muted') ?>
                                    <?= esc(lang('Ui.logout')) ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main id="conteudo" class="mx-auto w-full max-w-screen-2xl flex-1 p-space-4 md:p-space-6">
                <?= view('layouts/_atrasos', ['usuarioId' => (int) $usuario['id_usuario']], ['saveData' => false]) ?>
                <?= view('layouts/_mensagens', [], ['saveData' => false]) ?>
                <?= $this->renderSection('content') ?>
            </main>
        </div>
    </div>
</body>
<?php endif ?>
</html>
