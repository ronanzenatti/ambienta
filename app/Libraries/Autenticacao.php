<?php

namespace App\Libraries;

use App\Models\Institucional\DominioEmailPermitidoModel;
use App\Models\Institucional\UsuarioModel;
use Closure;
use CodeIgniter\Session\Session;
use Config\Services;

/**
 * Usuário da sessão: login por e-mail/CPF + senha ou por conta Google Workspace, e logout.
 *
 * A sessão guarda só o id do usuário; o registro é recarregado do banco a cada requisição, então
 * desativar, excluir ou anonimizar um usuário derruba o acesso dele imediatamente.
 */
class Autenticacao
{
    public const SESSAO_USUARIO = 'id_usuario';
    public const SESSAO_IDIOMA  = 'idioma';

    /** @var array<string, mixed>|null */
    private ?array $usuario = null;
    private bool $carregado = false;

    private static ?string $hashFalso = null;

    private ?Session $sessao = null;

    /**
     * @param Closure(): Session $fabricaSessao Só é chamada quando a sessão é realmente necessária:
     *                                          visitante anônimo (consulta pública) não ganha arquivo de sessão.
     */
    public function __construct(private readonly Closure $fabricaSessao, private readonly UsuarioModel $usuarios)
    {
    }

    /** Idioma guardado na sessão (escolhido no cabeçalho ou preferido do usuário), se houver. */
    public function idioma(): ?string
    {
        $idioma = $this->sessaoIniciada() ? $this->sessao()->get(self::SESSAO_IDIOMA) : null;

        return is_string($idioma) ? $idioma : null;
    }

    /** Mensagem de retorno de uma ação anterior (flashdata: 'sucesso', 'aviso' ou 'erro'), lida uma vez. */
    public function mensagem(string $tipo): ?string
    {
        $mensagem = $this->sessaoIniciada() ? $this->sessao()->getFlashdata($tipo) : null;

        return is_string($mensagem) ? $mensagem : null;
    }

    public function definirIdioma(string $idioma): void
    {
        $this->sessao()->set(self::SESSAO_IDIOMA, $idioma);
    }

    private function sessao(): Session
    {
        return $this->sessao ??= ($this->fabricaSessao)();
    }

    /** Sessão já existente (cookie recebido ou já ativa)? Evita criar sessão para quem só navega. */
    private function sessaoIniciada(): bool
    {
        return $this->sessao !== null
            || session_status() === PHP_SESSION_ACTIVE
            || isset($_COOKIE[config('Session')->cookieName])
            || ! empty($_SESSION);
    }

    /** @return array<string, mixed>|null */
    public function usuario(): ?array
    {
        if (! $this->carregado) {
            $this->carregado = true;
            $id              = $this->sessaoIniciada() ? $this->sessao()->get(self::SESSAO_USUARIO) : null;
            $usuario         = is_numeric($id) ? $this->usuarios->find((int) $id) : null;

            if ($usuario !== null && (bool) $usuario['ativo']) {
                $this->usuario = $usuario;
            } elseif ($id !== null) {
                $this->sessao()->remove(self::SESSAO_USUARIO);
            }
        }

        return $this->usuario;
    }

    public function id(): ?int
    {
        $usuario = $this->usuario();

        return $usuario === null ? null : (int) $usuario['id_usuario'];
    }

    public function logado(): bool
    {
        return $this->usuario() !== null;
    }

    /**
     * Abre a sessão do usuário: novo id de sessão (contra fixação), idioma preferido e último acesso.
     *
     * @param array<string, mixed> $usuario
     */
    public function entrar(array $usuario): void
    {
        $sessao = $this->sessao();

        $sessao->regenerate();
        $sessao->set(self::SESSAO_USUARIO, (int) $usuario['id_usuario']);
        $sessao->set(self::SESSAO_IDIOMA, $usuario['idioma_preferido']);

        $this->usuarios->update((int) $usuario['id_usuario'], ['ultimo_acesso_em' => date('Y-m-d H:i:s')]);

        $this->carregado = false;
        $this->usuario   = null;
        Services::resetSingle('autorizacao');
    }

