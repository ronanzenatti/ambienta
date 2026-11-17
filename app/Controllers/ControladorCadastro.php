<?php

namespace App\Controllers;

use App\Filters\PermissaoFilter;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base dos cadastros (institucional, patrimônio...): leitura dos campos postados, validação com rótulo
 * traduzido, arquivos enviados, mensagem de exclusão bloqueada por dependentes e resposta com status.
 * As regras de escopo ficam em cada módulo.
 */
abstract class ControladorCadastro extends BaseController
{
    protected function negar(): ResponseInterface
    {
        return PermissaoFilter::negar();
    }

    /** Texto postado sem espaços nas pontas; vazio vira null (colunas NULL do schema). */
    protected function textoOuNulo(string $campo): ?string
    {
        $valor = trim((string) $this->request->getPost($campo));

        return $valor === '' ? null : $valor;
    }

    protected function texto(string $campo): string
    {
        return trim((string) $this->request->getPost($campo));
    }

    /**
     * Roda as regras de validação do CodeIgniter sobre os dados.
     *
     * @param array<string, mixed>                                $dados
     * @param array<string, array{label: string, rules: string}> $regras
     *
     * @return array<string, string>
     */
    protected function validar(array $dados, array $regras): array
    {
        $validador = service('validation')->reset()->setRules($regras);

        return $validador->run($dados) ? [] : $validador->getErrors();
    }

    /**
     * Regra de validação com o rótulo traduzido.
     *
     * @return array{label: string, rules: string}
     */
    protected function regra(string $rotulo, string $regras): array
    {
        $texto = lang($rotulo);

        return ['label' => is_string($texto) ? $texto : $rotulo, 'rules' => $regras];
    }

    /**
     * Processa os campos de arquivo do formulário. Chame só depois de os demais campos validarem, para
     * não deixar arquivo órfão em disco; se algum envio falhar, os gravados nesta tentativa são descartados.
     *
     * @param array<string, string> $campos campo => uso (Arquivos::IMAGEM ou Arquivos::MAPA)
     * @param array<string, mixed>  $atual  registro atual (vazio ao criar)
     * @param array<string, string> $erros  recebe a mensagem de erro de cada campo que falhar
     *
     * @return array{0: array<string, ?string>, 1: list<?string>} [valores a gravar, arquivos antigos a apagar
     *                                                          depois de salvar]
     */
    protected function arquivosPostados(array $campos, string $pasta, array $atual, array &$erros): array
    {
        $valores = [];
        $atuais  = [];
        $antigos = [];

        foreach ($campos as $campo => $uso) {
            $atuais[$campo] = isset($atual[$campo]) ? (string) $atual[$campo] : null;
            [$valores[$campo], $antigos[]] = $this->arquivoPostado($campo, $pasta, $uso, $atuais[$campo], $erros);
        }

        if ($erros === []) {
            return [$valores, $antigos];
        }

        foreach ($valores as $campo => $caminho) {
            if ($caminho !== $atuais[$campo]) {
                service('arquivos')->remover($caminho);
            }
        }

        return [$atuais, []];
    }

    /**
     * Um campo de arquivo: novo envio substitui o atual; a caixa "remover_<campo>" limpa.
     *
     * @param array<string, string> $erros
     *
     * @return array{0: ?string, 1: ?string} [valor a gravar, arquivo antigo a apagar depois de salvar]
     */
    private function arquivoPostado(string $campo, string $pasta, string $uso, ?string $atual, array &$erros): array
    {
        $resultado = service('arquivos')->guardar($this->request->getFile($campo), $pasta, $uso);

        if ($resultado === null) {
            return $this->request->getPost('remover_' . $campo) === '1' ? [null, $atual] : [$atual, null];
        }

        if (isset($resultado['erro'])) {
            $mensagem      = lang($resultado['erro']);
            $erros[$campo] = is_string($mensagem) ? $mensagem : implode(' ', $mensagem);

            return [$atual, null];
        }

        return [$resultado['caminho'], $atual];
    }

    /** @param list<?string> $caminhos */
    protected function apagarArquivos(array $caminhos): void
    {
        foreach ($caminhos as $caminho) {
            service('arquivos')->remover($caminho);
        }
    }

    /**
     * Mensagem de exclusão recusada, com o que ainda depende do registro.
     *
     * @param array<string, int> $dependentes tabela => quantidade
     */
    protected function mensagemDependentes(array $dependentes): string
    {
        $partes = [];

        foreach ($dependentes as $tabela => $total) {
            $partes[] = $total . ' ' . lang('Institucional.dep_' . $tabela);
        }

        return lang('Institucional.hasDependents', [implode(', ', $partes)]);
    }

    /** @param array<string, string> $erros */
    protected function responder(
        string $view,
        array $dados,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        $html = view($view, $dados + ['erros' => $erros]);

        return $status === 200 ? $html : $this->response->setStatusCode($status)->setBody($html);
    }
}
