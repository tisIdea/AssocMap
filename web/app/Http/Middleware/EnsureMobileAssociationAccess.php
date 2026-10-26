<?php

namespace App\Http\Middleware;

use App\Services\Auth\MobileAssociationAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileAssociationAccess
{
    public function __construct(private MobileAssociationAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('mobile_access_required', true);
        $identity = $request->attributes->get('supabase_identity');
        if (! is_array($identity)) {
            return ApiRequestGuard::error('unauthenticated', 'Unauthenticated.', 401);
        }
        $context = $this->access->requireAssociation($identity);
        $request->attributes->set('current_user', $context['profile']);
        $request->attributes->set('mobile_context', $context['mobile']);
        $request->attributes->set('mobile_association_id', $context['mobile']['association']['id']);

        return ApiRequestGuard::secure($next($request));
    }
}
