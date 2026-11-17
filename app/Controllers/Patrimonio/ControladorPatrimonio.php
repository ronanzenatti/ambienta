<?php

namespace App\Controllers\Patrimonio;

use App\Controllers\ControladorCadastro;
use App\Libraries\Autorizacao;
use App\Libraries\Patrimonio\Locais;
use App\Models\Institucional\UsuarioModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Base do módulo de patrimônio (permissão patrimonio.acesso; ver Config/Routes.php).
 *
 * Regras de escopo:
 * - catálogos globais (categorias de item, modelos, softwares): editor em qualquer escopo;
 * - almoxarifado: editor no campus;
 * - item e estoque: pelo local onde estão (almoxarifado → campus; ambiente → prédio e campus). Quem tem só
 *   escopo de prédio não enxerga almoxarifados;
 * - confirmar recebimento e aprovar descarte: as permissões específicas, no local de destino / do item.
 *
 * @phpstan-import-type Local from Locais
 */
abstract class ControladorPatrimonio extends ControladorCadastro
{
    protected const CHAVE = 'patrimonio.acesso';

    protected Autorizacao $autorizacao;
    protected Locais $locais;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        helper('institucional');
        $this->autorizacao = service('autorizacao');
        $this->locais      = new Locais(db_connect());
    }

    /** @return array{global: bool, campus: list<int>, predio: list<int>} */
    protected function escopo(string $nivel = Autorizacao::LEITOR): array
    {
        return $this->autorizacao->escopoPara(self::CHAVE, $nivel);
    }

    protected function podeEditarCatalogo(): bool
    {
        return $this->autorizacao->pode(self::CHAVE, Autorizacao::EDITOR);
    }

    /**
     * Permissão no local de um registro (item, estoque, destino).
     *
     * @param array{campus_id: int, predio_id: ?int}|null $local
     */
    protected function podeNoLocal(
        ?array $local,
        string $nivel = Autorizacao::LEITOR,
        string $chave = self::CHAVE
    ): bool {
        return $local !== null && $this->autorizacao->pode($chave, $nivel, $local['campus_id'], $local['predio_id']);
    }

    /**
     * Local de um registro com ambiente_id/almoxarifado_id.
     *
     * @param array<string, mixed> $registro
     *
     * @return Local|null
     */
    protected function localDe(array $registro): ?array
    {
        return $this->locais->local(
            $registro['ambiente_id'] === null ? null : (int) $registro['ambiente_id'],
            $registro['almoxarifado_id'] === null ? null : (int) $registro['almoxarifado_id'],
        );
    }

    /**
     * Quem recebe precisa estar ativo e poder confirmar recebimento no local de destino; senão o item ficaria em
     * trânsito para sempre.
     *
     * @param array{campus_id: int, predio_id: ?int} $destino
     */
    protected function podeReceber(int $usuarioId, array $destino): bool
    {
        if ((new UsuarioModel())->where('ativo', 1)->find($usuarioId) === null) {
            return false;
        }

        return (new Autorizacao($usuarioId, db_connect()))->pode(
            'patrimonio.confirmar_recebimento',
            Autorizacao::EDITOR,
            $destino['campus_id'],
            $destino['predio_id']
        );
    }

    protected function usuarioId(): int
    {
        return (int) (service('autenticacao')->usuario()['id_usuario'] ?? 0);
    }
}
