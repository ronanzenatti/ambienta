<?php

namespace App\Controllers\Institucional;

use App\Controllers\ControladorCadastro;
use App\Libraries\Autorizacao;
use App\Models\Institucional\PredioModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Base dos cadastros institucionais (permissão institucional.acesso; ver Config/Routes.php).
 *
 * Regras de escopo:
 * - campus: criar e excluir exigem editor em escopo global; alterar, editor que cubra o campus;
 * - prédio: criar e excluir exigem editor no campus; alterar, editor no prédio;
 * - ambiente: editor no prédio;
 * - catálogos globais (categorias de ambiente, cursos, disciplinas, semestres): editor em qualquer escopo,
 *   conforme o perfil (o filtro de rota já garante isso).
 * Quem tem só escopo de prédio enxerga o campus desse prédio, mas apenas os prédios do seu escopo.
 */
abstract class ControladorInstitucional extends ControladorCadastro
{
    protected const CHAVE = 'institucional.acesso';

    protected Autorizacao $autorizacao;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        helper('institucional');
        $this->autorizacao = service('autorizacao');
    }

    protected function ehEditorGlobal(): bool
    {
        return $this->autorizacao->escopoPara(static::CHAVE, Autorizacao::EDITOR)['global'];
    }

    /** @return list<int>|null campi que o usuário enxerga (null = todos) */
    protected function campiVisiveis(): ?array
    {
        $escopo = $this->autorizacao->escopoPara(static::CHAVE);

        if ($escopo['global']) {
            return null;
        }

        return array_values(array_unique([...$escopo['campus'], ...(new PredioModel())->campiDe($escopo['predio'])]));
    }

    /** @return list<int>|null prédios do campus que o usuário enxerga (null = todos os do campus) */
    protected function prediosVisiveis(int $campusId): ?array
    {
        if ($this->autorizacao->pode(static::CHAVE, Autorizacao::LEITOR, $campusId)) {
            return null;
        }

        return $this->autorizacao->escopoPara(static::CHAVE)['predio'];
    }

    /**
     * Filtros de campus e prédio das listagens gerais (?campus=, ?predio=), validados contra as mesmas fontes dos
     * selects: um valor fora do escopo do usuário (ou prédio de outro campus) é ignorado, nunca obedecido.
     *
     * @return array{0: ?int, 1: ?int} [campus, prédio]
     */
    protected function filtrosCampusPredio(bool $comPredio): array
    {
        $escopo = $this->autorizacao->escopoPara(static::CHAVE);
        $opcoes = service('opcoes');
        $campus = trim((string) $this->request->getGet('campus'));
        $predio = trim((string) $this->request->getGet('predio'));

        $campusId = ctype_digit($campus) && $opcoes->campi($escopo, true)->contem($campus) ? (int) $campus : null;
        $predioId = $comPredio && ctype_digit($predio) && $opcoes->predios($escopo, $campusId)->contem($predio)
            ? (int) $predio
            : null;

        return [$campusId, $predioId];
    }

    protected function podeVerCampus(int $campusId): bool
    {
        $visiveis = $this->campiVisiveis();

        return $visiveis === null || in_array($campusId, $visiveis, true);
    }

    protected function podeEditarCampus(int $campusId): bool
    {
        return $this->autorizacao->pode(static::CHAVE, Autorizacao::EDITOR, $campusId);
    }

    /** @param array<string, mixed> $predio */
    protected function podeVerPredio(array $predio): bool
    {
        return $this->podeNoPredio(Autorizacao::LEITOR, $predio);
    }

    /** @param array<string, mixed> $predio */
    protected function podeEditarPredio(array $predio): bool
    {
        return $this->podeNoPredio(Autorizacao::EDITOR, $predio);
    }

    /** @param array<string, mixed> $predio */
    private function podeNoPredio(string $nivel, array $predio): bool
    {
        return $this->autorizacao->pode(static::CHAVE, $nivel, (int) $predio['campus_id'], (int) $predio['id_predio']);
    }

    protected function podeEditarCatalogo(): bool
    {
        return $this->autorizacao->pode(static::CHAVE, Autorizacao::EDITOR);
    }

    /**
     * Regras de endereço estruturado e coordenadas, comuns a campus e prédio.
     *
     * @return array<string, array{label: string, rules: string}>
     */
    protected function regrasEndereco(): array
    {
        $opcional = static fn (int $max): string => "permit_empty|max_length[{$max}]";

        return [
            'nome'            => $this->regra('Institucional.fieldName', 'required|max_length[255]'),
            'abreviacao'      => $this->regra('Institucional.fieldAbbreviation', 'required|max_length[20]'),
            'numero'          => $this->regra('Institucional.fieldNumber', $opcional(20)),
            'logradouro'      => $this->regra('Institucional.fieldStreet', $opcional(255)),
            'numero_endereco' => $this->regra('Institucional.fieldStreetNumber', $opcional(20)),
            'complemento'     => $this->regra('Institucional.fieldComplement', $opcional(100)),
            'bairro'          => $this->regra('Institucional.fieldDistrict', $opcional(100)),
            'cidade'          => $this->regra('Institucional.fieldCity', $opcional(100)),
            'uf'              => $this->regra('Institucional.fieldState', 'permit_empty|regex_match[/^[A-Z]{2}$/]'),
            'cep'             => $this->regra(
                'Institucional.fieldPostalCode',
                'permit_empty|regex_match[/^\d{5}-?\d{3}$/]'
            ),
            'latitude'        => $this->regra(
                'Institucional.fieldLatitude',
                'permit_empty|decimal|greater_than_equal_to[-90]|less_than_equal_to[90]'
            ),
            'longitude'       => $this->regra(
                'Institucional.fieldLongitude',
                'permit_empty|decimal|greater_than_equal_to[-180]|less_than_equal_to[180]'
            ),
        ];
    }

    /** @return array<string, string|null> campos de endereço postados, prontos para gravar */
    protected function enderecoPostado(): array
    {
        $dados = [];

        $campos = [
            'nome', 'abreviacao', 'numero', 'logradouro', 'numero_endereco', 'complemento', 'bairro', 'cidade',
            'cep', 'latitude', 'longitude',
        ];

        foreach ($campos as $campo) {
            $dados[$campo] = $this->textoOuNulo($campo);
        }

        $dados['nome']       = (string) $dados['nome'];
        $dados['abreviacao'] = (string) $dados['abreviacao'];
        $dados['uf']         = $this->textoOuNulo('uf') === null ? null : strtoupper((string) $this->textoOuNulo('uf'));

        return $dados;
    }
}
