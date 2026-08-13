<?php

namespace Tests\Unit\Support;

use App\Support\FrontendOrigins;
use PHPUnit\Framework\TestCase;

class FrontendOriginsTest extends TestCase
{
    public function test_null_uses_local_vite_default(): void
    {
        $this->assertSame(['http://localhost:5173'], FrontendOrigins::parse(null));
    }

    public function test_empty_string_is_fail_closed(): void
    {
        $this->assertSame([], FrontendOrigins::parse(''));
    }

    public function test_comma_separated_origins_are_trimmed_and_slash_normalized(): void
    {
        $this->assertSame(
            ['http://localhost:5173', 'https://controller.example'],
            FrontendOrigins::parse('http://localhost:5173/, https://controller.example/'),
        );
    }

    public function test_trailing_comma_and_blank_entries_are_dropped(): void
    {
        $this->assertSame(
            ['http://localhost:5173'],
            FrontendOrigins::parse('http://localhost:5173, ,'),
        );
    }
}
