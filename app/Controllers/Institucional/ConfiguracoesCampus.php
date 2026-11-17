<?php

namespace App\Controllers\Institucional;

use App\Libraries\Agendamento\Configuracoes;
use App\Models\Institucional\CampusModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Configurações do campus (REQUISITOS §9): confirmação e fila de reservas, antecedências, horários de corte
 * dos turnos, agendamento em feriado e prazos de resposta do patrimônio. Página própria (muitos campos),
 * aberta pela região "Ações" da faixa do campus; quem altera o campus altera a configuração dele.
 */
class ConfiguracoesCampus extends ControladorInstitucional
{
    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$campus, $recusa] = $this->carregar($id);

        return $recusa ?? $this->formulario($campus, (new Configuracoes(db_connect()))->doCampus($id));
    }

    public function salvar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$campus, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = [];
        $erros = [];

        foreach (Configuracoes::BOOLEANOS as $campo) {
            $dados[$campo] = $this->request->getPost($campo) === '1' ? 1 : 0;
        }

        foreach (Configuracoes::INTEIROS as $campo) {
            $valor = $this->texto($campo);

            if ($valor !== '' && (! ctype_digit($valor) || (int) $valor > 1000000)) {
                $erros[$campo] = lang('Agendamento.numberInvalid');
            }

            $dados[$campo] = $valor === '' ? null : $valor;
        }

        foreach (Configuracoes::HORARIOS as $campo) {
            $valor = $this->texto($campo);

            if ($valor !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor) !== 1) {
                $erros[$campo] = lang('Agendamento.dateTimeInvalid');
            }

            $dados[$campo] = $valor === '' ? null : $valor;
        }

        if ($erros === [] && ! $this->turnosEmOrdem($dados)) {
            $erros['horario_fim_tarde'] = lang('Agendamento.shiftsOutOfOrder');
        }

        if ($erros !== []) {
            return $this->formulario($campus, $dados, $erros, 422);
        }

        (new Configuracoes(db_connect()))->salvar($id, $dados);

        return redirect()->to(site_url("campus/{$id}"))->with('sucesso', lang('Agendamento.settingsSaved'));
    }

    /** @param array<string, mixed> $dados manhã < tarde < noite, entre os preenchidos */
    private function turnosEmOrdem(array $dados): bool
    {
        $horarios = array_values(array_filter([
            $dados['horario_fim_manha'],
            $dados['horario_fim_tarde'],
            $dados['horario_fim_noite'],
        ], static fn ($h): bool => $h !== null));

        for ($i = 1; $i < count($horarios); $i++) {
            if ($horarios[$i] <= $horarios[$i - 1]) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null} */
    private function carregar(int $id): array
    {
        $campus = (new CampusModel())->find($id);

        if ($campus === null) {
            return [[], redirect()->to(site_url('campus'))->with('erro', lang('Campus.notFound'))];
        }

        return [$campus, $this->podeEditarCampus($id) ? null : $this->negar()];
    }

    /**
     * @param array<string, mixed>  $campus
     * @param array<string, mixed>  $config
     * @param array<string, string> $erros
     */
    private function formulario(
        array $campus,
        array $config,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        $dados = ['campus' => $campus, 'config' => $config];

        return $this->responder('institucional/campus/configuracoes', $dados, $erros, $status);
    }
}
