<?php

namespace App\Controllers\Institucional;

use App\Controllers\BaseController;
use App\Libraries\Autorizacao;
use App\Libraries\CatalogoPermissoes;
use App\Models\Institucional\PerfilModel;
use App\Tabelas\Institucional\PerfisTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Perfis e matriz de permissões (leitor/editor por módulo + permissões específicas).
 * Área exclusiva do superperfil (filtro `superperfil` nas rotas). O superperfil em si é fixo.
 */
class Perfis extends BaseController
{
    private PerfilModel $perfis;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->perfis = new PerfilModel();
    }

    public function index(): string
    {
        $tabela = $this->tabela(new PerfisTabela());

        if ($this->pedidoParcial()) {
            return $tabela->fragmento();
        }

        return view('institucional/perfis/index', ['tabela' => $tabela]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new PerfisTabela());
    }

    public function novo(): string
    {
        return $this->formulario(['nome' => '', 'super_perfil' => 0], []);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $nome  = trim((string) $this->request->getPost('nome'));
        $erros = $this->validarNome($nome, null);

        if ($erros !== []) {
            $perfil = ['nome' => $nome, 'super_perfil' => 0];

            return $this->formulario($perfil, $this->matrizPostada(), $erros, 422);
        }

        $id = $this->perfis->insert(['nome' => $nome, 'super_perfil' => 0]);
        $this->perfis->sincronizarPermissoes((int) $id, $this->matrizPostada());

        return redirect()->to(site_url('perfis'))->with('sucesso', lang('Perfis.saved'));
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        $perfil = $this->perfis->find($id);

        if ($perfil === null) {
            return redirect()->to(site_url('perfis'))->with('erro', lang('Perfis.notFound'));
        }

        return $this->formulario($perfil, $this->perfis->permissoes($id));
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        $perfil = $this->perfis->find($id);

        if ($perfil === null || (bool) $perfil['super_perfil']) {
            $motivo = $perfil === null ? 'Perfis.notFound' : 'Perfis.superLocked';

            return redirect()->to(site_url('perfis'))->with('erro', lang($motivo));
        }

        $nome  = trim((string) $this->request->getPost('nome'));
        $erros = $this->validarNome($nome, $id);

        if ($erros !== []) {
            $perfil = ['id_perfil' => $id, 'nome' => $nome, 'super_perfil' => 0];

            return $this->formulario($perfil, $this->matrizPostada(), $erros, 422);
        }

        $this->perfis->update($id, ['nome' => $nome]);
        $this->perfis->sincronizarPermissoes($id, $this->matrizPostada());

        return redirect()->to(site_url('perfis'))->with('sucesso', lang('Perfis.saved'));
    }

    public function excluir(int $id): RedirectResponse
    {
        $perfil = $this->perfis->find($id);

        if ($perfil === null) {
            return redirect()->to(site_url('perfis'))->with('erro', lang('Perfis.notFound'));
        }

        if ((bool) $perfil['super_perfil']) {
            return redirect()->to(site_url('perfis'))->with('erro', lang('Perfis.superLocked'));
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url('perfis'))->with('erro', lang('Ui.confirmMismatch'));
        }

        $emUso = $this->perfis->contarVinculos($id);

        if ($emUso > 0) {
            return redirect()->to(site_url('perfis'))->with('erro', lang('Perfis.inUse', [$emUso]));
        }

        $this->perfis->sincronizarPermissoes($id, []);
        $this->perfis->delete($id);

        return redirect()->to(site_url('perfis'))->with('sucesso', lang('Perfis.deleted'));
    }

    /**
     * @return array<string, string>
     */
    private function validarNome(string $nome, ?int $id): array
    {
        $validador = service('validation')->reset()->setRules([
            'nome' => ['label' => lang('Perfis.fieldName'), 'rules' => 'required|max_length[100]'],
        ]);

        if (! $validador->run(['nome' => $nome])) {
            return $validador->getErrors();
        }

        return $this->perfis->existeNome($nome, $id) ? ['nome' => lang('Perfis.nameInUse')] : [];
    }

    /**
     * Matriz enviada pelo formulário, só com chaves do catálogo: acesso[<chave>] = leitor|editor
     * para as permissões com nível e especifica[] = <chave> para as permissões específicas (sempre 'editor').
     *
     * @return array<string, string> chave => leitor|editor
     */
    private function matrizPostada(): array
    {
        $acesso     = (array) $this->request->getPost('acesso');
        $especifica = (array) $this->request->getPost('especifica');
        $matriz     = [];

        foreach ($this->perfis->catalogoPorModulo() as $permissoes) {
            foreach ($permissoes as $permissao) {
                $chave = (string) $permissao['chave'];

                if (CatalogoPermissoes::temNivel($chave)) {
                    $nivel = $acesso[$chave] ?? '';

                    if (in_array($nivel, [Autorizacao::LEITOR, Autorizacao::EDITOR], true)) {
                        $matriz[$chave] = $nivel;
                    }
                } elseif (in_array($chave, $especifica, true)) {
                    $matriz[$chave] = Autorizacao::EDITOR;
                }
            }
        }

        return $matriz;
    }

    /**
     * @param array<string, mixed>  $perfil
     * @param array<string, string> $matriz
     * @param array<string, string> $erros
     */
    private function formulario(
        array $perfil,
        array $matriz,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        $html = view('institucional/perfis/form', [
            'perfil'   => $perfil,
            'matriz'   => $matriz,
            'erros'    => $erros,
            'catalogo' => $this->perfis->catalogoPorModulo(),
        ]);

        return $status === 200 ? $html : $this->response->setStatusCode($status)->setBody($html);
    }
}
