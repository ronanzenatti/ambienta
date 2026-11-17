<?php

/**
 * Região trocada a cada carregamento da listagem: tabela, resumo e paginação.
 * Links com data-tabela-link são interceptados pelo Alpine (ambientaTabela); sem JS, navegam normalmente.
 *
 * @var App\Libraries\Tabela\Tabela      $tabela
 * @var App\Libraries\Tabela\Resultado   $resultado
 * @var App\Libraries\Tabela\Parametros  $parametros
 */
$numero = ui_numero(...);
$url    = static fn (array $extra): string => $tabela->urlLista() . $parametros->query($tabela, $extra);
$colunas  = $tabela->colunas();
$comAcoes = $tabela->temAcoes();

if ($resultado->total === 0) {
    $resumo = lang('Tabela.summaryEmpty');
} else {
    $resumo = lang('Tabela.summary', [$numero($resultado->inicio()), $numero($resultado->fim()), $numero($resultado->total)]);
}
if ($parametros->busca !== '') {
    $resumo .= ' ' . lang('Tabela.summaryFiltered', [$numero($resultado->totalGeral)]);
}

$linkPagina = 'inline-flex min-h-11 min-w-11 items-center justify-center gap-space-1 rounded-sm border px-space-3 text-body no-underline shadow-xs';
?>
<div data-resumo="<?= esc($resumo, 'attr') ?>">
    <div class="overflow-x-auto rounded-md border border-border bg-surface shadow-xs">
        <table class="w-full text-left text-data">
            <caption class="sr-only"><?= esc($tabela->titulo()) ?>. <?= esc($resumo) ?></caption>
            <thead class="border-b border-border text-caption text-ink-muted">
                <tr>
                    <?php foreach ($colunas as $coluna) : ?>
                        <?php
                        $ativa   = $coluna->chave === $parametros->ordem;
                        $proxima = $ativa && $parametros->direcao === 'asc' ? 'desc' : 'asc';
                        $alinhar = $coluna->ehNumerica() ? ' text-right' : '';
                        ?>
                        <?php if ($coluna->ehOrdenavel()) : ?>
                            <th scope="col" class="px-space-4 py-space-1<?= $alinhar ?>"<?= $ativa ? ' aria-sort="' . ($parametros->direcao === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
                                <a href="<?= esc($url(['ordem' => $coluna->chave, 'dir' => $proxima, 'pagina' => '']), 'attr') ?>"
                                   data-tabela-link data-foco="ordem-<?= esc($coluna->chave, 'attr') ?>"
                                   class="inline-flex min-h-11 items-center gap-space-1 no-underline hover:text-ink<?= $ativa ? ' text-ink' : '' ?>">
                                    <?= esc($coluna->rotulo) ?>
                                    <?= ui_icon($ativa ? ($parametros->direcao === 'asc' ? 'ordem-cresc' : 'ordem-decr') : 'ordem', 'size-4') ?>
                                    <span class="sr-only">, <?= esc(lang($proxima === 'asc' ? 'Tabela.sortAsc' : 'Tabela.sortDesc')) ?></span>
                                </a>
                            </th>
                        <?php else : ?>
                            <th scope="col" class="px-space-4 py-space-3<?= $alinhar ?>"><?= esc($coluna->rotulo) ?></th>
                        <?php endif ?>
                    <?php endforeach ?>
                    <?php if ($comAcoes) : ?>
                        <th scope="col" class="px-space-4 py-space-3 text-right"><?= esc(lang('Ui.actions')) ?></th>
                    <?php endif ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($resultado->linhas === []) : ?>
                    <tr>
                        <td colspan="<?= count($colunas) + ($comAcoes ? 1 : 0) ?>" class="px-space-4 py-space-8 text-center text-body text-ink-muted">
                            <?= esc($parametros->busca === '' ? $tabela->vazio() : lang('Tabela.emptySearch', [$parametros->busca])) ?>
                        </td>
                    </tr>
                <?php endif ?>
                <?php foreach ($resultado->linhas as $linha) : ?>
                    <tr class="border-b border-border align-middle last:border-b-0 <?= esc($tabela->classeLinha($linha), 'attr') ?>">
                        <?php foreach ($colunas as $coluna) : ?>
                            <?php $classes = 'px-space-4 py-space-3' . ($coluna->ehNumerica() ? ' text-right tabular-nums' : ''); ?>
                            <?php if ($coluna->ehPrincipal()) : ?>
                                <th scope="row" class="<?= $classes ?> font-semibold"><?= $coluna->htmlDe($linha) ?></th>
                            <?php else : ?>
                                <td class="<?= $classes ?>"><?= $coluna->htmlDe($linha) ?></td>
                            <?php endif ?>
                        <?php endforeach ?>
                        <?php if ($comAcoes) : ?>
                            <?php
                            $acoes  = $tabela->acoes($linha);
                            $rotulo = $tabela->rotuloDaLinha($linha);
                            ?>
                            <td class="px-space-4 py-space-1">
                                <div class="flex justify-end gap-space-2">
                                    <?= $acoes['extra'] ?? '' ?>
                                    <?php if (($acoes['ver'] ?? null) !== null) : ?>
                                        <?= ui_icon_button('olho', lang('Ui.view'), lang('Ui.view') . ': ' . $rotulo, 'secondary', ['href' => $acoes['ver']]) ?>
                                    <?php endif ?>
                                    <?php if (($acoes['editar'] ?? null) !== null) : ?>
                                        <?= ui_icon_button('lapis', lang('Ui.edit'), lang('Ui.edit') . ': ' . $rotulo, 'edit', [
                                            'href'   => $acoes['editar'],
                                            '@click' => $tabela->formulariosEmModal() ? 'ambientaAbrirFormulario($event)' : null,
                                        ]) ?>
                                    <?php endif ?>
                                    <?php if (($acoes['excluir'] ?? null) !== null) : ?>
                                        <?= ui_icon_button('lixeira', lang('Ui.delete'), lang('Ui.delete') . ': ' . $rotulo, 'delete', [
                                            'data-acao'     => $acoes['excluir'],
                                            'data-mensagem' => $tabela->mensagemExclusao($linha),
                                            '@click'        => "\$dispatch('open-modal', { id: '" . $tabela->idModalExclusao() . "', acao: \$el.dataset.acao, mensagem: \$el.dataset.mensagem })",
                                        ]) ?>
                                    <?php endif ?>
                                </div>
                            </td>
                        <?php endif ?>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <div class="mt-space-4 flex flex-wrap items-center justify-between gap-space-4">
        <p class="text-caption text-ink-muted"><?= esc($resumo) ?></p>

        <?php if ($resultado->paginas > 1) : ?>
            <nav aria-label="<?= esc(lang('Ui.pagination'), 'attr') ?>">
                <ul class="flex flex-wrap items-center gap-space-1">
                    <li>
                        <?php if ($resultado->pagina > 1) : ?>
                            <a href="<?= esc($url(['pagina' => $resultado->pagina - 1 === 1 ? '' : $resultado->pagina - 1]), 'attr') ?>" data-tabela-link data-foco="anterior"
                               class="<?= $linkPagina ?> border-border bg-surface text-ink hover:bg-paper"><?= ui_icon('seta-esquerda', 'size-4') ?><?= esc(lang('Ui.previous')) ?></a>
                        <?php else : ?>
                            <span aria-disabled="true" class="<?= $linkPagina ?> border-border bg-surface text-ink-muted opacity-50 shadow-none"><?= ui_icon('seta-esquerda', 'size-4') ?><?= esc(lang('Ui.previous')) ?></span>
                        <?php endif ?>
                    </li>
                    <?php foreach ($resultado->paginasVisiveis() as $pagina) : ?>
                        <li>
                            <?php if ($pagina === null) : ?>
                                <span class="inline-flex min-h-11 min-w-8 items-center justify-center text-ink-muted" aria-hidden="true">…</span>
                            <?php elseif ($pagina === $resultado->pagina) : ?>
                                <span aria-current="page" class="<?= $linkPagina ?> border-brand bg-brand text-brand-ink"><span class="sr-only"><?= esc(lang('Tabela.page')) ?> </span><?= $numero($pagina) ?></span>
                            <?php else : ?>
                                <a href="<?= esc($url(['pagina' => $pagina === 1 ? '' : $pagina]), 'attr') ?>" data-tabela-link data-foco="pagina-<?= $pagina ?>"
                                   class="<?= $linkPagina ?> border-border bg-surface text-ink hover:bg-paper"><span class="sr-only"><?= esc(lang('Tabela.page')) ?> </span><?= $numero($pagina) ?></a>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                    <li>
                        <?php if ($resultado->pagina < $resultado->paginas) : ?>
                            <a href="<?= esc($url(['pagina' => $resultado->pagina + 1]), 'attr') ?>" data-tabela-link data-foco="proxima"
                               class="<?= $linkPagina ?> border-border bg-surface text-ink hover:bg-paper"><?= esc(lang('Ui.next')) ?><?= ui_icon('seta-direita', 'size-4') ?></a>
                        <?php else : ?>
                            <span aria-disabled="true" class="<?= $linkPagina ?> border-border bg-surface text-ink-muted opacity-50 shadow-none"><?= esc(lang('Ui.next')) ?><?= ui_icon('seta-direita', 'size-4') ?></span>
                        <?php endif ?>
                    </li>
                </ul>
            </nav>
        <?php endif ?>
    </div>
</div>
