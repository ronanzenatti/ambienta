<?php

// Domínios de e-mail permitidos no login com Google Workspace.
return [
    'title'       => 'Domínios de e-mail permitidos',
    'intro'       => 'Só e-mails destes domínios podem entrar com Google Workspace. Além do domínio, o usuário precisa já estar cadastrado.',
    'colDomain'   => 'Domínio',
    'fieldDomain' => 'Domínio (ex.: fatec.sp.gov.br)',
    'new'         => 'Novo domínio',
    'empty'       => 'Nenhum domínio cadastrado: ninguém consegue entrar com Google.',
    'invalid'     => 'Informe apenas o domínio, sem @ (ex.: fatec.sp.gov.br).',
    'exists'      => 'Este domínio já está cadastrado.',
    'added'       => 'Domínio adicionado.',
    'activate'    => 'Ativar',
    'deactivate'  => 'Desativar',
    'toggled'     => 'Situação do domínio alterada.',
    'deleteTitle' => 'Excluir domínio',
    'deleteText'  => 'O domínio {0} será removido: e-mails dele deixam de entrar com Google.',
    'deleted'     => 'Domínio excluído.',
    'notFound'    => 'Domínio não encontrado.',
];
