<?php

/**
 * Listagem padrão (Design System, "Tabela de listagem"). Funciona sem JavaScript (formulário GET e
 * links comuns); com Alpine, busca, ordenação, paginação e registros por página trocam só a região da
 * tabela via fetch (?parcial=1), sem recarregar a tela, e a URL acompanha (history.replaceState).
 *
 * A barra (registros por página e busca) fica fora da região trocada, para o foco não sair do campo
 * enquanto a pessoa digita.
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 * @var string                      $regiao HTML de components/tabela/regiao
 */

use App\Libraries\Tabela\Parametros;

$parametros = $tabela->parametros();
$id         = 'tabela-' . $tabela->id();
[$ordemPadrao, $direcaoPadrao] = $tabela->ordemPadrao();
$ordemAtual = $parametros->ordem === $ordemPadrao && $parametros->direcao === $direcaoPadrao;
$campoClasses = 'min-h-11 rounded-sm border border-border-strong bg-surface px-space-3 text-body shadow-xs focus:border-brand';
?>
<div id="<?= esc($id, 'attr') ?>" x-data="ambientaTabela(<?= Parametros::POR_PAGINA_PADRAO ?><?= $tabela->independente() ? ', ' . esc(json_encode($tabela->id()), 'attr') : '' ?>)" class="min-w-0"
     @tabela-recarregar.window="recarregar($event.detail)">
    <form x-ref="form" method="get" action="<?= esc($tabela->urlLista(), 'attr') ?>" role="search"
          aria-label="<?= esc(lang('Tabela.searchIn', [$tabela->titulo()]), 'attr') ?>"
          @submit.prevent="pesquisar()"
          class="mb-space-4 flex flex-wrap items-end justify-between gap-space-4">
        <div>
            <label for="<?= esc($id, 'attr') ?>-por" class="mb-space-1 block text-body"><?= esc(lang('Tabela.perPage')) ?></label>
            <select id="<?= esc($id, 'attr') ?>-por" name="por" @change="pesquisar()" x-busca data-largura="w-28" class="<?= $campoClasses ?> pr-space-8">
                <?php foreach (Parametros::POR_PAGINA as $opcao) : ?>
                    <option value="<?= $opcao ?>"<?= $opcao === $parametros->porPagina ? ' selected' : '' ?>><?= $opcao ?></option>
                <?php endforeach ?>
            </select>
        </div>

        <div class="flex min-w-0 flex-1 items-end justify-end gap-space-2">
            <div class="w-full max-w-sm">
                <label for="<?= esc($id, 'attr') ?>-q" class="mb-space-1 block text-body"><?= esc(lang('Tabela.search')) ?></label>
                <input type="search" id="<?= esc($id, 'attr') ?>-q" name="q" value="<?= esc($parametros->busca, 'attr') ?>"
                       maxlength="100" autocomplete="off" @input.debounce.400ms="pesquisar()"
                       class="<?= $campoClasses ?> w-full">
            </div>
            <input type="hidden" name="ordem" value="<?= $ordemAtual ? '' : esc($parametros->ordem, 'attr') ?>">
            <input type="hidden" name="dir" value="<?= $ordemAtual ? '' : esc($parametros->direcao, 'attr') ?>">
            <?php foreach ($tabela->filtros() as $filtro => $valorFiltro) : ?>
                <input type="hidden" name="<?= esc($filtro, 'attr') ?>" value="<?= esc($valorFiltro, 'attr') ?>" data-filtro>
            <?php endforeach ?>
            <?php /* Sem JavaScript a busca é por este botão; com Alpine ela acontece enquanto a pessoa digita. */ ?>
            <span x-show="false"><?= ui_button(lang('Ui.search'), 'secondary', ['type' => 'submit']) ?></span>
        </div>
    </form>

    <p class="sr-only" role="status" aria-live="polite" x-text="anuncio"></p>
    <div x-show="erro" x-cloak class="mb-space-4"><?= ui_alert('critical', lang('Tabela.loadError')) ?></div>
    <?php /* Retorno de um formulário salvo no modal (mesmo visual de ui_alert('confirmed'), texto vindo do servidor). */ ?>
    <div x-show="sucesso !== ''" x-cloak role="status" class="mb-space-4 rounded-sm border border-signal-confirmed/40 bg-signal-confirmed/5 p-space-4">
        <span class="inline-flex items-center gap-space-2 text-body">
            <span class="size-2 shrink-0 rounded-full bg-signal-confirmed" aria-hidden="true"></span>
            <span x-text="sucesso"></span>
        </span>
    </div>

    <div x-ref="regiao" tabindex="-1" :aria-busy="carregando ? 'true' : 'false'"
         :class="carregando && 'opacity-60'" class="transition-opacity focus:outline-none">
        <?= $regiao ?>
    </div>

    <?php if ($tabela->temModalDeFormulario()) : ?>
        <?= view('components/tabela/formulario_modal', [], ['saveData' => false]) ?>
    <?php endif ?>

    <?php if ($tabela->temExclusao()) : ?>
        <?= ui_confirm_delete_linhas($tabela->idModalExclusao(), $tabela->tituloExclusao()) ?>
    <?php endif ?>
</div>
