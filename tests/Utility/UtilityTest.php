<?php

namespace App\Tests\Utility;

use App\Utility\Utility;
use PHPUnit\Framework\TestCase;

class UtilityTest extends TestCase
{
    public function testPregMatchInArray(): void
    {
        // Test exact match
        $this->assertTrue(Utility::pregMatchInArray('test', ['no-match', 'test']));
        // Test no match with prefix
        $this->assertFalse(Utility::pregMatchInArray('dev-test', ['no-match', 'test']));
        // Test no match with postfix
        $this->assertFalse(Utility::pregMatchInArray('test-dev', ['no-match', 'test']));
        // Test no match
        $this->assertFalse(Utility::pregMatchInArray('test', ['no-match']));
    }
}
