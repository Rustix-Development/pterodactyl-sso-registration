<?php

namespace RustixDev\Registration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Models\User;
use RustixDev\Registration\Sso\Flow;
use RustixDev\Registration\Sso\SsoException;
use RustixDev\Registration\Sso\SsoService;

/**
 * Account > Connections: the external accounts the signed-in user can sign in with.
 */
class ConnectionController
{
    public function __construct(private readonly SsoService $sso) {}

    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->sso->connections($this->user($request))]);
    }

    /**
     * Starts connecting a provider and answers with its address. A POST, so another site
     * cannot connect its own external account to someone's panel account.
     */
    public function store(Request $request, string $provider): JsonResponse
    {
        if ($limited = SsoController::limit('registration:sso:link:'.$this->user($request)->id)) {
            return $limited;
        }

        try {
            return new JsonResponse(['data' => ['redirect' => $this->sso->start($request, $provider, Flow::LINK)]]);
        } catch (SsoException $exception) {
            return SsoController::refused($exception);
        }
    }

    public function destroy(Request $request, string $provider): Response
    {
        $this->sso->unlink($this->user($request), $provider);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
