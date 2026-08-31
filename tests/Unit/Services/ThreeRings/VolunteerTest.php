<?php

namespace Tests\Unit\Services\ThreeRings;

use App\Services\ThreeRings\Data\Volunteer;
use PHPUnit\Framework\TestCase;

class VolunteerTest extends TestCase
{
    public function test_from_array_maps_live_directory_format(): void
    {
        $volunteer = Volunteer::fromArray([
            'id' => 267046,
            'name' => 'Abeigail Baker [W]',
            'roles' => [
                ['id' => 12929, 'name' => 'Everyone', 'suffix' => null],
                ['id' => 13014, 'name' => 'Rider - Milk', 'suffix' => ''],
            ],
            'volunteer_properties' => [
                ['email' => ['org_name' => 'Email', 'value' => 'a.baker@bloodbikes.wales']],
                ['email_alt' => ['org_name' => 'Alternate Email', 'value' => 'luckyred1@hotmail.co.uk']],
                ['telephone_mobile' => ['org_name' => 'Telephone (Mobile)', 'value' => '07840116046']],
            ],
        ]);

        $this->assertSame(267046, $volunteer->id);
        $this->assertSame('Abeigail Baker [W]', $volunteer->name);
        $this->assertSame('a.baker@bloodbikes.wales', $volunteer->email);
        $this->assertSame(['Everyone', 'Rider - Milk'], $volunteer->roles);
        $this->assertSame('a.baker@bloodbikes.wales', $volunteer->properties['email']);
        $this->assertSame('luckyred1@hotmail.co.uk', $volunteer->properties['email_alt']);
        $this->assertSame('07840116046', $volunteer->phone());
    }

    public function test_from_array_maps_legacy_wrapped_format(): void
    {
        $volunteer = Volunteer::fromArray([
            'id' => 100001,
            'name' => 'Alex Morgan',
            'roles' => [
                ['role' => ['id' => 5001, 'name' => 'Rider']],
                ['role' => ['id' => 5002, 'name' => 'Controller']],
            ],
            'volunteer_properties' => [
                ['volunteer_property' => ['name' => 'Email', 'value' => 'alex.morgan@example.com']],
                ['volunteer_property' => ['name' => 'Telephone', 'value' => '07700 900001']],
            ],
        ]);

        $this->assertSame(100001, $volunteer->id);
        $this->assertSame('Alex Morgan', $volunteer->name);
        $this->assertSame('alex.morgan@example.com', $volunteer->email);
        $this->assertSame(['Rider', 'Controller'], $volunteer->roles);
        $this->assertSame([
            'Email' => 'alex.morgan@example.com',
            'Telephone' => '07700 900001',
        ], $volunteer->properties);
    }

    public function test_from_array_handles_missing_optional_fields(): void
    {
        $volunteer = Volunteer::fromArray([
            'id' => 100002,
            'name' => 'Bethan Hughes',
        ]);

        $this->assertSame(100002, $volunteer->id);
        $this->assertSame('Bethan Hughes', $volunteer->name);
        $this->assertNull($volunteer->email);
        $this->assertSame([], $volunteer->roles);
        $this->assertSame([], $volunteer->properties);
    }

    public function test_from_array_handles_unwrapped_roles_and_properties(): void
    {
        $volunteer = Volunteer::fromArray([
            'id' => 100003,
            'name' => 'Carys Evans',
            'roles' => [['id' => 5003, 'name' => 'Driver']],
            'volunteer_properties' => [['name' => 'Email address', 'value' => 'carys.evans@example.com']],
        ]);

        $this->assertSame(['Driver'], $volunteer->roles);
        $this->assertSame('carys.evans@example.com', $volunteer->email);
    }

    public function test_from_array_extracts_area_and_phone_properties(): void
    {
        $volunteer = Volunteer::fromArray([
            'id' => 100004,
            'name' => 'Dafydd Price',
            'roles' => [],
            'volunteer_properties' => [
                ['volunteer_property' => ['name' => 'Area', 'value' => 'South Wales']],
                ['volunteer_property' => ['name' => 'Telephone', 'value' => '07700900099']],
            ],
        ]);

        $this->assertSame('South Wales', $volunteer->area());
        $this->assertSame('07700900099', $volunteer->phone());
    }

    public function test_matches_email_on_primary_and_alternate_addresses(): void
    {
        $volunteer = Volunteer::fromArray([
            'id' => 100005,
            'name' => 'Eleri Owen',
            'roles' => [],
            'volunteer_properties' => [
                ['email' => ['value' => 'primary@example.com']],
                ['email_alt' => ['value' => 'alt@example.com']],
            ],
        ]);

        $this->assertTrue($volunteer->matchesEmail('primary@example.com'));
        $this->assertTrue($volunteer->matchesEmail('Primary@Example.com'));
        $this->assertTrue($volunteer->matchesEmail('alt@example.com'));
        $this->assertFalse($volunteer->matchesEmail('unknown@example.com'));
    }
}
