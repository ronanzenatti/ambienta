<?php

namespace App\Controllers;

use App\Libraries\Agendamento\GradeConsulta;
use App\Models\Agendamento\ConsultaPublicaModel;
use DateTimeImmutable;

/**
 * Consulta pública, sem login: o que acontece em cada ambiente de um campus numa semana (domingo a sábado),
 * aulas do semestre e reservas pontuais. Três visões da mesma grade, cada uma na sua rota:
 * linha do tempo (padrão), por turno e resumo por sala. Usa sempre a identidade padrão do sistema
 * (visão acima de um campus), nunca a de um campus específico.
 */
class Consulta extends BaseController
{
    /** Visão => rota. */
    private const VISOES = [
        'gantt'  => 'consulta',
        'turnos' => 'consulta/turnos',
        'resumo' => 'consulta/resumo',
    ];

    public function index(): string
    {
        return $this->pagina('gantt');
    }

    public function turnos(): string
    {
        return $this->pagina('turnos');
    }

    public function resumo(): string
    {
        return $this->pagina('resumo');
    }

    private function pagina(string $visao): string
    {
        helper(['institucional', 'consulta']);

        $modelo  = new ConsultaPublicaModel();
        $campus  = $this->campus($modelo);
        $data    = $this->data();
        $domingo = $data->modify('-' . $data->format('w') . ' days');
        $filtros = $this->filtros();
        $fins    = $campus === null ? ConsultaPublicaModel::TURNOS_PADRAO : $modelo->fimDosTurnos($campus);

        $grade = $campus === null ? null : GradeConsulta::montar(
            $domingo,
            $modelo->ambientes($campus),
            $modelo->aulas($campus, $filtros, $domingo),
            $modelo->excecoes($domingo),
            $modelo->reservas($campus, $filtros, $domingo),
            $fins,
            $filtros !== []
        );

        // Parâmetros que acompanham todos os links (visões e semanas): campus, filtros e a data escolhida.
        $comuns = ['campus' => $campus] + $filtros;
        $link   = static fn (string $rota, array $extra = []): string => site_url($rota)
            . '?' . http_build_query(array_filter($comuns + $extra, static fn ($v): bool => $v !== null));

        $dia     = GradeConsulta::DIAS[(int) $data->format('N') - 1];
        $turno   = $this->turno();
        $daVisao = $visao === 'turnos' && $turno !== 'todos' ? ['turno' => $turno] : [];
        $semana  = static fn (DateTimeImmutable $outro): string => $link(
            self::VISOES[$visao],
            $daVisao + ['data' => $outro->format('Y-m-d')]
        );

        return view('consulta/pagina', [
            'visao'     => $visao,
            'campus'    => $campus,
            'filtros'   => $filtros,
            'data'      => $data,
            'domingo'   => $domingo,
            'grade'     => $grade,
            'fins'      => $fins,
            'turno'     => $turno,
            'diaPadrao' => $grade !== null && in_array($dia, $grade['dias'], true) ? $dia : 'segunda',
            'acao'      => site_url(self::VISOES[$visao]),
            'links'     => array_map(
                static fn (string $rota): string => $link($rota, ['data' => $data->format('Y-m-d')]),
                self::VISOES
            ),
            'semanaAnterior' => $semana($data->modify('-7 days')),
            'semanaSeguinte' => $semana($data->modify('+7 days')),
            'hoje'           => $semana(new DateTimeImmutable('today')),
            'limpar'         => site_url(self::VISOES[$visao]),
            'linkTurno'      => static fn (string $t): string => $link(
                'consulta/turnos',
                ['data' => $data->format('Y-m-d'), 'turno' => $t === 'todos' ? null : $t]
            ),
        ]);
    }

    /** Campus da URL se for ativo; senão o primeiro campus ativo (a tela já abre preenchida). */
    private function campus(ConsultaPublicaModel $modelo): ?int
    {
        $id = (int) $this->request->getGet('campus');

        return $id > 0 && $modelo->campusAtivo($id) ? $id : $modelo->campusPadrao();
    }

    /** Dia escolhido (Y-m-d); sem ele ou inválido, hoje. A semana mostrada é a que contém esse dia. */
    private function data(): DateTimeImmutable
    {
        $texto = (string) $this->request->getGet('data');
        $data  = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);

        return $data !== false && $data->format('Y-m-d') === $texto ? $data : new DateTimeImmutable('today');
    }

    private function turno(): string
    {
        $turno = (string) $this->request->getGet('turno');

        return in_array($turno, GradeConsulta::TURNOS, true) ? $turno : 'todos';
    }

    /**
     * Filtros válidos da URL (GET). Ids viram inteiros; textos são aparados e limitados.
     *
     * @return array{curso?: int, disciplina?: int, professor?: string, texto?: string}
     */
    private function filtros(): array
    {
        $filtros = [];

        foreach (['curso', 'disciplina'] as $campo) {
            $id = (int) $this->request->getGet($campo);

            if ($id > 0) {
                $filtros[$campo] = $id;
            }
        }

        foreach (['professor', 'texto'] as $campo) {
            $valor = mb_substr(trim((string) $this->request->getGet($campo)), 0, 100);

            if ($valor !== '') {
                $filtros[$campo] = $valor;
            }
        }

        return $filtros;
    }
}
