<?php

namespace App\Controllers;

use App\Filters\AuthFilter;
use App\Models\Institucional\UsuarioModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\Google;

/**
 * Entrada e saída do sistema: login por e-mail/CPF e senha, login com Google Workspace,
 * logout e troca de idioma da interface.
 */
class Auth extends BaseController
{
    /** Tentativas de login por identificador e por IP dentro da janela (bucket do Throttler). */
    private const LIMITE_POR_IDENTIFICADOR = 5;
    private const LIMITE_POR_IP            = 20;
    private const JANELA_SEGUNDOS          = 900;

    private const SESSAO_GOOGLE_STATE = 'oauth2_state';
    private const SESSAO_GOOGLE_PKCE  = 'oauth2_pkce';

    public function entrar(): ResponseInterface|RedirectResponse|string
    {
        if (service('autenticacao')->logado()) {
            return redirect()->to(site_url('/'));
        }

        return $this->formularioLogin();
    }

    public function autenticar(): ResponseInterface|RedirectResponse|string
    {
        if (service('autenticacao')->logado()) {
            return redirect()->to(site_url('/'));
        }

        $identificador = trim((string) $this->request->getPost('identificador'));
        $senha         = (string) $this->request->getPost('senha');

        if ($identificador === '' || $senha === '') {
            return $this->formularioLogin($identificador, lang('Auth.invalidCredentials'), 422);
        }

        $throttler = service('throttler');
        $ip        = $this->request->getIPAddress();
        $porConta  = 'login-id-' . md5(strtolower($identificador) . '|' . $ip);

        if (
            ! $throttler->check($porConta, self::LIMITE_POR_IDENTIFICADOR, self::JANELA_SEGUNDOS)
            || ! $throttler->check('login-ip-' . md5($ip), self::LIMITE_POR_IP, self::JANELA_SEGUNDOS)
        ) {
            return $this->formularioLogin($identificador, lang('Auth.tooManyAttempts'), 429);
        }

        $autenticacao = service('autenticacao');
        $usuario      = $autenticacao->tentarSenha($identificador, $senha);

        if ($usuario === null) {
            return $this->formularioLogin($identificador, lang('Auth.invalidCredentials'), 401);
        }

        return $this->concluirLogin($usuario);
    }

    public function sair(): RedirectResponse
    {
        service('autenticacao')->sair();

        return redirect()->to(site_url('entrar'))->with('sucesso', lang('Auth.loggedOut'));
    }

    /** Troca o idioma da interface: vale na sessão e, para quem está logado, vira preferência salva. */
    public function idioma(): RedirectResponse
    {
        $idioma = (string) $this->request->getPost('idioma');

        if (in_array($idioma, config(App::class)->supportedLocales, true)) {
            $autenticacao = service('autenticacao');
            $autenticacao->definirIdioma($idioma);

            $usuario = $autenticacao->usuario();
            if ($usuario !== null) {
                (new UsuarioModel())->update((int) $usuario['id_usuario'], ['idioma_preferido' => $idioma]);
            }
        }

        return redirect()->to($this->paginaAnterior());
    }

    /** Inicia o fluxo OAuth2 (authorization code + PKCE) com o Google. */
    public function google(): RedirectResponse
    {
        if (! config(Google::class)->habilitado()) {
            return redirect()->to(site_url('entrar'))->with('erro', lang('Auth.googleUnavailable'));
        }

        $provider = service('googleProvider');
        $url      = $provider->getAuthorizationUrl([
            'scope'  => ['openid', 'email', 'profile'],
            'prompt' => 'select_account',
        ]);

        session()->set([
            self::SESSAO_GOOGLE_STATE => $provider->getState(),
            self::SESSAO_GOOGLE_PKCE  => $provider->getPkceCode(),
        ]);

        return redirect()->to($url);
    }

    /** Retorno do Google: confere state, troca o código, valida domínio/cadastro e abre a sessão. */
    public function googleRetorno(): RedirectResponse
    {
        $sessao   = session();
        $state    = (string) $sessao->get(self::SESSAO_GOOGLE_STATE);
        $pkce     = (string) $sessao->get(self::SESSAO_GOOGLE_PKCE);
        $recebido = (string) $this->request->getGet('state');
        $codigo   = (string) $this->request->getGet('code');

        $sessao->remove([self::SESSAO_GOOGLE_STATE, self::SESSAO_GOOGLE_PKCE]);

        $negado = $this->request->getGet('error') !== null;

        if ($state === '' || ! hash_equals($state, $recebido) || $codigo === '' || $negado) {
            return $this->falhaGoogle('Auth.googleFailed');
        }

        try {
            $provider = service('googleProvider');
            $provider->setPkceCode($pkce);

            $token = $provider->getAccessToken('authorization_code', ['code' => $codigo]);
            $dados = $provider->getResourceOwner($token)->toArray();
        } catch (\Throwable $e) {
            log_message('error', 'Falha no login com Google: {mensagem}', ['mensagem' => $e->getMessage()]);

            return $this->falhaGoogle('Auth.googleFailed');
        }

        $resultado = service('autenticacao')->resolverGoogle($dados);

        if ($resultado['usuario'] === null) {
            return $this->falhaGoogle((string) $resultado['erro']);
        }

        return $this->concluirLogin($resultado['usuario']);
    }

    /**
     * @param array<string, mixed> $usuario
     */
    private function concluirLogin(array $usuario): RedirectResponse
    {
        $sessao  = session();
        $destino = $sessao->get(AuthFilter::SESSAO_DESTINO);
        service('autenticacao')->entrar($usuario);
        $sessao->remove(AuthFilter::SESSAO_DESTINO);

        return redirect()->to(site_url($this->destinoLocal(is_string($destino) ? $destino : null)));
    }

    private function falhaGoogle(string $chaveDeIdioma): RedirectResponse
    {
        return redirect()->to(site_url('entrar'))->with('erro', lang($chaveDeIdioma));
    }

    private function formularioLogin(
        string $identificador = '',
        ?string $erro = null,
        int $status = 200
    ): ResponseInterface|string {
        $html = view('auth/entrar', [
            'identificador'    => $identificador,
            'erro'             => $erro,
            'googleHabilitado' => config(Google::class)->habilitado(),
        ]);

        return $status === 200 ? $html : $this->response->setStatusCode($status)->setBody($html);
    }

    /** Página de onde veio a requisição, se for do próprio site; senão a inicial. */
    private function paginaAnterior(): string
    {
        $referer = $this->request->getServer('HTTP_REFERER');

        if (! is_string($referer) || $referer === '') {
            return site_url('/');
        }

        $partes = parse_url($referer);
        $base   = parse_url(base_url());

        if (($partes['host'] ?? null) !== ($base['host'] ?? null)) {
            return site_url('/');
        }

        return $referer;
    }
}
