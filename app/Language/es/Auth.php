<?php

// Inicio de sesión, Google Workspace y recuperación de contraseña.
return [
    'loginTitle'         => 'Ingresar a Ambienta',
    'identifier'         => 'Correo o CPF',
    'password'           => 'Contraseña',
    'submit'             => 'Ingresar',
    'google'             => 'Ingresar con Google Workspace',
    'or'                 => 'o',
    'forgot'             => 'Olvidé mi contraseña',
    'invalidCredentials' => 'Correo/CPF o contraseña incorrectos.',
    'tooManyAttempts'    => 'Demasiados intentos. Espere unos minutos e inténtelo de nuevo.',
    'loggedOut'          => 'Usted salió del sistema.',
    'loginRequired'      => 'Ingrese para continuar.',

    'googleUnavailable'         => 'El acceso con Google no está configurado en este entorno.',
    'googleFailed'              => 'No fue posible completar el acceso con Google. Inténtelo de nuevo.',
    'googleEmailNotVerified'    => 'Google no confirmó este correo.',
    'googleDomainNotAllowed'    => 'El dominio de su correo no está autorizado para acceder al sistema.',
    'googleNotRegistered'       => 'Su correo aún no fue registrado. Comuníquese con la administración del campus.',
    'googleConflict'            => 'Este registro ya está vinculado a otra cuenta de Google.',

    'resetTitle'     => 'Recuperar contraseña',
    'resetIntro'     => 'Indique su correo o CPF. Si existe un registro, enviaremos un enlace para crear una nueva contraseña.',
    'resetSubmit'    => 'Enviar enlace',
    'resetSent'      => 'Si el registro existe, enviamos el enlace al correo registrado. Vale por 1 hora.',
    'resetNewTitle'  => 'Definir nueva contraseña',
    'newPassword'    => 'Nueva contraseña',
    'confirmPassword' => 'Repita la nueva contraseña',
    'passwordHelp'   => 'Mínimo de 8 caracteres.',
    'resetInvalid'   => 'Este enlace no es válido o ya fue utilizado. Solicite uno nuevo.',
    'resetDone'      => 'Contraseña cambiada. Ingrese con la nueva contraseña.',
    'resetMailSubject' => 'Ambienta: restablecimiento de contraseña',
    'resetMailBody'  => "Recibimos una solicitud para restablecer la contraseña de su cuenta de Ambienta.\n\n"
        . "Para elegir una nueva contraseña, abra el enlace (vale por 1 hora y solo puede usarse una vez):\n{0}\n\n"
        . 'Si usted no hizo esta solicitud, ignore este mensaje.',

    'mustChangeNotice' => 'Su contraseña es provisional. Elija una nueva contraseña para continuar.',
    'changeTitle'    => 'Elija su contraseña',
    'changeIntro'    => 'Ingresó con una contraseña provisional. Cree su propia contraseña para usar el sistema.',
    'mustDiffer'     => 'La nueva contraseña debe ser diferente de la provisional.',
    'changeDone'     => 'Contraseña cambiada.',
];
