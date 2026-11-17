<?php

/**
 * Lista de usuários (limitada ao escopo de quem consulta).
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 * @var bool                        $podeNovo
 * @var bool                        $podeSenha
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Usuarios.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div class="mb-space-6 flex flex-wrap items-center justify-between gap-space-4">
        <h1 class="text-display"><?= esc(lang('Usuarios.title')) ?></h1>
        <div class="flex flex-wrap items-center gap-space-2">
            <?= $tabela->botaoExportar() ?>
            <?php if ($podeNovo) : ?>
                <?= ui_button(lang('Usuarios.new'), 'primary', ['href' => site_url('usuarios/novo')]) ?>
            <?php endif ?>
        </div>
    </div>

    <?= $tabela->renderizar() ?>

    <?php if ($podeSenha) : ?>
        <?php /* Resultado da troca rápida de senha: texto pronto para copiar (aberto pelo JS do formulário). */ ?>
        <?= ui_modal(
            'senha-resultado',
            lang('Usuarios.passwordResultTitle'),
            view('institucional/usuarios/_senha_resultado', [], ['saveData' => false]),
            ui_button(lang('Usuarios.copyText'), 'primary', [
                '@click' => 'copiar(dados.texto ?? \'\')',
            ])
        ) ?>
    <?php endif ?>
<?= $this->endSection() ?>
