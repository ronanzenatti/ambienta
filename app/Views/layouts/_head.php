<?php

/**
 * <head> comum aos layouts (main, publico e auth).
 *
 * @var string $titulo título da página (já renderizado pela view filha)
 */
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $titulo ?> · <?= esc(lang('App.name')) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= base_url('favicon.svg') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
<?php
// Ordem obrigatória: plugin do Alpine, Tom Select, JS próprio (registra alpine:init) e só então o Alpine.
// Textos do select com busca (diretiva x-busca em resources/js/app.js), no idioma da interface.
?>
<script type="application/json" id="ambienta-textos-select"><?= json_encode([
    'pesquisar'      => lang('Ui.selectSearch'),
    'semResultados'  => lang('Ui.selectNoResults'),
    'carregando'     => lang('Tabela.loading'),
    'carregandoMais' => lang('Ui.selectLoadingMore'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script defer src="<?= base_url('assets/js/vendor/alpine-focus.min.js') ?>"></script>
<script defer src="<?= base_url('assets/js/vendor/tom-select.min.js') ?>"></script>
<script defer src="<?= base_url('assets/js/app.js') ?>"></script>
<script defer src="<?= base_url('assets/js/vendor/alpine.min.js') ?>"></script>
