<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Todo checkbox do sistema é um interruptor (toggle switch, estilo iOS): `class="toggle"` e `role="switch"`.
 * Ver "Interruptor" em design/design-system/README.md.
 *
 * @internal
 */
final class ToggleSwitchTest extends CIUnitTestCase
{
    public function testEveryCheckboxIsAToggleSwitch(): void
    {
        $roots = [APPPATH . 'Views', APPPATH . 'Helpers', APPPATH . 'Libraries', APPPATH . 'Tabelas'];
        $found = [];

        foreach ($roots as $root) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = (string) file_get_contents($file->getPathname());

                // Cada <input ...>, atravessando os blocos PHP embutidos (que contêm o caractere de fechar tag).
                preg_match_all('/<input\b(?:<\?.*?\?>|[^>])*>/s', $content, $inputs);

                foreach ($inputs[0] as $input) {
                    if (! preg_match('/type=["\']checkbox["\']/', $input)) {
                        continue;
                    }

                    if (! preg_match('/class=["\'][^"\']*\btoggle\b/', $input) || ! str_contains($input, 'role="switch"')) {
                        $found[] = $file->getPathname();
                    }
                }
            }
        }

        $this->assertSame([], $found, "Checkbox sem class=\"toggle\" role=\"switch\":\n" . implode("\n", $found));
    }

    public function testBrandBookDocumentsTheToggleSwitch(): void
    {
        $readme = (string) file_get_contents(ROOTPATH . 'design/design-system/README.md');

        $this->assertStringContainsString('**Interruptor (toggle switch)**', $readme);
        $this->assertStringContainsString('class="toggle"', $readme);
    }
}
