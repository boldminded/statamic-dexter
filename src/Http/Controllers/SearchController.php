<?php

namespace BoldMinded\Dexter\Http\Controllers;

use BoldMinded\Dexter\Service\Searcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Public search endpoint, for client-side interfaces.
 *
 * Unauthenticated, so it is treated as hostile input throughout: only
 * configured indices are reachable, the result limit is capped, and the
 * response carries indexed documents and nothing else -- no configuration,
 * no credentials, no provider diagnostics.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, Searcher $searcher): JsonResponse
    {
        if (! config('dexter.publicSearch', false)) {
            return $this->error('Search is not available.', 404);
        }

        $index = (string) ($request->input('index') ?? '');
        $query = (string) ($request->input('query') ?? $request->input('q') ?? '');

        if ($index === '') {
            return $this->error('An index is required.', 422);
        }

        // The configured map is the allowlist. Refusing an unknown index with
        // the same shape as an empty result would leak which indices exist,
        // so this is an explicit 404 either way.
        if (! $searcher->isConfigured($index)) {
            return $this->error('Unknown index.', 404);
        }

        try {
            $results = $searcher->search(
                $index,
                $query,
                $this->params($request),
                $request->has('limit') ? (int) $request->input('limit') : null,
            );
        } catch (\Throwable) {
            // Deliberately opaque: a provider's own error text can name
            // internal hosts and index structure.
            return $this->error('Search is temporarily unavailable.', 503);
        }

        return response()->json([
            'data' => $results,
            'meta' => [
                'index' => $index,
                'query' => $query,
                'count' => count($results),
            ],
        ]);
    }

    /**
     * Filters accepted as JSON, or as a raw provider filter string.
     */
    private function params(Request $request): array|string
    {
        $raw = $request->input('params') ?? $request->input('filter');

        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return $request->input('filter') !== null ? (string) $raw : [];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
