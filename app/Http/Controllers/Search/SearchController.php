<?php

namespace App\Http\Controllers\Search;

use App\Domain\Search\HeaderSearch;
use App\Domain\Search\SearchText;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The header search (R-52): the suggestions the shared dot-ui pill fetches, and the full results page its form submits
 * to. Both answer only what the signed-in person may see on the surface they are searching (HeaderSearch). Nothing
 * here is cached for anybody else: answers are private and not stored, and no request here ever reaches InfoDot.
 */
class SearchController extends Controller
{
    public const LAYOUTS = [
        'passenger' => 'passenger-layout',
        'driver' => 'driver-layout',
        'fleet' => 'fleet-layout',
        'ops' => 'ops-layout',
        'business' => 'business-layout',
    ];

    public function __construct(private readonly HeaderSearch $search) {}

    public function suggest(Request $request): JsonResponse
    {
        [$surface, $q] = $this->input($request);

        $groups = $this->search->search($request, $request->user(), $surface, $q, HeaderSearch::SUGGEST_LIMIT);

        if ($groups !== []) {
            $groups[] = ['key' => 'all', 'head' => '', 'rows' => [[
                'href' => route('search', ['q' => $q, 'scope' => $surface] + $this->fleetScope($request, $surface)),
                'label' => 'See every result for "'.$q.'"',
                'code' => null,
                'tag' => null,
                'action' => true,
            ]], 'more' => false];
        }

        return response()->json(['groups' => $groups])->header('Cache-Control', 'private, no-store');
    }

    public function page(Request $request): View
    {
        [$surface, $q] = $this->input($request);

        $groups = $this->search->search($request, $request->user(), $surface, $q, HeaderSearch::PAGE_LIMIT);

        return view('search.index', [
            'surface' => $surface,
            'layout' => self::LAYOUTS[$surface],
            'q' => $q,
            'tooShort' => $q !== '' && mb_strlen($q) < SearchText::MIN,
            'groups' => $groups,
            'limit' => HeaderSearch::PAGE_LIMIT,
        ]);
    }

    /** @return array{0: string, 1: string} the surface (403 when the person does not hold the one asked for) and the cleaned text */
    private function input(Request $request): array
    {
        $request->validate(['q' => ['nullable', 'string', 'max:200'], 'scope' => ['nullable', 'string', 'max:20']]);

        $surface = $this->search->resolve($request->user(), $request->query('scope'));
        abort_if($surface === null, 403);

        return [$surface, SearchText::clean($request->query('q'))];
    }

    /** @return array<string, mixed> the fleet being searched, so "see every result" stays on it */
    private function fleetScope(Request $request, string $surface): array
    {
        return $surface === 'fleet' && ctype_digit((string) $request->query('fleet')) ? ['fleet' => (int) $request->query('fleet')] : [];
    }
}
