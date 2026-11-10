<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Ops\Live\LiveBoard;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Live board: the map of open rides and online drivers, the queues, and its polling feed (ETag, no-store). */
class LiveController extends Controller
{
    public function index(Request $request, LiveBoard $board): View
    {
        return view('ops.live', ['board' => $board->payload($request->user())]);
    }

    public function feed(Request $request, LiveBoard $board): JsonResponse|Response
    {
        $payload = $board->payload($request->user());
        $etag = '"'.sha1(json_encode(array_diff_key($payload, ['generated_at' => true])) ?: '').'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag)->header('Cache-Control', 'private, no-store');
        }

        return response()->json($payload)->header('ETag', $etag)->header('Cache-Control', 'private, no-store');
    }
}
