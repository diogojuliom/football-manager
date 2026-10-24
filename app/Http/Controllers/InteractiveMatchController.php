<?php

namespace App\Http\Controllers;

use App\Application\Careers\CareerProgressionService;
use App\Application\Matches\InteractiveMatchService;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerMatch;
use App\Domain\Tactics\Tactics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InteractiveMatchController extends Controller
{
    public function start(Request $request, Career $career, CareerMatch $match, InteractiveMatchService $service): RedirectResponse
    {
        $session = $service->start($request->user(), $career, $match);

        return redirect()->route('careers.matches.live.show', [$career, $match])->with('status', $session->minute > 0 ? 'Partida retomada.' : 'Partida preparada.');
    }

    public function show(Request $request, Career $career, CareerMatch $match, InteractiveMatchService $service): View
    {
        $session = $service->find($request->user(), $career, $match);
        abort_unless($session->match_id === $match->id, 404);
        $sameRound = $career->matches()->where('competition_edition_id', $match->competition_edition_id)->where('round', $match->round)
            ->with(['homeClub:id,name', 'awayClub:id,name', 'result'])->orderBy('id')->get();

        return view('matches.live', [
            'career' => $career, 'match' => $match->load(['homeClub', 'awayClub']), 'session' => $session,
            'payload' => $service->response($session), 'tacticalModel' => Tactics::frontendModel(),
            'liveOptions' => Tactics::LIVE_OPTIONS, 'sameRound' => $sameRound,
        ]);
    }

    public function control(Request $request, Career $career, CareerMatch $match, InteractiveMatchService $service): JsonResponse
    {
        $data = $request->validate($this->commonRules() + ['action' => ['required', Rule::in(['pause', 'resume'])]]);

        return response()->json($service->control($request->user(), $career, $service->find($request->user(), $career, $match), $data));
    }

    public function advance(Request $request, Career $career, CareerMatch $match, InteractiveMatchService $service): JsonResponse
    {
        $data = $request->validate($this->commonRules() + ['minutes' => ['sometimes', 'integer', 'between:1,5']]);

        return response()->json($service->advance($request->user(), $career, $service->find($request->user(), $career, $match), $data));
    }

    public function decide(Request $request, Career $career, CareerMatch $match, InteractiveMatchService $service): JsonResponse
    {
        $data = $request->validate($this->commonRules() + [
            'type' => ['required', Rule::in(['tactics', 'positions', 'substitution'])],
            'payload' => ['required', 'array'],
        ]);

        return response()->json($service->decide($request->user(), $career, $service->find($request->user(), $career, $match), $data));
    }

    public function finish(Request $request, Career $career, CareerMatch $match, InteractiveMatchService $service): JsonResponse
    {
        $data = $request->validate($this->commonRules());

        return response()->json($service->finish($request->user(), $career, $service->find($request->user(), $career, $match), $data));
    }

    public function finalize(Request $request, Career $career, CareerMatch $match, InteractiveMatchService $service,
        CareerProgressionService $progression): RedirectResponse
    {
        $session = $service->find($request->user(), $career, $match);
        abort_unless($session->status === 'finished', 409);
        $operation = $progression->execute($request->user(), $career, [
            'action' => CareerProgressionService::PLAY_MATCH,
            'request_id' => (string) Str::uuid(),
            'revision' => $career->fresh()->progression_revision,
            'match_id' => $match->id,
        ]);
        $result = \App\Domain\Football\Models\OfficialMatchResult::where('career_id', $career->id)->where('match_id', $match->id)->firstOrFail();

        return redirect()->route('careers.results.show', [$career, $result])->with('status', 'Partida encerrada e resultado oficial registrado.');
    }

    private function commonRules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:0']];
    }
}
