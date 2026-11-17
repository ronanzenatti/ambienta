<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Credenciais do login com Google Workspace. Valores vêm do .env (google.clientId,
 * google.clientSecret, google.redirectUri); nunca ficam no repositório.
 * Sem clientId/clientSecret o botão do Google não aparece e a rota responde com erro amigável.
 */
class Google extends BaseConfig
{
    public string $clientId     = '';
    public string $clientSecret = '';

    /** Vazio = site_url('auth/google/callback'). Deve ser idêntico ao cadastrado no Google Cloud Console. */
    public string $redirectUri = '';

    public function habilitado(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function redirectUri(): string
    {
        return $this->redirectUri !== '' ? $this->redirectUri : site_url('auth/google/callback');
    }
}
