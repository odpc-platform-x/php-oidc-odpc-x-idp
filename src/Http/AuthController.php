<?php

namespace OdpcPlatformX\PhpOidcOdpcXIdp\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use OdpcPlatformX\PhpOidcOdpcXIdp\Contracts\AuthUserService;
use OdpcPlatformX\PhpOidcOdpcXIdp\OidcClient;

class AuthController extends Controller
{
    public function __construct(private readonly OidcClient $oidc)
    {
    }

    public function login(Request $request)
    {
        $built = $this->oidc->buildAuthorizeUrl();
        $request->session()->put('odpcx_oauth_tx', $built['tx']);

        return redirect()->away($built['url']);
    }

    public function callback(Request $request)
    {
        if ($request->query('error')) {
            return redirect(config('odpcx-auth.redirect_uri'));
        }

        $tx = $request->session()->pull('odpcx_oauth_tx');

        if (! $tx || $request->query('state') !== $tx['state']) {
            abort(401, 'State mismatch — possible CSRF');
        }

        $claims = $this->oidc->exchangeCode($request->query('code'), $tx);

        $user = app(AuthUserService::class)->onLogin($claims);

        Auth::guard(config('odpcx-auth.guard'))->login($user);
        $request->session()->put('odpcx_id_token', $claims['id_token']);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $request)
    {
        $guard = Auth::guard(config('odpcx-auth.guard'));

        if (! $guard->check()) {
            abort(401, 'Unauthenticated');
        }

        $idToken = $request->session()->get('odpcx_id_token');
        $logoutUrl = $this->oidc->buildLogoutUrl($idToken);

        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['logoutUrl' => $logoutUrl]);
    }

    public function me(Request $request)
    {
        $guard = Auth::guard(config('odpcx-auth.guard'));

        if (! $guard->check()) {
            abort(401, 'Unauthenticated');
        }

        return response()->json($guard->user());
    }
}
