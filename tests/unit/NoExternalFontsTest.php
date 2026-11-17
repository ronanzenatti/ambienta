<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Fontes são sempre self-hosted (Public Sans via @fontsource-variable/public-sans).
 * Nenhum request a Google Fonts ou a CDN de terceiros, para não expor o IP dos usuários.
 *
 * @internal
 */
final class NoExternalFontsTest extends CIUnitTestCase
{
    private const EXTERNAL_FONT_HOSTS = [
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'use.typekit.net',
        'fonts.bunny.net',
        'use.fontawesome.com',
    ];

    private const TEMPLATE_REFERENCIA = 'tailadmin-free-tailwind-dashboard-template-main';

    public function testNoSourceReferencesAnExternalFontHost(): void
    {
        $roots = [
            APPPATH . 'Views',
            ROOTPATH . 'resources',
            ROOTPATH . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css',
            ROOTPATH . 'design',
        ];

        $found = [];

        foreach ($roots as $root) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                // Template TailAdmin: referência visual de terceiros, fora do git e nunca servida.
                if (str_contains($file->getPathname(), self::TEMPLATE_REFERENCIA)) {
                    continue;
                }

                if (! in_array($file->getExtension(), ['php', 'css', 'js', 'md', 'json', 'html'], true)) {
                    continue;
                }

                $content = (string) file_get_contents($file->getPathname());

                foreach (self::EXTERNAL_FONT_HOSTS as $host) {
                    if (str_contains($content, $host)) {
                        $found[] = $file->getPathname() . ' → ' . $host;
                    }
                }
            }
        }

        $this->assertSame([], $found, "Fontes externas encontradas:\n" . implode("\n", $found));
    }

    public function testBrandBookDeclaresSelfHostedFont(): void
    {
        $readme = (string) file_get_contents(ROOTPATH . 'design/design-system/README.md');

        $this->assertStringContainsString('@fontsource-variable/public-sans', $readme);
        $this->assertStringNotContainsString('via Google Fonts', $readme);
    }
}
