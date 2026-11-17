<?php

namespace App\Controllers\Chamados;

use App\Controllers\ControladorCadastro;
use App\Libraries\Autorizacao;
use App\Libraries\Patrimonio\Locais;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Base do módulo de chamados de adequação (permissão chamados.acesso; ver Config/Routes.php). Requisitos de disciplina
 * herdam daqui mas usam academico.requisitos (ver Requisitos::ehEditor).
 *
 * Regras:
 * - requisitos de disciplina (catálogo sem campus): qualquer leitor consulta; o editor cadastra e altera os
 *   próprios; os de outro professor exigem chamados.resolver_cancelar (em qualquer escopo);
 * - chamado: pelo ambiente (prédio → campus). Abre quem é editor no ambiente; muda o status quem tem
 *   chamados.resolver_cancelar no ambiente; quem abriu pode cancelar o próprio enquanto está aberto. Quem
 *   abriu sempre enxerga o seu chamado, mesmo fora do escopo.
 */
abstract class ControladorChamados extends ControladorCadastro
{
    protected const CHAVE    = 'chamados.acesso';
    protected const RESOLVER = 'chamados.resolver_cancelar';

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
    protected function escopo(string $nivel = Autorizacao::LEITOR, string $chave = self::CHAVE): array
    {
        return $this->autorizacao->escopoPara($chave, $nivel);
    }

    protected function ehEditor(): bool
    {
        return $this->autorizacao->pode(self::CHAVE, Autorizacao::EDITOR);
    }

    protected function usuarioId(): int
    {
        return (int) (service('autenticacao')->usuario()['id_usuario'] ?? 0);
    }

    /** Permissão num ambiente (pelo prédio e campus dele). */
    protected function podeNoAmbiente(
        int $ambienteId,
        string $nivel = Autorizacao::LEITOR,
        string $chave = self::CHAVE
    ): bool {
        $local = $this->locais->local($ambienteId, null);

        return $local !== null && $this->autorizacao->pode($chave, $nivel, $local['campus_id'], $local['predio_id']);
    }
}
