<?php

declare(strict_types=1);

namespace App\Http\Controllers\Development;

use App\Domain\Keirin\Presentation\CompositionArchiveView\Presenter;
use App\Domain\Keirin\Presentation\CompositionArchiveView\Reader;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class CompositionArchiveController extends Controller
{
    public function index(Request $request, Reader $reader, Presenter $presenter): Response
    {
        if (array_diff(array_keys($request->query()), ['page', 'race_id']) !== []) {
            return $this->notFound();
        }
        $page = Reader::integer($request->query('page', '1'));
        if ($page === null) {
            return $this->notFound();
        }
        if ($request->query->has('race_id')) {
            $id = Reader::integer($request->query('race_id'));
            if ($id === null) {
                return $this->notFound();
            }
            try {
                if ($reader->read(null, $id) === null) {
                    return $this->notFound();
                }

                return new Response('', 302, ['Location' => '/development/keirin/composition-archive/2025/'.$id]);
            } catch (Throwable) {
                return $this->unavailable();
            }
        }
        try {
            $archive = $reader->read($page);

            return $archive === null ? $this->notFound() : new Response(view('development.composition-archive.index',
                ['overview' => $presenter->overview($archive)]));
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    public function show(string $raceId, Request $request, Reader $reader, Presenter $presenter): Response
    {
        $id = Reader::integer($raceId);
        if ($id === null || $request->query() !== []) {
            return $this->notFound();
        }
        try {
            $archive = $reader->read(null, $id);
            $detail = $archive === null ? null : $presenter->detail($archive, $id);

            return $detail === null ? $this->notFound() : new Response(view('development.composition-archive.show',
                ['overview' => $presenter->overview($archive), ...$detail]));
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    private function unavailable(): Response
    {
        return new Response(view('development.composition-archive.error'), 503);
    }

    private function notFound(): Response
    {
        return new Response(view('development.composition-archive.error', ['notFound' => true]), 404);
    }
}
