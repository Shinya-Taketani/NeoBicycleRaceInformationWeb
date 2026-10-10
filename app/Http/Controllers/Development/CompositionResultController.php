<?php

declare(strict_types=1);

namespace App\Http\Controllers\Development;

use App\Domain\Keirin\Presentation\CompositionResultView\Presenter;
use App\Domain\Keirin\Presentation\CompositionResultView\Reader;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Throwable;

final class CompositionResultController extends Controller
{
    public function index(Reader $reader, Presenter $presenter): Response
    {
        try {
            return new Response(view('development.composition-results.index', ['overview' => $presenter->overview($reader->read())]));
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    public function show(string $raceId, Reader $reader, Presenter $presenter): Response
    {
        $id = filter_var($raceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || (string) $id !== $raceId) {
            return new Response('見つかりません', 404);
        }
        try {
            $saved = $reader->read();
            $detail = $reader->detail($saved, $id);
            if ($detail === null) {
                return new Response('見つかりません', 404);
            }
            $overview = $presenter->overview($saved);

            return new Response(view('development.composition-results.show', ['overview' => $overview,
                ...$presenter->detail($overview, $detail)]));
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    private function unavailable(): Response
    {
        return new Response(view('development.composition-results.error'), 503);
    }
}
