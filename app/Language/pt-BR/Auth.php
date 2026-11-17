<?php

// Login, Google Workspace e recuperação de senha.
return [
    'loginTitle'         => 'Entrar no Ambienta',
    'identifier'         => 'E-mail ou CPF',
    'password'           => 'Senha',
    'submit'             => 'Entrar',
    'google'             => 'Entrar com Google Workspace',
    'or'                 => 'ou',
    'forgot'             => 'Esqueci minha senha',
    'invalidCredentials' => 'E-mail/CPF ou senha incorretos.',
    'tooManyAttempts'    => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.',
    'loggedOut'          => 'Você saiu do sistema.',
    'loginRequired'      => 'Entre para continuar.',

    'googleUnavailable'         => 'O login com Google não está configurado neste ambiente.',
    'googleFailed'              => 'Não foi possível concluir o login com o Google. Tente novamente.',
    'googleEmailNotVerified'    => 'O Google não confirmou este e-mail.',
    'googleDomainNotAllowed'    => 'O domínio do seu e-mail não está autorizado a acessar o sistema.',
    'googleNotRegistered'       => 'Seu e-mail ainda não foi cadastrado. Procure a administração do campus.',
    'googleConflict'            => 'Este cadastro já está vinculado a outra conta Google.',

    'resetTitle'     => 'Recuperar senha',
    'resetIntro'     => 'Informe seu e-mail ou CPF. Se houver cadastro, enviaremos um link para criar uma nova senha.',
    'resetSubmit'    => 'Enviar link',
    'resetSent'      => 'Se o cadastro existir, enviamos o link para o e-mail cadastrado. Ele vale por 1 hora.',
    'resetNewTitle'  => 'Definir nova senha',
    'newPassword'    => 'Nova senha',
    'confirmPassword' => 'Repita a nova senha',
    'passwordHelp'   => 'Mínimo de 8 caracteres.',
    'resetInvalid'   => 'Este link é inválido ou já foi usado. Solicite um novo.',
    'resetDone'      => 'Senha alterada. Entre com a nova senha.',
    'resetMailSubject' => 'Ambienta: redefinição de senha',
    'resetMailBody'  => "Recebemos um pedido para redefinir a senha da sua conta no Ambienta.\n\n"
        . "Para escolher uma nova senha, acesse o link abaixo (vale por 1 hora e só pode ser usado uma vez):\n{0}\n\n"
        . 'Se você não fez esse pedido, ignore esta mensagem.',

    'mustChangeNotice' => 'Sua senha é provisória. Escolha uma nova senha para continuar.',
    'changeTitle'    => 'Escolha a sua senha',
    'changeIntro'    => 'Você entrou com uma senha provisória. Crie a sua própria senha para usar o sistema.',
    'mustDiffer'     => 'A nova senha precisa ser diferente da provisória.',
    'changeDone'     => 'Senha alterada.',
];
