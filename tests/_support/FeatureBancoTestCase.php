<?php

namespace Tests\Support;

use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * Base dos testes de requisição (rotas, filtros, controllers e views) com o banco de testes.
 *
 * O CSRF fica ligado, como em produção: post() e excluir() enviam o token da sessão de teste;
 * postSemToken() envia sem ele, para provar que a proteção barra.
 */
abstract class FeatureBancoTestCase extends BancoTestCase
{
    use FeatureTestTrait {
        post as private postSemCsrf;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reiniciarSessaoDoUsuario();
        // O Throttler guarda o cache com que nasceu: sem isto, o contador de um teste vaza para o próximo.
        Services::resetSingle('throttler');
    }

    /**
     * @param array<string, mixed>|null $params
     */
    public function post(string $path, ?array $params = null): TestResponse
    {
        return $this->postSemCsrf($path, $this->comToken($params));
    }

    /**
     * O router é um serviço compartilhado e o FeatureTestTrait fixa o verbo HTTP na coleção de rotas ANTES
     * de a requisição ser "spoofada" (POST + _method=DELETE); em produção o router nasce depois do spoof.
     * Recriá-lo a cada requisição reproduz esse comportamento.
     */
    protected function reiniciarRouter(): void
    {
        Services::resetSingle('router');
    }

    /**
     * Exclusão como o navegador faz: POST com _method=DELETE e o campo de confirmação.
     *
     * @param array<string, mixed> $extra
     */
    protected function excluir(string $path, string $confirmacao = 'CONFIRMO', array $extra = []): TestResponse
    {
        return $this->post($path, ['_method' => 'DELETE', 'confirmacao' => $confirmacao] + $extra);
    }

    /**
     * @param array<string, mixed>|null $params
     */
    protected function postSemToken(string $path, ?array $params = null): TestResponse
    {
        return $this->postSemCsrf($path, $params);
    }

    /**
     * @param array<string, mixed>|null $params
     *
     * @return array<string, mixed>
     */
    private function comToken(?array $params): array
    {
        return ($params ?? []) + [csrf_token() => csrf_hash()];
    }

    /**
     * Define quem está logado nas próximas requisições (null = visitante anônimo).
     *
     * @param array<string, mixed>|null $usuario
     * @param array<string, mixed>      $extra   outras chaves de sessão
     */
    protected function como(?array $usuario, array $extra = []): static
    {
        $this->reiniciarSessaoDoUsuario();

        $sessao = $extra;
        if ($usuario !== null) {
            $sessao['id_usuario'] = (int) $usuario['id_usuario'];
        }

        return $this->withSession($sessao);
    }

    /** Autenticação e autorização são serviços compartilhados: descarta o que ficou da requisição anterior. */
    protected function reiniciarSessaoDoUsuario(): void
    {
        Services::resetSingle('autenticacao');
        Services::resetSingle('autorizacao');
        $this->reiniciarRouter();
    }
}
