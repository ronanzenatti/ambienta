<?php

namespace App\Controllers;

use App\Libraries\Agendamento\Agenda;
use App\Libraries\Agendamento\FeedIcal;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;

/**
 * Agenda pública de um ambiente (sem login), destino do QR Code da porta, e os feeds iCal: o do ambiente
 * (público, mesmo recorte da agenda pública) e o pessoal, cuja URL carrega o segredo usuarios.token_ical.
 * Sempre a identidade padrão do sistema, como a consulta pública.
 */
class AgendaPublica extends BaseController
{
    public function ambiente(int $id): string
    {
        helper('institucional');

        $ambiente = $this->ambientePublico($id);
        $data     = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->request->getGet('semana'))
            ?: new DateTimeImmutable('today');
        $segunda  = Agenda::segunda($data);

        return view('consulta/ambiente', [
            'ambiente' => $ambiente,
            'segunda'  => $segunda,
            'dias'     => (new Agenda(db_connect()))->semana($id, $segunda, true),
        ]);
    }

    public function icalAmbiente(int $id): ResponseInterface
    {
        $ambiente = $this->ambientePublico($id);

        return $this->calendario(
            (new FeedIcal(db_connect()))->doAmbiente($id, $ambiente['numero'] . ' — ' . $ambiente['nome']),
            'ambiente-' . $id
        );
    }

    /** QR Code (SVG) com a URL da agenda pública do ambiente; ?baixar=1 devolve como anexo, para imprimir. */
    public function qrcode(int $id): ResponseInterface
    {
        $ambiente = $this->ambientePublico($id);

        $svg = (new QRCode(new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64'    => false,
            'eccLevel'        => EccLevel::M,
            'addQuietzone'    => true,
            'svgAddXmlHeader' => true,
        ])))->render(site_url('agenda/ambiente/' . $id));

        $resposta = $this->response->setContentType('image/svg+xml')->setBody($svg);

        if ($this->request->getGet('baixar') !== null) {
            $arquivo = 'qrcode-ambiente-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $ambiente['numero']) . '.svg';
            $resposta->setHeader('Content-Disposition', 'attachment; filename="' . $arquivo . '"');
        }

        return $resposta;
    }

    public function icalUsuario(string $token): ResponseInterface
    {
        $usuario = ctype_xdigit($token) && strlen($token) === 64
            ? db_connect()->table('usuarios')
                ->select('id_usuario, nome, idioma_preferido')
                ->where('token_ical', $token)
                ->where('deletado_em', null)
                ->where('ativo', 1)
                ->where('anonimizado_em', null)
                ->get()->getRowArray()
            : null;

        if ($usuario === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        // O app de calendário não tem sessão: os textos do feed saem no idioma preferido do usuário.
        if (in_array($usuario['idioma_preferido'], config('App')->supportedLocales, true)) {
            $this->request->setLocale((string) $usuario['idioma_preferido']);
            service('language')->setLocale((string) $usuario['idioma_preferido']);
        }

        return $this->calendario(
            (new FeedIcal(db_connect()))->doUsuario((int) $usuario['id_usuario'], lang('Agendamento.icalMyName')),
            'minha-agenda'
        );
    }

    /**
     * Ambiente que pode aparecer em público: existe, e prédio e campus também existem, com o campus ativo.
     *
     * @return array{id_ambiente: int, numero: string, nome: string, ativo: int, predio: string, campus: string}
     */
    private function ambientePublico(int $id): array
    {
        $ambiente = db_connect()->query(
            'SELECT a.id_ambiente, a.numero, a.nome, a.ativo, p.nome AS predio,
                    IF(LENGTH(TRIM(c.abreviacao)) > 0, TRIM(c.abreviacao), c.nome) AS campus
             FROM ambientes a
             JOIN predios p ON p.id_predio = a.predio_id AND p.deletado_em IS NULL
             JOIN campus c  ON c.id_campus = p.campus_id AND c.deletado_em IS NULL AND c.ativo = 1
             WHERE a.id_ambiente = ? AND a.deletado_em IS NULL',
            [$id]
        )->getRowArray();

        if ($ambiente === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $ambiente;
    }

    private function calendario(string $conteudo, string $arquivo): ResponseInterface
    {
        return $this->response
            ->setContentType('text/calendar', 'utf-8')
            ->setHeader('Content-Disposition', 'inline; filename="' . $arquivo . '.ics"')
            ->setHeader('Cache-Control', 'private, max-age=900')
            ->setBody($conteudo);
    }
}
