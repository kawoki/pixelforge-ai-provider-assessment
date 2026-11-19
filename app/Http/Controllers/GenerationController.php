<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGenerationAction;
use App\Actions\Generations\ListUserGenerationsAction;
use App\Exceptions\Credits\InsufficientCreditsException;
use App\Exceptions\Generations\GenerationLimitReachedException;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class GenerationController extends Controller
{
    public function index(Request $request, ListUserGenerationsAction $list): Response
    {
        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($list->execute($request->user())),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('generations/Create', [
            'options' => config('pixelforge.options'),
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGenerationAction $create): RedirectResponse
    {
        try {
            $create->execute($request->user(), $request->toData());
        } catch (InsufficientCreditsException $e) {
            return back()->withErrors(['credits' => $e->getMessage()]);
        } catch (GenerationLimitReachedException $e) {
            return back()->withErrors(['limit' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation queued. You will see it update here.')]);

        return to_route('generations.index');
    }
}