    public function sair(): void
    {
        $sessao = $this->sessao();

        // Mantém a sessão viva (o idioma escolhido e a mensagem de "saiu" continuam), mas sem o
        // usuário e com um novo id, para o id antigo deixar de valer.
        $sessao->remove(self::SESSAO_USUARIO);
        $sessao->regenerate(true);

        $this->carregado = true;
        $this->usuario   = null;
        Services::resetSingle('autorizacao');
    }

    /**
     * Confere e-mail ou CPF + senha. Devolve o usuário ou null; nunca diz qual dos dois falhou e
     * gasta o mesmo tempo com usuário inexistente (contra enumeração de contas).
     *
     * @return array<string, mixed>|null
     */
    public function tentarSenha(string $identificador, string $senha): ?array
    {
        $usuario = $this->buscarPorIdentificador(trim($identificador));
        $hash    = $usuario['senha_hash'] ?? null;
        $confere = password_verify($senha, is_string($hash) && $hash !== '' ? $hash : self::hashFalso());

        if ($usuario === null || ! $confere || ! (bool) $usuario['ativo']) {
            return null;
        }

        if (password_needs_rehash((string) $hash, PASSWORD_DEFAULT)) {
            $this->usuarios->update((int) $usuario['id_usuario'], [
                'senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
            ]);
        }

        return $usuario;
    }

    /**
     * Resolve o acesso de uma conta Google já autenticada pelo provider. Só entra quem já tem cadastro
     * (o perfil vem de quem administra) e cujo e-mail é de um domínio permitido e verificado.
     *
     * @param array<string, mixed> $dadosGoogle sub, email, email_verified, picture
     *
     * @return array{usuario: ?array<string, mixed>, erro: ?string} erro = chave de idioma (Auth.*)
     */
    public function resolverGoogle(array $dadosGoogle): array
    {
        $sub   = (string) ($dadosGoogle['sub'] ?? '');
        $email = strtolower(trim((string) ($dadosGoogle['email'] ?? '')));

        if ($sub === '' || ! str_contains($email, '@')) {
            return ['usuario' => null, 'erro' => 'Auth.googleFailed'];
        }

        if (! filter_var($dadosGoogle['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return ['usuario' => null, 'erro' => 'Auth.googleEmailNotVerified'];
        }

        $dominio = substr($email, (int) strrpos($email, '@') + 1);

        if (! (new DominioEmailPermitidoModel())->permite($dominio)) {
            return ['usuario' => null, 'erro' => 'Auth.googleDomainNotAllowed'];
        }

        $usuario = $this->usuarios->ativoPorGoogleId($sub);

        if ($usuario === null) {
            $usuario = $this->usuarios->ativoPorEmail($email);

            if ($usuario === null) {
                return ['usuario' => null, 'erro' => 'Auth.googleNotRegistered'];
            }

            if (! empty($usuario['google_id']) && $usuario['google_id'] !== $sub) {
                return ['usuario' => null, 'erro' => 'Auth.googleConflict'];
            }

            $vinculo = ['google_id' => $sub];

            if (empty($usuario['avatar']) && ! empty($dadosGoogle['picture'])) {
                $vinculo['avatar'] = mb_substr((string) $dadosGoogle['picture'], 0, 255);
            }

            $this->usuarios->update((int) $usuario['id_usuario'], $vinculo);
            $usuario = array_merge($usuario, $vinculo);
        }

        if (! (bool) $usuario['ativo']) {
            return ['usuario' => null, 'erro' => 'Auth.googleNotRegistered'];
        }

        return ['usuario' => $usuario, 'erro' => null];
    }

    /** @return array<string, mixed>|null */
    private function buscarPorIdentificador(string $identificador): ?array
    {
        if ($identificador === '') {
            return null;
        }

        if (str_contains($identificador, '@')) {
            return $this->usuarios->ativoPorEmail(strtolower($identificador));
        }

        $cpf = Cpf::normalizar($identificador);

        return strlen($cpf) === 11 ? $this->usuarios->ativoPorCpf($cpf) : null;
    }

    private static function hashFalso(): string
    {
        return self::$hashFalso ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }
}
