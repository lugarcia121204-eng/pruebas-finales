<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RestrictInstitutionAdministrator
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && !$user->isSuperAdmin() && $user->hasAnyRole(['Admin', 'Admin Nacional'])) {
            // Shared security, backups and catalog mutations affect every institution.
            if ($request->routeIs('superadmin.*', 'security.*', 'catalog.*')) {
                $documents = $request->routeIs('security.documents.*', 'catalog.admin.documents.*',
                    'catalog.documents.show', 'catalog.documents.downloadDocente', 'catalog.documents.streamDocente');
                abort_unless($documents, 403);
            }
            if ($request->routeIs('announcements.*') && !$request->isMethod('GET')) {
                abort(403);
            }
        }
        return $next($request);
    }
}
