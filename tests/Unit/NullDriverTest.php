<?php

namespace Tests\Unit;

use App\Services\Embeddings\NullDriver;
use PHPUnit\Framework\TestCase;

class NullDriverTest extends TestCase
{
    public function test_embed_returns_null(): void
    {
        $driver = new NullDriver;

        $result = $driver->embed('test content');

        $this->assertNull($result);
    }

    public function test_dimensions_returns_zero(): void
    {
        $driver = new NullDriver;

        $this->assertEquals(0, $driver->dimensions());
    }
}
