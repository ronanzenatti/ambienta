<?php

namespace App\Controllers\Agendamento;

use App\Controllers\ControladorCadastro;
use App\Libraries\Autorizacao;
use App\Libraries\Patrimonio\Locais;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Base do módulo de alocações e reservas (permissão agendamento.acesso; ver Config/Routes.php). O escopo é
 * sempre o do ambiente (prédio → campus). Regras:
 * - consultar: leitor no ambiente; quem reservou, o beneficiário e o professor da alocação sempre veem o seu;
 * - reservar: editor no ambiente; reservar para outra pessoa: agendamento.reservar_em_nome_de_terceiros;
 * - criar, cancelar e excluir alocação semestral: agendamento.alocar no ambiente;
 * - confirmar reserva pendente: agendamento.confirmar_reserva; decidir conflito com alocação:
 *   agendamento.autorizar_conflito; cancelar/alterar a de outra pessoa: agendamento.cancelar_reserva_de_terceiros
 *   (quem reservou e o beneficiário cancelam a própria);
 * - bloqueios e feriados do campus: quem gere o ambiente/campus (institucional.acesso editor); feriado para
 *   todos os campi, só o superperfil;
 * - equipamentos e softwares extras: quem gere o patrimônio do ambiente (patrimonio.acesso editor).
 */
abstract class ControladorAgendamento extends ControladorCadastro
{
    protected const CHAVE      = 'agendamento.acesso';
    protected const CONFIRMAR  = 'agendamento.confirmar_reserva';
    protected const ALOCAR     = 'agendamento.alocar';
    protected const AUTORIZAR  = 'agendamento.autorizar_conflito';
    protected const TERCEIROS  = 'agendamento.reservar_em_nome_de_terceiros';
    protected const CANCELAR   = 'agendamento.cancelar_reserva_de_terceiros';
    protected const GESTAO     = 'institucional.acesso';
    protected const PATRIMONIO = 'patrimonio.acesso';

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

    protected function usuarioId(): int
    {
        return (int) (service('autenticacao')->usuario()['id_usuario'] ?? 0);
    }

    /**
     * Ambientes ativos que o escopo alcança, para listas de escolha.
     *
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     *
     * @return array<int, string> id => "campus › prédio › número nome"
     */
    protected function opcoesAmbientes(array $escopo): array
    {
        return service('opcoes')->ambientes($escopo)->todas();
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

    /** Data (AAAA-MM-DD) e hora (HH:MM) postadas ou na URL, se válidas. */
    protected function dataHora(string $data, string $hora): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) !== 1 || ! $this->horaValida($hora)) {
            return null;
        }

        $valor = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $data . ' ' . $hora);

        return $valor === false || $valor->format('Y-m-d') !== $data ? null : $valor;
    }

    protected function horaValida(string $hora): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) === 1;
    }
}
