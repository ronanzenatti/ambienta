<?php

/**
 * Endereço e coordenadas de um campus ou prédio, só leitura. O link para o mapa é externo e só é
 * seguido se a pessoa clicar (nenhum mapa de terceiros é embutido na página).
 *
 * @var array<string, mixed> $registro
 */
$linha1 = trim(implode(', ', array_filter([
    (string) ($registro['logradouro'] ?? ''),
    (string) ($registro['numero_endereco'] ?? ''),
    (string) ($registro['complemento'] ?? ''),
])));
$linha2 = trim(implode(' · ', array_filter([
    (string) ($registro['bairro'] ?? ''),
    trim(implode(' / ', array_filter([(string) ($registro['cidade'] ?? ''), (string) ($registro['uf'] ?? '')]))),
    (string) ($registro['cep'] ?? ''),
])));
$temCoordenadas = ($registro['latitude'] ?? null) !== null && ($registro['longitude'] ?? null) !== null;
?>
<div class="text-body">
    <?php if ($linha1 === '' && $linha2 === '') : ?>
        <p class="text-ink-muted"><?= esc(lang('Institucional.noAddress')) ?></p>
    <?php else : ?>
        <?php if ($linha1 !== '') : ?>
            <p><?= esc($linha1) ?></p>
        <?php endif ?>
        <?php if ($linha2 !== '') : ?>
            <p><?= esc($linha2) ?></p>
        <?php endif ?>
    <?php endif ?>
    <?php if ($temCoordenadas) : ?>
        <?php
        $lat = (float) $registro['latitude'];
        $lon = (float) $registro['longitude'];
        $url = 'https://www.openstreetmap.org/?mlat=' . rawurlencode((string) $lat) . '&mlon=' . rawurlencode((string) $lon) . '#map=18/' . $lat . '/' . $lon;
        ?>
        <p class="mt-space-1 text-data tabular-nums text-ink-muted"><?= esc($registro['latitude']) ?>, <?= esc($registro['longitude']) ?></p>
        <a href="<?= esc($url, 'attr') ?>" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center text-body underline">
            <?= esc(lang('Institucional.openMap')) ?>
        </a>
    <?php endif ?>
</div>
