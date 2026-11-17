<?php

namespace App\Libraries\Agendamento;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\CURLRequest;
use JsonException;

/**
 * Calendário de feriados (REQUISITOS §7): a API pública (BrasilAPI, feriados nacionais) é a base, sincronizada
 * pelo comando `php spark feriados:sincronizar`; o cadastro manual por campus complementa, sem duplicar data.
 *
 * Feriado da API excluído à mão não volta na sincronização seguinte (a data já tem linha de origem 'api').
 */
final class Feriados
{
    public const ABRANGENCIAS = ['nacional', 'estadual', 'municipal'];

    public const URL_API = 'https://brasilapi.com.br/api/feriados/v1/';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Busca os feriados nacionais do ano na API e grava os que faltam (campus NULL, origem 'api').
     *
     * @return array{recebidos: int, novos: int}
     *
     * @throws Recusa quando a API não responde ou responde fora do formato esperado
     */
    public function sincronizar(int $ano, CURLRequest $cliente): array
    {
        try {
            $resposta = $cliente->get(self::URL_API . $ano, ['http_errors' => false, 'timeout' => 20]);
        } catch (\Throwable) {
            throw new Recusa('Agendamento.holidayApiFailed');
        }

        if ($resposta->getStatusCode() !== 200) {
            throw new Recusa('Agendamento.holidayApiFailed');
        }

        try {
            $lista = json_decode($resposta->getBody() ?? '', true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new Recusa('Agendamento.holidayApiFailed');
        }

        if (! is_array($lista)) {
            throw new Recusa('Agendamento.holidayApiFailed');
        }

        return ['recebidos' => count($lista), 'novos' => $this->gravarDaApi($lista)];
    }

    /**
     * @param array<mixed> $lista itens {date: 'AAAA-MM-DD', name: string}
     */
    public function gravarDaApi(array $lista): int
    {
        $novos = 0;

        foreach ($lista as $feriado) {
            if (
                ! is_array($feriado) || ! isset($feriado['date'], $feriado['name'])
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $feriado['date']) !== 1
            ) {
                continue;
            }

            $data = (string) $feriado['date'];

            $jaTem = $this->db->table('feriados')
                ->where('data_feriado', $data)
                ->where('campus_id', null)
                ->groupStart()->where('deletado_em', null)->orWhere('origem', 'api')->groupEnd()
                ->countAllResults() > 0;

            if ($jaTem) {
                continue;
            }

            try {
                $this->db->table('feriados')->insert([
                    'campus_id'    => null,
                    'data_feriado' => $data,
                    'descricao'    => mb_substr((string) $feriado['name'], 0, 255),
                    'abrangencia'  => 'nacional',
                    'origem'       => 'api',
                ]);
                $novos++;
            } catch (DatabaseException) {
                // Gravado por outra sincronização ao mesmo tempo.
            }
        }

        return $novos;
    }

    /**
     * Cadastro manual. Com campus: não duplica a data se ela já é feriado no campus ou em todos os campi.
     * Sem campus (todos os campi): não duplica um feriado geral na mesma data.
     *
     * @throws Recusa
     */
    public function criar(?int $campusId, string $data, string $descricao, string $abrangencia): int
    {
        $this->exigirDataLivre($campusId, $data, null);

        $this->db->table('feriados')->insert([
            'campus_id'    => $campusId,
            'data_feriado' => $data,
            'descricao'    => $descricao,
            'abrangencia'  => $abrangencia,
            'origem'       => 'manual',
        ]);

        return (int) $this->db->insertID();
    }

    /** @throws Recusa */
    public function alterar(int $id, ?int $campusId, string $data, string $descricao, string $abrangencia): void
    {
        $this->exigirDataLivre($campusId, $data, $id);

        $this->db->table('feriados')->where('id_feriado', $id)->update([
            'campus_id'    => $campusId,
            'data_feriado' => $data,
            'descricao'    => $descricao,
            'abrangencia'  => $abrangencia,
        ]);
    }

    private function exigirDataLivre(?int $campusId, string $data, ?int $ignorarId): void
    {
        $builder = $this->db->table('feriados')
            ->where('data_feriado', $data)
            ->where('deletado_em', null);

        if ($campusId === null) {
            $builder->where('campus_id', null);
        } else {
            $builder->groupStart()->where('campus_id', $campusId)->orWhere('campus_id', null)->groupEnd();
        }

        if ($ignorarId !== null) {
            $builder->where('id_feriado <>', $ignorarId);
        }

        if ($builder->countAllResults() > 0) {
            throw new Recusa('Agendamento.holidayDuplicate');
        }
    }
}
