<?php

use App\Libraries\Agendamento\Recorrencia;
use App\Libraries\Agendamento\Recusa;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Datas das ocorrências de uma série de reservas.
 *
 * @internal
 */
final class RecorrenciaTest extends CIUnitTestCase
{
    /** @param list<DateTimeImmutable> $datas */
    private function formatar(array $datas): array
    {
        return array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i'), $datas);
    }

    public function testSemanalPorNumeroDeOcorrencias(): void
    {
        $datas = Recorrencia::datas(new DateTimeImmutable('2030-03-04 19:00'), 'semanal', null, 3);

        $this->assertSame(['2030-03-04 19:00', '2030-03-11 19:00', '2030-03-18 19:00'], $this->formatar($datas));
    }

    public function testDiariaAteUmaData(): void
    {
        $datas = Recorrencia::datas(new DateTimeImmutable('2030-03-04 08:00'), 'diaria', new DateTimeImmutable('2030-03-06'), null);

        $this->assertSame(['2030-03-04 08:00', '2030-03-05 08:00', '2030-03-06 08:00'], $this->formatar($datas));
    }

    public function testMensalPulaMesSemODia(): void
    {
        $datas = Recorrencia::datas(new DateTimeImmutable('2030-01-31 10:00'), 'mensal', null, 3);

        $this->assertSame(['2030-01-31 10:00', '2030-03-31 10:00', '2030-05-31 10:00'], $this->formatar($datas));
    }

    public function testTetoDeOcorrencias(): void
    {
        $datas = Recorrencia::datas(new DateTimeImmutable('2030-01-01 10:00'), 'diaria', null, 5000);

        $this->assertCount(Recorrencia::MAXIMO, $datas);
    }

    public function testSemTerminoERecusado(): void
    {
        $this->expectException(Recusa::class);

        Recorrencia::datas(new DateTimeImmutable('2030-01-01 10:00'), 'semanal', null, null);
    }
}
