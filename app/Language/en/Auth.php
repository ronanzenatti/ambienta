<?php

// Sign-in, Google Workspace and password recovery.
return [
    'loginTitle'         => 'Sign in to Ambienta',
    'identifier'         => 'Email or CPF',
    'password'           => 'Password',
    'submit'             => 'Sign in',
    'google'             => 'Sign in with Google Workspace',
    'or'                 => 'or',
    'forgot'             => 'Forgot my password',
    'invalidCredentials' => 'Incorrect email/CPF or password.',
    'tooManyAttempts'    => 'Too many attempts. Wait a few minutes and try again.',
    'loggedOut'          => 'You have signed out.',
    'loginRequired'      => 'Sign in to continue.',

    'googleUnavailable'         => 'Google sign-in is not configured in this environment.',
    'googleFailed'              => 'Could not complete the Google sign-in. Please try again.',
    'googleEmailNotVerified'    => 'Google has not verified this email.',
    'googleDomainNotAllowed'    => 'Your email domain is not authorized to access the system.',
    'googleNotRegistered'       => 'Your email has not been registered yet. Contact the campus administration.',
    'googleConflict'            => 'This account is already linked to a different Google account.',

    'resetTitle'     => 'Recover password',
    'resetIntro'     => 'Enter your email or CPF. If there is an account, we will send a link to create a new password.',
    'resetSubmit'    => 'Send link',
    'resetSent'      => 'If the account exists, we sent the link to the registered email. It is valid for 1 hour.',
    'resetNewTitle'  => 'Set a new password',
    'newPassword'    => 'New password',
    'confirmPassword' => 'Repeat the new password',
    'passwordHelp'   => 'At least 8 characters.',
    'resetInvalid'   => 'This link is invalid or has already been used. Request a new one.',
    'resetDone'      => 'Password changed. Sign in with the new password.',
    'resetMailSubject' => 'Ambienta: password reset',
    'resetMailBody'  => "We received a request to reset the password of your Ambienta account.\n\n"
        . "To choose a new password, open the link below (valid for 1 hour and usable only once):\n{0}\n\n"
        . 'If you did not make this request, ignore this message.',

    'mustChangeNotice' => 'Your password is temporary. Choose a new password to continue.',
    'changeTitle'    => 'Choose your password',
    'changeIntro'    => 'You signed in with a temporary password. Create your own password to use the system.',
    'mustDiffer'     => 'The new password must differ from the temporary one.',
    'changeDone'     => 'Password changed.',
];
