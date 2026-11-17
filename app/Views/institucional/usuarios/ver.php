<?php

/**
 * Um usuário: dados, perfis com escopo e exclusão/anonimização. Sem permissão de edição sobre este
 * usuário, a página é só de consulta.
 *
 * @var array<string, mixed>                     $usuario
 * @var array<string, mixed>                     $dados
 * @var list<array<string, mixed>>               $vinculos
 * @var array<string, string>                    $erros
 * @var bool                                     $podeEditar
 * @var bool                                     $proprio
 * @var bool                                     $ultimoSuper
 * @var array{global: bool, campus: bool, predios: bool}                     $escopos
 * @var array<string, mixed>                     $vinculo
 */
helper('usuarios');

$id            = (int) $usuario['id_usuario'];
$podeRemover   = $podeEditar && ! $proprio && ! $ultimoSuper;
$ativoMarcado  = (bool) ($dados['ativo'] ?? false);
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($usuario['nome']) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div class="mb-space-6 flex flex-wrap items-center justify-between gap-space-4">
        <h1 class="text-display"><?= esc($usuario['nome']) ?></h1>
        <?= ui_button(lang('Ui.back'), 'secondary', ['href' => site_url('usuarios')]) ?>
    </div>

    <?php if (! $podeEditar) : ?>
        <p class="mb-space-4 text-body text-ink-muted"><?= esc(lang('Usuarios.readOnly')) ?></p>
    <?php endif ?>

    <?php if ($ultimoSuper && $podeEditar) : ?>
        <?= ui_alert('pending', lang('Usuarios.lastAdmin')) ?>
    <?php endif ?>

    <section aria-labelledby="dados-titulo" class="mb-space-8">
        <h2 id="dados-titulo" class="mb-space-4 border-b border-border pb-space-2 text-title"><?= esc(lang('Usuarios.formEdit')) ?></h2>

        <?php if ($podeEditar) : ?>
            <form method="post" action="<?= site_url('usuarios/' . $id) ?>" class="max-w-xl rounded-md border border-border bg-surface shadow-xs p-space-6">
                <?= csrf_field() ?>
                <?= ui_field('nome', lang('Usuarios.fieldName'), 'text', (string) $dados['nome'], $erros['nome'] ?? null, null, ['required' => true, 'maxlength' => '255', 'autocomplete' => 'off']) ?>
                <?= ui_field('email', lang('Usuarios.fieldEmail'), 'email', (string) $dados['email'], $erros['email'] ?? null, null, ['required' => true, 'maxlength' => '255', 'autocomplete' => 'off']) ?>
                <?= ui_field('cpf', lang('Usuarios.fieldCpf'), 'text', (string) ($dados['cpf'] ?? ''), $erros['cpf'] ?? null, null, ['inputmode' => 'numeric', 'maxlength' => '14', 'autocomplete' => 'off']) ?>
                <?= ui_field('senha', lang('Usuarios.fieldPasswordEdit'), 'password', null, $erros['senha'] ?? null, lang('Usuarios.passwordHelp'), ['autocomplete' => 'new-password', 'minlength' => '8']) ?>
                <?= ui_select('idioma_preferido', lang('Usuarios.fieldLanguage'), usuarios_idiomas(), (string) $dados['idioma_preferido'], $erros['idioma_preferido'] ?? null) ?>
                <?= ui_checkbox('ativo', lang('Usuarios.fieldActive'), $ativoMarcado) ?>
                <?php if (isset($erros['ativo'])) : ?>
                    <p class="mb-space-4 text-data text-signal-critical"><?= esc($erros['ativo']) ?></p>
                <?php endif ?>
                <?= ui_button(lang('Ui.edit'), 'edit', ['type' => 'submit']) ?>
            </form>
        <?php else : ?>
            <dl class="grid max-w-xl grid-cols-[max-content_1fr] gap-x-space-6 gap-y-space-2 text-body">
                <dt class="text-ink-muted"><?= esc(lang('Usuarios.fieldEmail')) ?></dt>
                <dd><?= esc($usuario['email']) ?></dd>
                <dt class="text-ink-muted"><?= esc(lang('Ui.status')) ?></dt>
                <dd>
                    <?= (bool) $usuario['ativo']
                        ? ui_status_dot('confirmed', lang('Ui.active'))
                        : ui_status_dot('critical', lang('Ui.inactive')) ?>
                </dd>
                <dt class="text-ink-muted"><?= esc(lang('Usuarios.colAccess')) ?></dt>
                <dd class="tabular-nums">
                    <?= $usuario['ultimo_acesso_em'] === null ? esc(lang('Ui.never')) : esc(date('d/m/Y H:i', strtotime((string) $usuario['ultimo_acesso_em']))) ?>
                </dd>
            </dl>
        <?php endif ?>
    </section>

    <section aria-labelledby="perfis-titulo" class="mb-space-8">
        <h2 id="perfis-titulo" class="mb-space-4 border-b border-border pb-space-2 text-title"><?= esc(lang('Usuarios.profilesTitle')) ?></h2>

        <?php if ($vinculos === []) : ?>
            <p class="mb-space-4 text-body text-ink-muted"><?= esc(lang('Usuarios.noProfiles')) ?></p>
        <?php else : ?>
            <ul class="mb-space-6 max-w-xl divide-y divide-border rounded-md border border-border bg-surface shadow-xs">
                <?php foreach ($vinculos as $item) : ?>
                    <li class="flex flex-wrap items-center justify-between gap-space-3 px-space-4 py-space-3">
                        <span class="text-body"><?= esc(usuarios_rotulo_vinculo($item)) ?></span>
                        <?php if ($podeEditar) : ?>
                            <?= ui_button(lang('Ui.delete'), 'delete', [
                                '@click'     => "\$dispatch('open-modal', 'remover-perfil-{$item['id_usuario_perfil']}')",
                                'aria-label' => lang('Ui.delete') . ': ' . usuarios_rotulo_vinculo($item),
                            ]) ?>
                            <?= ui_confirm_delete(
                                'remover-perfil-' . $item['id_usuario_perfil'],
                                lang('Usuarios.removeProfileText', [(string) $item['perfil_nome']]),
                                site_url("usuarios/{$id}/perfis/{$item['id_usuario_perfil']}"),
                                lang('Usuarios.removeProfileTitle')
                            ) ?>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <?php if ($podeEditar) : ?>
            <form method="post" action="<?= site_url("usuarios/{$id}/perfis") ?>" class="max-w-xl rounded-md border border-border bg-surface shadow-xs p-space-6">
                <?= csrf_field() ?>
                <h3 class="mb-space-4 text-heading"><?= esc(lang('Usuarios.addProfile')) ?></h3>
                <?= view('institucional/usuarios/_vinculo_campos', [
                    'escopos' => $escopos, 'vinculo' => $vinculo, 'erros' => $erros, 'obrigatorio' => true,
                ], ['saveData' => false]) ?>
                <?= ui_button(lang('Usuarios.addProfile'), 'primary', ['type' => 'submit']) ?>
            </form>
        <?php endif ?>
    </section>

    <?php if ($podeRemover) : ?>
        <section aria-labelledby="perigo-titulo">
            <h2 id="perigo-titulo" class="mb-space-4 border-b border-border pb-space-2 text-title"><?= esc(lang('Usuarios.dangerZone')) ?></h2>
            <div class="flex flex-wrap gap-space-3">
                <?= ui_button(lang('Usuarios.deleteTitle'), 'delete', ['@click' => "\$dispatch('open-modal', 'excluir-usuario')"]) ?>
                <?= ui_button(lang('Usuarios.anonymize'), 'delete', ['@click' => "\$dispatch('open-modal', 'anonimizar-usuario')"]) ?>
            </div>
            <?= ui_confirm_delete('excluir-usuario', lang('Usuarios.deleteText', [(string) $usuario['nome']]), site_url("usuarios/{$id}"), lang('Usuarios.deleteTitle')) ?>
            <?= ui_confirm_delete('anonimizar-usuario', lang('Usuarios.anonymizeText', [(string) $usuario['nome']]), site_url("usuarios/{$id}/anonimizar"), lang('Usuarios.anonymizeTitle')) ?>
        </section>
    <?php endif ?>
<?= $this->endSection() ?>
