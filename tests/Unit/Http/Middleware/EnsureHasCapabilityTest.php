<?php

namespace Tests\Unit\Http\Middleware;

use App\Authorization\Capability;
use App\Enums\Role;
use App\Http\Middleware\AttachUserRoles;
use App\Http\Middleware\EnsureHasCapability;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class EnsureHasCapabilityTest extends TestCase
{
    public function test_allows_a_controller_to_manage_shifts(): void
    {
        $response = $this->handle(
            assigned: [Role::Controller],
            capability: Capability::ManageShifts,
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_allows_an_admin_to_manage_shifts_via_hierarchy(): void
    {
        $response = $this->handle(
            assigned: [Role::Admin],
            capability: Capability::ManageShifts,
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_allows_a_controller_to_create_a_job(): void
    {
        $response = $this->handle(
            assigned: [Role::Controller],
            capability: Capability::CreateJob,
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_allows_a_rider_to_view_jobs(): void
    {
        $response = $this->handle(
            assigned: [Role::Rider],
            capability: Capability::ViewJobs,
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_forbids_a_rider_from_managing_shifts(): void
    {
        $response = $this->handle(
            assigned: [Role::Rider],
            capability: Capability::ManageShifts,
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Forbidden.'], json_decode($response->getContent(), true));
    }

    public function test_forbids_a_signed_in_user_with_no_roles(): void
    {
        $response = $this->handle(
            assigned: [],
            capability: Capability::ViewActiveShifts,
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_allows_a_rider_to_view_active_shifts(): void
    {
        $response = $this->handle(
            assigned: [Role::Rider],
            capability: Capability::ViewActiveShifts,
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_rejects_an_unknown_capability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->handle(assigned: [Role::Admin], capability: 'not-a-capability');
    }

    /**
     * @param  list<Role>  $assigned
     */
    private function handle(array $assigned, Capability|string $capability): Response
    {
        $request = Request::create('/api/shifts/logon', 'POST');
        $request->attributes->set(AttachUserRoles::ATTRIBUTE, $assigned);
        $request->headers->set('X-Active-Role', 'controller');

        $name = $capability instanceof Capability ? $capability->value : $capability;

        return (new EnsureHasCapability)->handle(
            $request,
            static fn (): Response => response()->json(['ok' => true]),
            $name,
        );
    }
}
