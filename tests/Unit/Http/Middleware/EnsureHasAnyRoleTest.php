<?php

namespace Tests\Unit\Http\Middleware;

use App\Enums\Role;
use App\Http\Middleware\AttachUserRoles;
use App\Http\Middleware\EnsureHasAnyRole;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class EnsureHasAnyRoleTest extends TestCase
{
    public function test_allows_a_request_when_the_user_holds_a_required_role(): void
    {
        $response = $this->handle(
            assigned: [Role::Controller],
            required: ['admin', 'controller'],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], json_decode($response->getContent(), true));
    }

    public function test_forbids_a_request_when_the_user_holds_none_of_the_required_roles(): void
    {
        $response = $this->handle(
            assigned: [Role::Rider],
            required: ['admin', 'controller'],
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Forbidden.'], json_decode($response->getContent(), true));
    }

    public function test_ignores_non_role_values_on_the_request(): void
    {
        $response = $this->handle(
            assigned: ['controller', Role::Admin, null],
            required: ['admin'],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_forbids_a_request_when_assigned_roles_are_not_an_array(): void
    {
        $response = $this->handle(
            assigned: 'admin',
            required: ['admin'],
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * @param  list<string>  $required
     */
    private function handle(mixed $assigned, array $required): Response
    {
        $request = Request::create('/api/shifts/logon', 'POST');
        $request->attributes->set(AttachUserRoles::ATTRIBUTE, $assigned);

        return (new EnsureHasAnyRole)->handle(
            $request,
            static fn (): Response => response()->json(['ok' => true]),
            ...$required,
        );
    }
}
