<?php

use App\Libraries\Agendamento\GradeConsulta;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Regras puras da grade da consulta pública: minutos, faixas dos turnos e faixas (linhas) de sobreposição.
 *
 * @internal
 */
final class GradeConsultaTest extends CIUnitTestCase
{
    private const FINS = ['manha' => 780, 'tarde' => 1080, 'noite' => 1380];

    public function testMinutosEHora(): void
    {
        $this->assertSame(460, GradeConsulta::minutos('07:40:00'));
        $this->assertSame(1380, GradeConsulta::minutos('23:00'));
        $this->assertSame('07:40', GradeConsulta::hora(460));
        $this->assertSame('24:00', GradeConsulta::hora(1440));
    }

    public function testFaixaDeCadaTurno(): void
    {
        $this->assertSame([420, 1380], GradeConsulta::faixaDoTurno('todos', self::FINS));
        $this->assertSame([420, 780], GradeConsulta::faixaDoTurno('manha', self::FINS));
        $this->assertSame([780, 1080], GradeConsulta::faixaDoTurno('tarde', self::FINS));
        $this->assertSame([1080, 1380], GradeConsulta::faixaDoTurno('noite', self::FINS));
    }

    public function testTurnoDoHorario(): void
    {
        $this->assertSame('manha', GradeConsulta::turnoDe(460, self::FINS));
        $this->assertSame('tarde', GradeConsulta::turnoDe(780, self::FINS));
        $this->assertSame('noite', GradeConsulta::turnoDe(1080, self::FINS));
    }

    public function testQuemTocaAFaixa(): void
    {
        $evento = ['imin' => 720, 'fmin' => 840]; // 12h–14h

        $this->assertTrue(GradeConsulta::toca($evento, 420, 780));
        $this->assertTrue(GradeConsulta::toca($evento, 780, 1080));
        $this->assertFalse(GradeConsulta::toca($evento, 840, 1080)); // termina onde a faixa começa
    }
}
