<?php

namespace App\Controllers;

use App\Libraries\Tabela\Abrangencia;
use App\Libraries\Tabela\Exportador;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * Extend this class in any new controllers:
 * ```
 *     class Home extends BaseController
 * ```
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */

    // protected $session;

    /**
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Load here all helpers you want to be available in your controllers that extend BaseController.
        // Caution: Do not put the this below the parent::initController() call below.
        // $this->helpers = ['form', 'url'];

        // Caution: Do not edit this line.
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.
        // $this->session = service('session');
    }

    /**
     * Toda exclusão do sistema exige a palavra CONFIRMO (o botão do modal só habilita ao digitá-la,
     * mas o servidor revalida: o cliente nunca é a única barreira).
     */
    protected function exclusaoConfirmada(): bool
    {
        return trim((string) $this->request->getPost('confirmacao')) === 'CONFIRMO';
    }

    /**
     * Prepara uma listagem com o estado da URL (busca, ordem, página, registros por página).
     *
     * @template T of Tabela
     *
     * @param T $tabela
     *
     * @return T
     */
    protected function tabela(Tabela $tabela): Tabela
    {
        return $tabela->comRequisicao($this->request);
    }

    /** Carregamento sob demanda da listagem: devolver só $tabela->fragmento(), sem o layout. */
    protected function pedidoParcial(): bool
    {
        return $this->request->getGet('parcial') === '1';
    }

    /**
     * Formulário aberto no modal da listagem (?modal=1, ver components/tabela/formulario_modal): as respostas
     * vão sem layout (o formulário) ou em JSON (salvo), em vez de página inteira e redirecionamento.
     */
    protected function pedidoModal(): bool
    {
        return $this->request->getGet('modal') === '1';
    }

    /**
     * Formulário curto de cadastro/alteração: a página "<pasta>/form" ou, no modal, só "<pasta>/_form".
     * Com erro de validação, devolva $status 422: o modal continua aberto mostrando os erros.
     *
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    protected function telaFormulario(
        string $pasta,
        array $dados,
        array $erros = [],
        int $status = 200,
        string $nome = 'form'
    ): ResponseInterface|string {
        $modal = $this->pedidoModal();
        $html  = view($pasta . ($modal ? '/_' : '/') . $nome, $dados + ['erros' => $erros, 'modal' => $modal]);

        if (! $modal && $status === 200) {
            return $html;
        }

        return $this->comTokenCsrf($this->response->setStatusCode($status)->setBody($html));
    }

    /**
     * Depois de salvar: no modal, JSON com a mensagem (o modal fecha e a tabela recarrega); fora dele,
     * redirecionamento com a mensagem na sessão, como sempre. Com $abrirDestino, o modal também leva a pessoa
     * ao destino (ex.: o registro recém-criado, para completar o cadastro), com a mensagem na sessão.
     */
    protected function salvo(string $destino, string $mensagem, bool $abrirDestino = false): ResponseInterface
    {
        if (! $this->pedidoModal()) {
            return redirect()->to($destino)->with('sucesso', $mensagem);
        }

        if ($abrirDestino) {
            session()->setFlashdata('sucesso', $mensagem);
        }

        return $this->comTokenCsrf($this->response->setJSON(
            ['mensagem' => $mensagem] + ($abrirDestino ? ['redirecionar' => $destino] : [])
        ));
    }

    /**
     * O token CSRF é regenerado a cada POST (Config\Security::$regenerate): no modal a página não recarrega,
     * então o novo token vai no cabeçalho para o JS atualizar os demais formulários da tela.
     */
    protected function comTokenCsrf(ResponseInterface $resposta): ResponseInterface
    {
        return $resposta->setHeader('X-Csrf-Name', csrf_token())->setHeader('X-Csrf-Hash', csrf_hash());
    }

    /**
     * Rota de exportação de uma listagem: ?formato=xlsx|pdf&abrangencia=pagina|pesquisa|tudo, mais o estado
     * da tabela. PDF acima do limite volta para a lista com o aviso (o Excel não tem limite).
     */
    protected function exportarTabela(Tabela $tabela): ResponseInterface|RedirectResponse
    {
        $tabela      = $this->tabela($tabela);
        $abrangencia = Abrangencia::tryFrom((string) $this->request->getGet('abrangencia')) ?? Abrangencia::Pagina;
        $exportador  = new Exportador($tabela);

        if ($this->request->getGet('formato') !== 'pdf') {
            return $exportador->excel($abrangencia);
        }

        return $exportador->pdf($abrangencia)
            ?? redirect()->to($tabela->urlLista() . $tabela->parametros()->query($tabela))->with('erro', lang(
                'Tabela.pdfTooMany',
                [Exportador::LIMITE_PDF, $tabela->contarExportacao($abrangencia)]
            ));
    }

    /**
     * Destino de volta seguro: só caminhos do próprio site (evita redirecionamento aberto).
     */
    protected function destinoLocal(?string $destino, string $padrao = '/'): string
    {
        if ($destino !== null && preg_match('#^/(?![/\\\\])#', $destino) === 1) {
            return $destino;
        }

        return $padrao;
    }
}
