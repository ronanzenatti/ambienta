<?php

namespace App\Controllers;

use App\Libraries\Cpf;
use App\Libraries\TokenSenha;
use App\Models\Institucional\UsuarioModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Encryption;

/**
 * Recuperação de senha por e-mail. O link leva um token assinado (ver TokenSenha), sem tabela.
 */
class Senha extends BaseController
{
    private const LIMITE_POR_IP   = 5;
    private const JANELA_SEGUNDOS = 3600;

    public function esqueci(): string
    {
        return view('auth/esqueci', ['erro' => null]);
    }

    /**
     * Sempre responde a mesma coisa, exista o cadastro ou não (não revela quem tem conta).
     */
    public function enviar(): ResponseInterface|RedirectResponse|string
    {
        $bucket = 'senha-ip-' . md5($this->request->getIPAddress());

        if (! service('throttler')->check($bucket, self::LIMITE_POR_IP, self::JANELA_SEGUNDOS)) {
            $html = view('auth/esqueci', ['erro' => lang('Auth.tooManyAttempts')]);

            return $this->response->setStatusCode(429)->setBody($html);
        }

        $usuario = $this->buscar(trim((string) $this->request->getPost('identificador')));

        if ($usuario !== null && (bool) $usuario['ativo']) {
            $this->enviarLink($usuario);
        }

        return redirect()->to(site_url('senha/esqueci'))->with('sucesso', lang('Auth.resetSent'));
    }

    public function redefinir(string $token): ResponseInterface|string
    {
        if ($this->usuarioDoToken($token) === null) {
            return $this->response->setStatusCode(410)->setBody(view('auth/redefinir_invalido'));
        }

        return view('auth/redefinir', ['token' => $token, 'erros' => []]);
    }

    public function salvar(string $token): ResponseInterface|RedirectResponse|string
    {
        $usuario = $this->usuarioDoToken($token);

        if ($usuario === null) {
            return $this->response->setStatusCode(410)->setBody(view('auth/redefinir_invalido'));
        }

        $valido = $this->validate([
            'senha'          => [
                'label' => lang('Auth.newPassword'),
                'rules' => 'required|min_length[8]|max_length[72]',
            ],
            'senha_confirma' => ['label' => lang('Auth.confirmPassword'), 'rules' => 'required|matches[senha]'],
        ]);

        if (! $valido) {
            return $this->response->setStatusCode(422)->setBody(
                view('auth/redefinir', ['token' => $token, 'erros' => $this->validator->getErrors()])
            );
        }

        (new UsuarioModel())->update((int) $usuario['id_usuario'], [
            'senha_hash'   => password_hash((string) $this->request->getPost('senha'), PASSWORD_DEFAULT),
            'trocar_senha' => 0,
        ]);

        return redirect()->to(site_url('entrar'))->with('sucesso', lang('Auth.resetDone'));
    }

    /** Escolha da própria senha por quem entrou com a provisória (ver TrocarSenhaFilter). */
    public function trocar(): RedirectResponse|string
    {
        if (! (bool) service('autenticacao')->usuario()['trocar_senha']) {
            return redirect()->to(site_url('/'));
        }

        return view('auth/trocar', ['erros' => []]);
    }

    public function salvarTroca(): ResponseInterface|RedirectResponse|string
    {
        $usuario = service('autenticacao')->usuario();

        if (! (bool) $usuario['trocar_senha']) {
            return redirect()->to(site_url('/'));
        }

        $valido = $this->validate([
            'senha'          => [
                'label' => lang('Auth.newPassword'),
                'rules' => 'required|min_length[8]|max_length[72]',
            ],
            'senha_confirma' => ['label' => lang('Auth.confirmPassword'), 'rules' => 'required|matches[senha]'],
        ]);
        $erros = $valido ? [] : $this->validator->getErrors();
        $nova  = (string) $this->request->getPost('senha');

        // Repetir a provisória não adianta: ela passou por quem a definiu (e talvez por e-mail).
        if ($valido && password_verify($nova, (string) $usuario['senha_hash'])) {
            $erros['senha'] = lang('Auth.mustDiffer');
        }

        if ($erros !== []) {
            return $this->response->setStatusCode(422)->setBody(view('auth/trocar', ['erros' => $erros]));
        }

        (new UsuarioModel())->update((int) $usuario['id_usuario'], [
            'senha_hash'   => password_hash($nova, PASSWORD_DEFAULT),
            'trocar_senha' => 0,
        ]);

        return redirect()->to(site_url('/'))->with('sucesso', lang('Auth.changeDone'));
    }

    /** @return array<string, mixed>|null */
    private function buscar(string $identificador): ?array
    {
        $usuarios = new UsuarioModel();

        if (str_contains($identificador, '@')) {
            return $usuarios->ativoPorEmail(strtolower($identificador));
        }

        $cpf = Cpf::normalizar($identificador);

        return strlen($cpf) === 11 ? $usuarios->ativoPorCpf($cpf) : null;
    }

    /**
     * Usuário a que o token pertence, se o token ainda vale (dentro do prazo, assinatura correta e
     * senha inalterada desde a emissão).
     *
     * @return array<string, mixed>|null
     */
    private function usuarioDoToken(string $token): ?array
    {
        $id = TokenSenha::usuarioDoToken($token);

        if ($id === null) {
            return null;
        }

        $usuario = (new UsuarioModel())->find($id);

        if ($usuario === null || ! (bool) $usuario['ativo']) {
            return null;
        }

        return $this->tokens()->valido($token, $usuario['senha_hash']) ? $usuario : null;
    }

    /**
     * @param array<string, mixed> $usuario
     */
    private function enviarLink(array $usuario): void
    {
        $token = $this->tokens()->gerar((int) $usuario['id_usuario'], $usuario['senha_hash']);
        $link  = site_url('senha/redefinir/' . $token);

        $email     = service('email');
        $host      = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
        $remetente = config(\Config\Email::class)->fromEmail ?: 'nao-responda@' . $host;

        $email->setFrom($remetente, lang('App.name'));
        $email->setTo((string) $usuario['email']);
        $email->setSubject(lang('Auth.resetMailSubject'));
        $email->setMessage(lang('Auth.resetMailBody', [$link]));

        if (! $email->send(false)) {
            log_message('error', 'Falha ao enviar o e-mail de redefinição (usuário {id}).', [
                'id' => $usuario['id_usuario'],
            ]);
        }

        if (ENVIRONMENT === 'development') {
            // Sem SMTP configurado no ambiente local, o link fica no log para permitir o teste manual.
            log_message('info', 'Link de redefinição de senha (só em desenvolvimento): {link}', ['link' => $link]);
        }
    }

    private function tokens(): TokenSenha
    {
        return new TokenSenha(config(Encryption::class)->key);
    }
}
