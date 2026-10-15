<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Integration\ForSeo;

defined('_JEXEC') || define('_JEXEC', 1);

/**
 * The 4SEO bridge edits a file that belongs to another extension. What has to hold:
 * we add one fenced block, we remove exactly that block, and a file we created
 * disappears with it while a file the user already had comes back unchanged.
 */
final class ForSeoBridgeFileTest extends TestCase
{
    private const ORIGINAL = "<?php\n\ndefined('_JEXEC') or die;\n\n\$hooks->add('x', static fn (\$d) => \$d);\n";

    public function testTheBlockIsAddedAtTheEnd(): void
    {
        $updated = ForSeo::addBridge(self::ORIGINAL);

        $this->assertNotNull($updated);
        $this->assertStringStartsWith(self::ORIGINAL, $updated);
        $this->assertStringContainsString(ForSeo::BLOCK_BEGIN, $updated);
        $this->assertStringContainsString(ForSeo::BLOCK_END, $updated);
        $this->assertStringContainsString("include JPATH_LIBRARIES . '/aifriendly/forseo-bridge.php';", $updated);
    }

    public function testTheIncludeIsGuardedSoAMissingBridgeFileCannotBreak4Seo(): void
    {
        $this->assertStringContainsString("if (is_file(JPATH_LIBRARIES . '/aifriendly/forseo-bridge.php'))", ForSeo::snippet());
    }

    public function testAddingTwiceChangesNothing(): void
    {
        $once = ForSeo::addBridge(self::ORIGINAL);

        $this->assertSame($once, ForSeo::addBridge((string) $once));
    }

    public function testRemovingRestoresTheOriginalByteForByte(): void
    {
        foreach ([self::ORIGINAL, str_replace("\n", "\r\n", self::ORIGINAL), "<?php\n// no trailing newline"] as $original) {
            $updated = (string) ForSeo::addBridge($original);

            $this->assertSame($original, ForSeo::removeBridge($updated));
        }
    }

    public function testAFileEndingWithAClosingTagIsLeftAlone(): void
    {
        $this->assertNull(ForSeo::addBridge("<?php\n\$a = 1;\n?>\n"));
    }

    public function testTheLineEarlierVersionsAskedUsersToPasteIsRemovedToo(): void
    {
        $legacy = "<?php\n\$a = 1;\ninclude JPATH_LIBRARIES . '/aifriendly/forseo-bridge.php';\n\$b = 2;\n";

        $this->assertSame("<?php\n\$a = 1;\n\$b = 2;\n", ForSeo::removeBridge($legacy));
    }

    public function testAFileWeCreatedIsDeletedOnceItIsEmpty(): void
    {
        $created = (string) ForSeo::addBridge('');
        $cleaned = ForSeo::removeBridge($created);

        $this->assertStringContainsString(ForSeo::CREATED_MARK, $created);
        $this->assertTrue(ForSeo::isOnlyOurs($created, $cleaned));
    }

    public function testAFileTheUserHadIsNeverDeleted(): void
    {
        $updated = (string) ForSeo::addBridge(self::ORIGINAL);

        $this->assertFalse(ForSeo::isOnlyOurs($updated, ForSeo::removeBridge($updated)));
    }

    public function testAFileWeCreatedButTheUserExtendedIsKept(): void
    {
        $created = (string) ForSeo::addBridge('') . "\n\$hooks->add('mine', static fn (\$d) => \$d);\n";

        $this->assertFalse(ForSeo::isOnlyOurs($created, ForSeo::removeBridge($created)));
    }

    public function testTheInstallerScriptUsesTheSameMarkers(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/script.php');

        $this->assertStringContainsString(ForSeo::BLOCK_BEGIN, $script);
        $this->assertStringContainsString(ForSeo::BLOCK_END, $script);
        $this->assertStringContainsString(ForSeo::CREATED_MARK, $script);
        $this->assertStringContainsString(ForSeo::BRIDGE_FILE, str_replace('\\.', '.', $script));
    }
}
