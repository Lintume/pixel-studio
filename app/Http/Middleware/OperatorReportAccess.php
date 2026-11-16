<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operator report opens for a signed-in operator (ADMIN_EMAILS), or for
 * anyone holding a link made by `php artisan report:share-link`.
 *
 * A link instead of a shared password: it opens this one read-only page and
 * nothing else, it expires on its own, and it can't be edited without breaking
 * its signature. Rotating APP_KEY revokes every link at once.
 *
 * The signature covers the path and query only, not the host, so one link
 * works on every domain the app answers on.
 */
class OperatorReportAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasValidSignature(false)) {
            return $next($request);
        }

        if ($request->has('signature')) {
            abort(403, 'This link has expired or is not valid.');
        }

        if ($request->user() === null) {
            return redirect()->guest(route('login'));
        }

        abort_unless($request->user()->isAdmin(), 403);

        return $next($request);
    }
}
