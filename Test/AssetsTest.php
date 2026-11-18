<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Pulse\Plugin;

class AssetsTest extends Base
{
    private const CSS = __DIR__.'/../Assets/css/pulse.css';
    private const JS = __DIR__.'/../Assets/js/pulse.js';

    public function testBothAssetFilesExistAndAreFilled(): void
    {
        $this->assertFileExists(self::CSS);
        $this->assertFileExists(self::JS);
        $this->assertNotSame('', trim(file_get_contents(self::CSS)));
        $this->assertStringContainsString('window.KBPulse', file_get_contents(self::JS));
    }

    public function testCssLaysOutAWeekColumnGridAndRespectsReducedMotion(): void
    {
        $css = file_get_contents(self::CSS);
        foreach (['grid-auto-flow: column', 'grid-template-rows: repeat(7, 11px)', 'gap: 2px', 'var(--color-border, #e5e5e5)', 'prefers-reduced-motion', 'overflow-x: auto'] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }
        foreach ([1 => '.3', 2 => '.5', 3 => '.75', 4 => '1'] as $level => $opacity) {
            $this->assertMatchesRegularExpression('/\[data-level="'.$level.'"\]\s*\{\s*opacity:\s*'.preg_quote($opacity, '/').';/', $css);
        }
    }

    public function testNoTemplateContainsInlineScript(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../Template', \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $checked++;
            $this->assertStringNotContainsStringIgnoringCase('<script', file_get_contents($file->getPathname()), $file->getPathname());
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function testInitializeRegistersBothLayoutAssetHooks(): void
    {
        $this->container['logger'] = $this->createMock(\Psr\Log\LoggerInterface::class);
        (new Plugin($this->container))->initialize();
        $this->assertContains(['template' => 'plugins/Pulse/Assets/css/pulse.css'], $this->container['hook']->getListeners('template:layout:css'));
        $this->assertContains(['template' => 'plugins/Pulse/Assets/js/pulse.js'], $this->container['hook']->getListeners('template:layout:js'));
    }
}
