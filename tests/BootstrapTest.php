<?php

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testBootstrapConstantsAreAvailable(): void
    {
        self::assertTrue(defined('_JEXEC'));
        self::assertSame('E:/OSPanel/home/joomla.local/public', JPATH_ROOT);
    }
}
