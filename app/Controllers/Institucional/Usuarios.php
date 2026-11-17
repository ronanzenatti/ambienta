<?php

namespace App\Controllers\Institucional;

use App\Controllers\BaseController;
use App\Libraries\AnonimizadorUsuario;
use App\Libraries\Autorizacao;
use App\Libraries\Cpf;
use App\Libraries\Opcoes\Fonte;
use App\Models\Institucional\PerfilModel;
use App\Models\Institucional\UsuarioModel;
use App\Models\Institucional\UsuarioPerfilModel;
use App\Tabelas\Institucional\UsuariosTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Psr\Log\LoggerInterface;

/**
 * Usuários e atribuição de perfis (permissão usuarios.acesso; ver Config/Routes.php).
 *
 * Regra de escopo: quem administra (editor) só age sobre usuários cujos vínculos de perfil estão
 * TODOS dentro do seu escopo; sem nenhum vínculo, só o escopo global administra. Só o superperfil
 * mexe em quem tem o superperfil, e ninguém concede mais do que tem (Autorizacao::podeConceder).
 */
class Usuarios extends BaseController
{
    private const CHAVE       = 'usuarios.acesso';
    private const CHAVE_SENHA = 'usuarios.trocar_senha';

    private UsuarioModel $usuarios;
    private UsuarioPerfilModel $vinculos;
    private PerfilModel $perfis;
    private Autorizacao $autorizacao;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->usuarios    = new UsuarioModel();
        $this->vinculos    = new UsuarioPerfilModel();
        $this->perfis      = new PerfilModel();
        $this->autorizacao = service('autorizacao');
    }

    public function index(): string
    {
        $tabela = $this->tabela($this->tabelaUsuarios());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('institucional/usuarios/index', [
            'tabela'   => $tabela,
            'podeNovo' => $this->autorizacao->pode(self::CHAVE, Autorizacao::EDITOR),
            'podeSenha' => $this->autorizacao->pode(self::CHAVE_SENHA, Autorizacao::EDITOR),
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->tabelaUsuarios());
    }

    /** Alterar/excluir por linha seguem a mesma regra de alvoGerenciavel(). */
    private function tabelaUsuarios(): UsuariosTabela
    {
        return new UsuariosTabela(
            $this->autorizacao->escopoPara(self::CHAVE),
            fn (array $vinculos): bool => $this->motivoNegado($vinculos) === null,
            service('autenticacao')->id(),
            $this->autorizacao->pode(self::CHAVE, Autorizacao::EDITOR),
            $this->autorizacao->pode(self::CHAVE_SENHA, Autorizacao::EDITOR)
                ? fn (array $vinculos): bool => $this->motivoSenhaNegada($vinculos) === null
                : null
        );
    }

    public function novo(): string
    {
        return $this->formularioNovo();
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dados = $this->dadosDoFormulario();
        $erros = $this->validarUsuario($dados, null);

        $informouPerfil = (int) $this->request->getPost('perfil_id') > 0;

        if (! $informouPerfil && ! $this->autorizacao->escopoPara(self::CHAVE, Autorizacao::EDITOR)['global']) {
            $erros['perfil_id'] = lang('Usuarios.profileRequired');
        }

        if ($erros !== []) {
            return $this->formularioNovo($dados, $erros, 422);
        }

        // Cadastro excluído com o mesmo e-mail/CPF: oferece reativação em vez de duplicar a pessoa.
        foreach (['email', 'cpf'] as $campo) {
            $removido = $dados[$campo] === '' ? null : $this->usuarios->removidoPor($campo, $dados[$campo]);

            if ($removido !== null) {
                return $this->response->setStatusCode(409)->setBody(view('institucional/usuarios/reativar', [
                    'removido' => $removido,
                    'campo'    => $campo,
                    'vinculo'  => $this->vinculoPostado(),
                ]));
            }
        }

        $db = db_connect();
        $db->transBegin();

        $id = $this->usuarios->insert([
            'nome'             => $dados['nome'],
            'email'            => $dados['email'],
            'cpf'              => $dados['cpf'] === '' ? null : $dados['cpf'],
            'senha_hash'       => $dados['senha'] === '' ? null : password_hash($dados['senha'], PASSWORD_DEFAULT),
            'idioma_preferido' => $dados['idioma_preferido'],
            'ativo'            => 1,
        ]);

        $erroVinculo = $informouPerfil ? $this->conceder((int) $id, $this->vinculoPostado()) : null;

        if ($erroVinculo !== null) {
            $db->transRollback();

            return $this->formularioNovo($dados, ['perfil_id' => lang($erroVinculo)], 422);
        }

        $db->transCommit();

        return redirect()->to(site_url("usuarios/{$id}"))->with('sucesso', lang('Usuarios.saved'));
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        $alvo = $this->usuarios->find($id);

        if ($alvo === null) {
            return $this->voltarLista('erro', lang('Usuarios.notFound'));
        }

        $vinculos = $this->vinculos->doUsuario($id);

        if ($id !== service('autenticacao')->id() && ! $this->visivel($vinculos)) {
            return $this->voltarLista('erro', lang('Usuarios.outOfScope'));
        }

        return $this->paginaUsuario($alvo, $vinculos);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$alvo, $vinculos, $negado] = $this->alvoGerenciavel($id);

        if ($negado !== null) {
            return $negado;
        }

        $dados = $this->dadosDoFormulario();
        $dados['ativo'] = $this->request->getPost('ativo') !== null;
        $erros = $this->validarUsuario($dados, $id);

        if (! $dados['ativo'] && $alvo['ativo'] && ($mensagem = $this->impedeRemocao($id, $vinculos)) !== null) {
            $erros['ativo'] = $mensagem;
        }

        if ($erros !== []) {
            return $this->paginaUsuario($alvo, $vinculos, $erros, $dados, 422);
        }

        $novo = [
            'nome'             => $dados['nome'],
            'email'            => $dados['email'],
            'cpf'              => $dados['cpf'] === '' ? null : $dados['cpf'],
            'idioma_preferido' => $dados['idioma_preferido'],
            'ativo'            => $dados['ativo'] ? 1 : 0,
        ];

        if ($dados['senha'] !== '') {
            $novo['senha_hash'] = password_hash($dados['senha'], PASSWORD_DEFAULT);
        }

        $this->usuarios->update($id, $novo);

        return redirect()->to(site_url("usuarios/{$id}"))->with('sucesso', lang('Usuarios.saved'));
    }

    /**
     * Troca rápida de senha (modal da lista ou página). Permissão própria (usuarios.trocar_senha), com o mesmo
     * escopo dos demais atos sobre o usuário; a nova senha é provisória e o usuário a troca no próximo login.
     */
    public function senha(int $id): ResponseInterface|RedirectResponse|string
    {
        [$alvo, $negado] = $this->alvoDaTrocaDeSenha($id);

        return $negado ?? $this->telaSenha($alvo);
    }

    public function salvarSenha(int $id): ResponseInterface|RedirectResponse|string
    {
        [$alvo, $negado] = $this->alvoDaTrocaDeSenha($id);

        if ($negado !== null) {
            return $negado;
        }

        $senha = (string) $this->request->getPost('senha');
        $this->validator = service('validation')->reset()->setRules([
            'senha' => [
                'label' => lang('Usuarios.fieldNewPassword'),
                'rules' => 'required|min_length[8]|max_length[72]',
            ],
        ]);
        $this->validator->run(['senha' => $senha]);

        if ($this->validator->getErrors() !== []) {
            return $this->telaSenha($alvo, $this->validator->getErrors(), 422);
        }

        $this->usuarios->update($id, ['senha_hash' => password_hash($senha, PASSWORD_DEFAULT), 'trocar_senha' => 1]);

        $enviar = $this->request->getPost('enviar_email') === '1';
        $texto  = $this->textoDeAcesso($alvo, $senha);
        $email  = $enviar ? ($this->enviarSenha($alvo, $texto) ? 'enviado' : 'falhou') : null;

        $retorno = ['texto' => $texto, 'email' => $email];

        // A senha em claro vai só na resposta, nunca na sessão nem em redirecionamento.
        $this->response->setHeader('Cache-Control', 'no-store');

        if ($this->pedidoModal()) {
            return $this->comTokenCsrf($this->response->setJSON([
                'mensagem'  => lang('Usuarios.passwordChanged'),
                'resultado' => $retorno,
            ]));
        }

        return $this->response->setBody(view('institucional/usuarios/senha_resultado', $retorno));
    }

    /**
     * Alvo da troca de senha: ativo, dentro do escopo da permissão, nunca a própria conta.
     *
     * @return array{0: array<string, mixed>, 1: ?RedirectResponse}
     */
    private function alvoDaTrocaDeSenha(int $id): array
    {
        $alvo = $this->usuarios->find($id);

        if ($alvo === null) {
            return [[], $this->voltarLista('erro', lang('Usuarios.notFound'))];
        }

        if ($id === service('autenticacao')->id()) {
            return [[], $this->voltarLista('erro', lang('Usuarios.selfAction'))];
        }

        $negado = $this->motivoSenhaNegada($this->vinculos->doUsuario($id));

        return $negado === null ? [$alvo, null] : [[], $this->voltarLista('erro', $negado)];
    }

    /**
     * @param list<array<string, mixed>> $vinculos
     */
    private function motivoSenhaNegada(array $vinculos): ?string
    {
        foreach ($vinculos as $vinculo) {
            if ((bool) $vinculo['super_perfil'] && ! $this->autorizacao->ehSuper()) {
                return lang('Usuarios.superOnly');
            }
        }

        return $this->autorizacao->cobreVinculos(self::CHAVE_SENHA, Autorizacao::EDITOR, $vinculos)
            ? null
            : lang('Usuarios.outOfScope');
    }

    /**
     * @param array<string, mixed>  $alvo
     * @param array<string, string> $erros
     */
    private function telaSenha(array $alvo, array $erros = [], int $status = 200): ResponseInterface|string
    {
        return $this->telaFormulario('institucional/usuarios', ['usuario' => $alvo], $erros, $status, 'senha');
    }

    /**
     * Texto pronto para copiar, no idioma do destinatário.
     *
     * @param array<string, mixed> $alvo
     */
    private function textoDeAcesso(array $alvo, string $senha): string
    {
        $argumentos = [site_url('entrar'), (string) $alvo['email'], $senha];

        return lang('Usuarios.accessText', $argumentos, $alvo['idioma_preferido']);
    }

    /** @param array<string, mixed> $alvo */
    private function enviarSenha(array $alvo, string $texto): bool
    {
        $email     = service('email');
        $host      = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
        $remetente = config(\Config\Email::class)->fromEmail ?: 'nao-responda@' . $host;
        $idioma    = $alvo['idioma_preferido'];

        $email->setFrom($remetente, lang('App.name'));
        $email->setTo((string) $alvo['email']);
        $email->setSubject(lang('Usuarios.passwordMailSubject', [], $idioma));
        $email->setMessage(lang('Usuarios.passwordMailBody', [$texto], $idioma));

        if ($email->send(false)) {
            return true;
        }

        log_message('error', 'Falha ao enviar a nova senha por e-mail (usuário {id}).', ['id' => $alvo['id_usuario']]);

        return false;
    }

    public function atribuirPerfil(int $id): ResponseInterface|RedirectResponse|string
    {
        [$alvo, $vinculos, $negado] = $this->alvoGerenciavel($id);

        if ($negado !== null) {
            return $negado;
        }

        $erro = $this->conceder($id, $this->vinculoPostado());

        if ($erro !== null) {
            return $this->paginaUsuario($alvo, $vinculos, ['perfil_id' => lang($erro)], null, 422);
        }

        return redirect()->to(site_url("usuarios/{$id}"))->with('sucesso', lang('Usuarios.profileAdded'));
    }

    public function removerPerfil(int $id, int $vinculoId): RedirectResponse
    {
        [, $vinculos, $negado] = $this->alvoGerenciavel($id);

        if ($negado !== null) {
            return $negado;
        }

        $vinculo = null;
        foreach ($vinculos as $candidato) {
            if ((int) $candidato['id_usuario_perfil'] === $vinculoId) {
                $vinculo = $candidato;
            }
        }

        if ($vinculo === null) {
            return $this->voltarUsuario($id, 'erro', lang('Usuarios.notFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return $this->voltarUsuario($id, 'erro', lang('Ui.confirmMismatch'));
        }

        if ($this->ehSuperGlobal($vinculo) && $this->usuarios->contarSupersAtivos($id) === 0) {
            return $this->voltarUsuario($id, 'erro', lang('Usuarios.lastAdmin'));
        }

        $this->vinculos->delete($vinculoId);

        return $this->voltarUsuario($id, 'sucesso', lang('Usuarios.profileRemoved'));
    }

    public function excluir(int $id): RedirectResponse
    {
        [, $vinculos, $negado] = $this->alvoGerenciavel($id);

        if ($negado !== null) {
            return $negado;
        }

        if (! $this->exclusaoConfirmada()) {
            return $this->voltarUsuario($id, 'erro', lang('Ui.confirmMismatch'));
        }

        if (($mensagem = $this->impedeRemocao($id, $vinculos)) !== null) {
            return $this->voltarUsuario($id, 'erro', $mensagem);
        }

        $db = db_connect();
        $db->transStart();
        $this->vinculos->removerDoUsuario($id);
        $this->usuarios->delete($id);
        $db->transComplete();

        return $this->voltarLista('sucesso', lang('Usuarios.deleted'));
    }

    /** Anonimização LGPD: irreversível, com o mesmo padrão de confirmação de toda exclusão. */
    public function anonimizar(int $id): RedirectResponse
    {
        [, $vinculos, $negado] = $this->alvoGerenciavel($id);

        if ($negado !== null) {
            return $negado;
        }

        if (! $this->exclusaoConfirmada()) {
            return $this->voltarUsuario($id, 'erro', lang('Ui.confirmMismatch'));
        }

        if (($mensagem = $this->impedeRemocao($id, $vinculos)) !== null) {
            return $this->voltarUsuario($id, 'erro', $mensagem);
        }

        (new AnonimizadorUsuario())->anonimizar($id);

        return $this->voltarLista('sucesso', lang('Usuarios.anonymizedOk'));
    }

    /**
     * Reativa um cadastro excluído (não anonimizado). Os perfis antigos não voltam e a senha anterior
     * é descartada: quem reativa define uma nova ou o usuário usa "Esqueci minha senha"/Google.
     */
    public function reativar(int $id): ResponseInterface|RedirectResponse|string
    {
        $removido = $this->usuarios->incluindoRemovido($id);

        if ($removido === null || $removido['deletado_em'] === null || $removido['anonimizado_em'] !== null) {
            return $this->voltarLista('erro', lang('Usuarios.notFound'));
        }

        $vinculo        = $this->vinculoPostado();
        $informouPerfil = (int) $vinculo['perfil_id'] > 0;
        $global         = $this->autorizacao->escopoPara(self::CHAVE, Autorizacao::EDITOR)['global'];

        if (! $informouPerfil && ! $global) {
            return $this->voltarLista('erro', lang('Usuarios.outOfScope'));
        }

        foreach (['email', 'cpf'] as $campo) {
            if ($removido[$campo] !== null && $this->usuarios->existeAtivo($campo, (string) $removido[$campo])) {
                return $this->voltarLista('erro', lang('Usuarios.reactivateConflict'));
            }
        }

        $db = db_connect();
        $db->transBegin();

        $this->usuarios->withDeleted()->update($id, ['deletado_em' => null, 'ativo' => 1, 'senha_hash' => null]);

        $erro = $informouPerfil ? $this->conceder($id, $vinculo) : null;

        if ($erro !== null) {
            $db->transRollback();

            return $this->voltarLista('erro', lang($erro));
        }

        $db->transCommit();

        return redirect()->to(site_url("usuarios/{$id}"))->with('sucesso', lang('Usuarios.reactivated'));
    }

    /**
     * Carrega o usuário-alvo e confere se quem age pode administrá-lo.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: ?RedirectResponse}
     */
    private function alvoGerenciavel(int $id): array
    {
        $alvo = $this->usuarios->find($id);

        if ($alvo === null) {
            return [[], [], $this->voltarLista('erro', lang('Usuarios.notFound'))];
        }

        $vinculos = $this->vinculos->doUsuario($id);
        $negado   = $this->motivoNegado($vinculos);

        return [$alvo, $vinculos, $negado === null ? null : $this->voltarLista('erro', $negado)];
    }

    /**
     * @param list<array<string, mixed>> $vinculos
     */
    private function motivoNegado(array $vinculos): ?string
    {
        foreach ($vinculos as $vinculo) {
            if ((bool) $vinculo['super_perfil'] && ! $this->autorizacao->ehSuper()) {
                return lang('Usuarios.superOnly');
            }
        }

        return $this->autorizacao->cobreVinculos(self::CHAVE, Autorizacao::EDITOR, $vinculos)
            ? null
            : lang('Usuarios.outOfScope');
    }

    /**
     * Ao menos um vínculo dentro do escopo de leitura de quem consulta.
     *
     * @param list<array<string, mixed>> $vinculos
     */
    private function visivel(array $vinculos): bool
    {
        if ($this->autorizacao->escopoPara(self::CHAVE)['global']) {
            return true;
        }

        foreach ($vinculos as $vinculo) {
            if ($this->autorizacao->cobreVinculos(self::CHAVE, Autorizacao::LEITOR, [$vinculo])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>       $vinculo
     */
    private function ehSuperGlobal(array $vinculo): bool
    {
        return (bool) $vinculo['super_perfil'] && $vinculo['escopo_tipo'] === 'global';
    }

    /**
     * Excluir/anonimizar: nunca a própria conta nem o último administrador geral.
     *
     * @param list<array<string, mixed>> $vinculos
     */
    private function impedeRemocao(int $id, array $vinculos): ?string
    {
        if ($id === service('autenticacao')->id()) {
            return lang('Usuarios.selfAction');
        }

        return $this->ultimoSuper($id, $vinculos) ? lang('Usuarios.lastAdmin') : null;
    }

    /**
     * @param list<array<string, mixed>> $vinculos
     */
    private function ultimoSuper(int $id, array $vinculos): bool
    {
        foreach ($vinculos as $vinculo) {
            if ($this->ehSuperGlobal($vinculo)) {
                return $this->usuarios->contarSupersAtivos($id) === 0;
            }
        }

        return false;
    }

    /**
     * Cria o vínculo perfil+escopo, se quem concede pode. Devolve a chave de idioma do erro, ou null.
     *
     * @param array{perfil_id: int, escopo_tipo: string, campus_id: int, predio_id: int} $vinculo
     */
    private function conceder(int $usuarioId, array $vinculo): ?string
    {
        $perfil = $this->perfis->find($vinculo['perfil_id']);
        $tipo   = $vinculo['escopo_tipo'];

        if ($perfil === null || ! in_array($tipo, ['global', 'campus', 'predio'], true)) {
            return 'Usuarios.profileDenied';
        }

        $opcoes   = $this->opcoesDeEscopo();
        $campusId = $tipo === 'campus' ? $vinculo['campus_id'] : null;
        $predioId = $tipo === 'predio' ? $vinculo['predio_id'] : null;

        if (
            ($tipo === 'campus' && ! $opcoes['campus']->contem((int) $campusId))
            || ($tipo === 'predio' && ! $opcoes['predios']->contem((int) $predioId))
        ) {
            return 'Usuarios.scopeInvalid';
        }

        if ((bool) $perfil['super_perfil'] && $tipo !== 'global') {
            return 'Usuarios.superScope';
        }

        $permissoes = $this->perfis->permissoes((int) $perfil['id_perfil']);
        $super      = (bool) $perfil['super_perfil'];

        if (! $this->autorizacao->podeConceder($permissoes, $super, $tipo, $campusId, $predioId)) {
            return 'Usuarios.profileDenied';
        }

        if ($this->vinculos->existeVinculo($usuarioId, (int) $perfil['id_perfil'], $tipo, $campusId, $predioId)) {
            return 'Usuarios.profileExists';
        }

        $this->vinculos->insert([
            'usuario_id'  => $usuarioId,
            'perfil_id'   => (int) $perfil['id_perfil'],
            'escopo_tipo' => $tipo,
            'campus_id'   => $campusId,
            'predio_id'   => $predioId,
        ]);

        return null;
    }

    /**
     * Campus e prédios que quem administra pode usar como escopo (todos, se o escopo dele é global): as mesmas
     * fontes dos selects do vínculo.
     *
     * @return array{global: bool, campus: Fonte, predios: Fonte}
     */
    private function opcoesDeEscopo(): array
    {
        $escopo = $this->autorizacao->escopoPara(self::CHAVE, Autorizacao::EDITOR);

        return [
            'global'  => $escopo['global'],
            'campus'  => service('opcoes')->campi($escopo),
            'predios' => service('opcoes')->predios($escopo),
        ];
    }

    /**
     * Que tipos de escopo o formulário de vínculo oferece.
     *
     * @return array{global: bool, campus: bool, predios: bool}
     */
    private function tiposDeEscopo(): array
    {
        $opcoes = $this->opcoesDeEscopo();

        return [
            'global'  => $opcoes['global'],
            'campus'  => $opcoes['campus']->existe(),
            'predios' => $opcoes['predios']->existe(),
        ];
    }

    /**
     * @return array{perfil_id: int, escopo_tipo: string, campus_id: int, predio_id: int}
     */
    private function vinculoPostado(): array
    {
        return [
            'perfil_id'   => (int) $this->request->getPost('perfil_id'),
            'escopo_tipo' => (string) $this->request->getPost('escopo_tipo'),
            'campus_id'   => (int) $this->request->getPost('campus_id'),
            'predio_id'   => (int) $this->request->getPost('predio_id'),
        ];
    }

    /**
     * @return array{nome: string, email: string, cpf: string, senha: string, idioma_preferido: string, ativo: bool}
     */
    private function dadosDoFormulario(): array
    {
        return [
            'nome'             => trim((string) $this->request->getPost('nome')),
            'email'            => strtolower(trim((string) $this->request->getPost('email'))),
            'cpf'              => Cpf::normalizar((string) $this->request->getPost('cpf')),
            'senha'            => (string) $this->request->getPost('senha'),
            'idioma_preferido' => (string) $this->request->getPost('idioma_preferido'),
            'ativo'            => true,
        ];
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, string> campo => mensagem
     */
    private function validarUsuario(array $dados, ?int $id): array
    {
        $idiomas = implode(',', config(App::class)->supportedLocales);

        $this->validator = service('validation')->reset()->setRules([
            'nome' => [
                'label' => lang('Usuarios.fieldName'),
                'rules' => 'required|max_length[255]',
            ],
            'email' => [
                'label' => lang('Usuarios.fieldEmail'),
                'rules' => 'required|valid_email|max_length[255]',
            ],
            'idioma_preferido' => [
                'label' => lang('Usuarios.fieldLanguage'),
                'rules' => "required|in_list[{$idiomas}]",
            ],
            'senha' => [
                'label' => lang('Usuarios.fieldPassword'),
                'rules' => 'permit_empty|min_length[8]|max_length[72]',
            ],
        ]);
        $this->validator->run($dados);

        $erros = $this->validator->getErrors();

        if (! isset($erros['email']) && $this->usuarios->existeAtivo('email', $dados['email'], $id)) {
            $erros['email'] = lang('Usuarios.emailInUse');
        }

        if ($dados['cpf'] !== '') {
            if (! Cpf::valido($dados['cpf'])) {
                $erros['cpf'] = lang('Usuarios.cpfInvalid');
            } elseif ($this->usuarios->existeAtivo('cpf', $dados['cpf'], $id)) {
                $erros['cpf'] = lang('Usuarios.cpfInUse');
            }
        }

        return $erros;
    }

    /**
     * @param array<string, mixed>|null $dados
     * @param array<string, string>     $erros
     */
    private function formularioNovo(
        ?array $dados = null,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        $padrao = [
            'nome'             => '',
            'email'            => '',
            'cpf'              => '',
            'idioma_preferido' => config(App::class)->defaultLocale,
        ];

        $html = view('institucional/usuarios/novo', [
            'dados'   => $dados ?? $padrao,
            'erros'   => $erros,
            'escopos' => $this->tiposDeEscopo(),
            'vinculo' => $this->vinculoPostado(),
        ]);

        return $status === 200 ? $html : $this->response->setStatusCode($status)->setBody($html);
    }

    /**
     * @param array<string, mixed>       $alvo
     * @param list<array<string, mixed>> $vinculos
     * @param array<string, string>      $erros
     * @param array<string, mixed>|null  $dados
     */
    private function paginaUsuario(
        array $alvo,
        array $vinculos,
        array $erros = [],
        ?array $dados = null,
        int $status = 200
    ): ResponseInterface|string {
        $id       = (int) $alvo['id_usuario'];
        $podeEdit = $this->autorizacao->pode(self::CHAVE, Autorizacao::EDITOR)
            && $this->motivoNegado($vinculos) === null;

        $html = view('institucional/usuarios/ver', [
            'usuario'    => $alvo,
            'dados'      => $dados ?? $alvo,
            'vinculos'   => $vinculos,
            'erros'      => $erros,
            'podeEditar' => $podeEdit,
            'proprio'    => $id === service('autenticacao')->id(),
            'ultimoSuper' => $this->ultimoSuper($id, $vinculos),
            'escopos'    => $this->tiposDeEscopo(),
            'vinculo'    => $this->vinculoPostado(),
        ]);

        return $status === 200 ? $html : $this->response->setStatusCode($status)->setBody($html);
    }

    private function voltarLista(string $tipo, string $mensagem): RedirectResponse
    {
        return redirect()->to(site_url('usuarios'))->with($tipo, $mensagem);
    }

    private function voltarUsuario(int $id, string $tipo, string $mensagem): RedirectResponse
    {
        return redirect()->to(site_url("usuarios/{$id}"))->with($tipo, $mensagem);
    }
}
