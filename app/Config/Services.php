<?php

namespace Config;

use App\Libraries\Arquivos;
use App\Libraries\Autenticacao;
use App\Libraries\Autorizacao;
use App\Libraries\Opcoes\Registro as RegistroOpcoes;
use App\Models\Institucional\UsuarioModel;
use CodeIgniter\Config\BaseService;
use League\OAuth2\Client\Provider\Google as GoogleProvider;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /** Usuário da sessão atual (recarregado do banco a cada requisição). */
    public static function autenticacao(bool $getShared = true): Autenticacao
    {
        if ($getShared) {
            /** @var Autenticacao */
            return static::getSharedInstance('autenticacao');
        }

        return new Autenticacao(static fn () => static::session(), new UsuarioModel());
    }

    /** Permissões e escopos do usuário logado (vazio se ninguém logado). */
    public static function autorizacao(bool $getShared = true): Autorizacao
    {
        if ($getShared) {
            /** @var Autorizacao */
            return static::getSharedInstance('autorizacao');
        }

        return new Autorizacao(static::autenticacao()->id(), db_connect());
    }

    /**
     * Fontes de opções dos selects do banco (App\Libraries\Opcoes). Nunca compartilhado: depende do usuário
     * logado, e os testes trocam de usuário entre requisições.
     */
    public static function opcoes(bool $getShared = false): RegistroOpcoes
    {
        unset($getShared);

        return new RegistroOpcoes(
            db_connect(),
            static::autorizacao(),
            static::autenticacao()->logado(),
            (int) (static::autenticacao()->usuario()['id_usuario'] ?? 0)
        );
    }

    /** Arquivos enviados nos cadastros (logo, foto, mapa), gravados em public/uploads. */
    public static function arquivos(bool $getShared = true): Arquivos
    {
        if ($getShared) {
            /** @var Arquivos */
            return static::getSharedInstance('arquivos');
        }

        return new Arquivos(FCPATH . 'uploads');
    }

    /** Provider OAuth2 do Google; nos testes é substituído via Services::injectMock('googleProvider', ...). */
    public static function googleProvider(bool $getShared = true): GoogleProvider
    {
        if ($getShared) {
            /** @var GoogleProvider */
            return static::getSharedInstance('googleProvider');
        }

        $config = config(Google::class);

        return new GoogleProvider([
            'clientId'     => $config->clientId,
            'clientSecret' => $config->clientSecret,
            'redirectUri'  => $config->redirectUri(),
            'pkceMethod'   => GoogleProvider::PKCE_METHOD_S256,
        ]);
    }
}
