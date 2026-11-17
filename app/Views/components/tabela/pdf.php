<?php

/**
 * Documento PDF de uma listagem (Dompdf). Só texto: nada de recurso remoto (isRemoteEnabled = false),
 * fonte DejaVu Sans embutida no Dompdf (acentos e símbolos). Cores vêm de tokens.json via TokensDesign.
 *
 * @var App\Libraries\Tabela\Tabela       $tabela
 * @var list<list<string>>                $linhas
 * @var App\Libraries\Tabela\Abrangencia  $abrangencia
 * @var string                            $usuario
 * @var Closure(string): string           $cor
 */
$escopos = [
    'pagina'   => lang('Tabela.scopePage'),
    'pesquisa' => lang('Tabela.scopeSearch'),
    'tudo'     => lang('Tabela.scopeAll'),
];
$busca = $abrangencia->value === 'tudo' ? '' : $tabela->parametros()->busca;
?>
<!doctype html>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>">
<head>
    <meta charset="utf-8">
    <title><?= esc($tabela->titulo()) ?></title>
    <style>
        @page { margin: 28px 32px 44px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: <?= $cor('ink') ?>; }
        h1 { font-size: 15px; margin: 0 0 4px; }
        .meta { color: <?= $cor('ink-muted') ?>; margin: 0 0 12px; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-weight: bold; color: <?= $cor('ink-muted') ?>; border-bottom: 1px solid <?= $cor('border-strong') ?>; padding: 5px 6px; }
        td { padding: 4px 6px; border-bottom: 1px solid <?= $cor('border') ?>; vertical-align: top; }
        tr:nth-child(even) td { background: <?= $cor('table-stripe') ?>; }
        .num { text-align: right; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1><?= esc($tabela->titulo()) ?></h1>
    <p class="meta">
        <?= esc(lang('Tabela.pdfGenerated', [date(lang('Institucional.dateFormat') . ' H:i'), $usuario])) ?><br>
        <?= esc(lang('Tabela.pdfScope', [$escopos[$abrangencia->value]])) ?>
        <?php if ($busca !== '') : ?>
            · <?= esc(lang('Tabela.pdfSearch', [$busca])) ?>
        <?php endif ?>
        · <?= esc(lang('Tabela.pdfTotal', [ui_numero(count($linhas))])) ?>
        <?php foreach ($tabela->cabecalhoExportacao() as $linhaCabecalho) : ?>
            <br><?= esc($linhaCabecalho) ?>
        <?php endforeach ?>
    </p>
    <table>
        <thead>
            <tr>
                <?php foreach ($tabela->colunas() as $coluna) : ?>
                    <th<?= $coluna->ehNumerica() ? ' class="num"' : '' ?>><?= esc($coluna->rotulo) ?></th>
                <?php endforeach ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($linhas as $linha) : ?>
                <tr>
                    <?php foreach ($tabela->colunas() as $i => $coluna) : ?>
                        <td<?= $coluna->ehNumerica() ? ' class="num"' : '' ?>><?= esc($linha[$i]) ?></td>
                    <?php endforeach ?>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</body>
</html>
