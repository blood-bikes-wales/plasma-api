<?php

namespace Tests\Unit\Support;

use App\Support\UkOutwardPostcode;
use PHPUnit\Framework\TestCase;

class UkOutwardPostcodeTest extends TestCase
{
    public function test_normalises_outward_and_full_postcodes(): void
    {
        $this->assertSame('CF14', UkOutwardPostcode::normalise('cf14'));
        $this->assertSame('CF14', UkOutwardPostcode::normalise('CF14 4XW'));
        $this->assertSame('CH7', UkOutwardPostcode::normalise('ch7 1aa'));
        $this->assertNull(UkOutwardPostcode::normalise('INVALID'));
        $this->assertNull(UkOutwardPostcode::normalise(''));
    }
}
