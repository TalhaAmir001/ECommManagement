<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Strips the subdirectory prefix from the request path before Laravel
 * routes it. The .htaccess in the project root can internally rewrite
 * every URL to public/, but it can't change REQUEST_URI for PHP — only
 * PHP code can. So we do it here.
 *
 * Concrete example: APP_URL=http://localhost/ECommManagement means the
 * app lives at /ECommManagement. When the browser hits /ECommManagement/
 * dashboard, Laravel would otherwise see the path as
 * "/ECommManagement/dashboard" and find no matching route. This
 * middleware reduces that to "/dashboard" so the route table — which is
 * written assuming a root install — keeps working unchanged.
 *
 * The companion AppServiceProvider::boot() does
 * URL::forceRootUrl(config('app.url')), which makes URL generation
 * produce /ECommManagement/dashboard again. So the subpath disappears
 * for routing but reappears in generated links.
 *
 * When APP_URL has no path (e.g. http://localhost), this middleware
 * short-circuits and is effectively a no-op.
 */
class StripAppSubpath
{
    public function handle(Request $request, Closure $next)
    {
        $subpath = $this->resolveSubpath();
        if ($subpath === null) {
            return $next($request);
        }

        $path = $request->getPathInfo();
        if (! $this->pathHasPrefix($path, $subpath)) {
            return $next($request);
        }

        $newPath = substr($path, strlen($subpath));
        $newPath = $newPath === '' ? '/' : $newPath;

        // Symfony's Request::create() unconditionally rewrites REQUEST_URI
        // and QUERY_STRING on the resulting request from the URI it parses
        // (see vendor/symfony/http-foundation/Request.php, ~line 450). So
        // the only reliable way to keep the query string across this
        // rebuild is to put it in the URI we hand to Request::create().
        $query = $request->getQueryString();
        $newUri = $newPath.($query !== null && $query !== '' ? '?'.$query : '');

        // Build a fresh request. We carry over the original server vars
        // (HTTP_HOST, etc.) and the request body so downstream code sees
        // a faithful copy of the original request minus the path prefix.
        $server = $request->server->all();
        unset($server['REQUEST_URI'], $server['QUERY_STRING']);

        $newRequest = Request::create(
            $newUri,
            $request->getMethod(),
            // $request->post() is a method (returns an array); $request->request
            // is the underlying ParameterBag, which is what ->all() expects.
            $request->request->all(),
            $request->cookies->all(),
            $request->files->all(),
            $server,
            $request->getContent(),
        );

        $newRequest->setUserResolver($request->getUserResolver());

        // Replace the bound request so downstream code (routing,
        // controllers, URL generator) sees the stripped path.
        app()->instance('request', $newRequest);

        return $next($newRequest);
    }

    /**
     * Pull the path component out of APP_URL. Returns null when the app
     * is served at the host root, in which case there's nothing to strip.
     */
    private function resolveSubpath(): ?string
    {
        $appUrl = (string) config('app.url');
        if ($appUrl === '') {
            return null;
        }

        $path = parse_url($appUrl, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }
        $path = rtrim($path, '/');

        return $path === '' ? null : $path;
    }

    private function pathHasPrefix(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }
}
