<?php

namespace App\Http\Controllers;

use App\Application\Attendance\MatchAttendanceService;
use App\Application\Careers\CareerCalendar;
use App\Application\Careers\CareerProgressionService;
use App\Domain\Career\CareerState;
use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerMatch;
use App\Domain\Career\Models\CareerOperation;
use App\Domain\Finance\Models\CareerFinancialTransaction;
use App\Domain\Football\Models\InteractiveMatchSession;
use App\Domain\Football\Models\OfficialMatchResult;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProgressionController extends Controller
{
    public function store(Request $request, Career $career, CareerProgressionService $progression)
    {
        $operation = $progression->execute($request->user(), $career, $request->all());

        return $this->redirect($career, $operation);
    }

    public function resume(Request $request, Career $career, CareerOperation $operation, CareerProgressionService $progression)
    {
        $operation = $progression->resume($request->user(), $career, $operation);

        return $this->redirect($career, $operation);
    }

    public function preMatch(Career $career, CareerMatch $match, CareerCalendar $calendar, MatchAttendanceService $attendance)
    {
        $this->authorize('view', $career);
        abort_unless($match->career_id === $career->id, 404);
        abort_unless($career->state === CareerState::PreMatch && $calendar->nextMatch($career)?->id === $match->id
            && $match->scheduled_on->eq($career->current_date), 409, 'A partida não está no pré-jogo da data atual.');

        $match->load(['homeClub', 'awayClub', 'ticketPolicy']);

        return view('competitions.pre-match', ['career' => $career, 'match' => $match,
            'forecast' => $attendance->forecast($career, $match->homeClub, $match->awayClub, $match),
            'requestId' => (string) Str::uuid(), 'decision' => $calendar->decision($career),
            'interactive' => InteractiveMatchSession::where('career_id', $career->id)->where('match_id', $match->id)->first()]);
    }

    public function result(Career $career, OfficialMatchResult $result)
    {
        $this->authorize('view', $career);
        abort_unless($result->career_id === $career->id, 404);

        $finances = CareerFinancialTransaction::where('career_id', $career->id)->where('match_id', $result->match_id)
            ->get()->keyBy('kind');

        return view('simulations.show', ['career' => $career, 'simulation' => $result, 'result' => $result->result,
            'attendance' => $result->attendance, 'finances' => $finances, 'official' => true]);
    }

    private function redirect(Career $career, CareerOperation $operation)
    {
        if ($operation->kind === CareerProgressionService::PLAY_MATCH) {
            $result = OfficialMatchResult::where('career_id', $career->id)->where('match_id', $operation->input['match_id'])->firstOrFail();

            return redirect()->route('careers.results.show', [$career, $result]);
        }

        return redirect()->route('careers.show', $career);
    }
}
