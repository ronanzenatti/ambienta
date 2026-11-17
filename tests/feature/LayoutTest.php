<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class LayoutTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testHomeRendersMasterLayout(): void
    {
        $result = $this->get('/');

        $result->assertStatus(200);
        $result->assertSee('Pular para o conteúdo');

        $html = (string) $result->getBody();

        $this->assertStringContainsString('lang="pt-BR"', $html);
        $this->assertStringContainsString('id="conteudo"', $html);
        $this->assertStringContainsString('assets/css/app.css', $html);
        $this->assertStringContainsString('assets/js/vendor/alpine-focus.min.js', $html);
    }

    public function testDesignSystemShowcaseRendersBaseComponents(): void
    {
        $result = $this->get('_design-system');

        $result->assertStatus(200);

        $html = (string) $result->getBody();

        // Editar: fundo amarelo com texto ink. Excluir: fundo vermelho com texto branco.
        $this->assertStringContainsString('bg-action-edit text-ink', $html);
        $this->assertStringContainsString('bg-signal-critical text-brand-ink', $html);
        $this->assertStringContainsString('bg-signal-confirmed', $html);
    }

    public function testDeleteConfirmationModalStartsDisabledAndNeverClosesOnBackdrop(): void
    {
        $html = $this->get('_design-system')->getBody();

        $this->assertMatchesRegularExpression(
            '/<button[^>]*form="demo[^"]*excluir[^"]*form"[^>]*\sdisabled/',
            (string) $html
        );
        $this->assertStringContainsString('CONFIRMO', (string) $html);

        // Nenhum handler de clique no backdrop do modal.
        $this->assertStringNotContainsString('@click.self', (string) $html);
        $this->assertStringNotContainsString('@click.outside', (string) $html);
        $this->assertStringNotContainsString('@click.away', (string) $html);
    }

    public function testSubmitButtonKeepsItsType(): void
    {
        $html = (string) $this->get('_design-system')->getBody();

        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*>Salvar<\/button>/', $html);
    }

    public function testLanguageFilesExistForAllSupportedLocales(): void
    {
        foreach (['pt-BR', 'en', 'es'] as $locale) {
            $this->assertFileExists(APPPATH . "Language/{$locale}/App.php");
            $this->assertFileExists(APPPATH . "Language/{$locale}/Ui.php");
            $ui = require APPPATH . "Language/{$locale}/Ui.php";
            $this->assertSame('CONFIRMO', $ui['confirmWord'], "Palavra de confirmação deve ser CONFIRMO em {$locale}");
        }
    }
}
