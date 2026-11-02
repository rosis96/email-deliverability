<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::with('user:id,name');
        if ($action = $request->query('action')) {
            $query->where('action', 'ilike', "%{$action}%");
        }
        if ($entity = $request->query('entity_type')) {
            $query->where('entity_type', $entity);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(min((int) $request->query('per_page', 50), 200)));
    }
}
