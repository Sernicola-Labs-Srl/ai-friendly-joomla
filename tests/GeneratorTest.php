<?php

declare(strict_types=1);

namespace Sernicola\Library\AiFriendly\Tests;

use PHPUnit\Framework\TestCase;
use Sernicola\Library\AiFriendly\Markdown\Generator;

defined('_JEXEC') || define('_JEXEC', 1);

final class GeneratorTest extends TestCase
{
    public function testYamlEscapesStructuralCharactersAndNewlines(): void
    {
        $generator = new Generator([]);
        self::assertSame('"Name: value"', $generator->yamlEscape('Name: value'));
        self::assertSame('"Line one Line two"', $generator->yamlEscape("Line one\nLine two"));
        self::assertSame('Plain', $generator->yamlEscape('Plain'));
    }
}

