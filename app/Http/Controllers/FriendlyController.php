<?php

namespace App\Http\Controllers;

use App\Application\Matches\SimulateFriendly;
use App\Domain\Career\Models\Career;
use App\Domain\Football\Models\MatchSimulation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FriendlyController extends Controller
{
    public function create(Career $career)
    {
        $this->authorize('manageSquad', $career);
        $club = $career->controlledClub->club;
        $opponents = $career->clubs()->where('id', '!=', $club->id)->orderBy('name')->get();
        $simulations = MatchSimulation::where('career_id', $career->id)->latest('id')->take(10)->get();

        return view('simulations.create', ['career' => $career, 'opponents' => $opponents, 'simulations' => $simulations,
            'requestId' => (string) Str::uuid(), 'seed' => bin2hex(random_bytes(16)), 'hasLineup' => $club->lineup()->exists()]);
    }

    public function store(Request $request, Career $career, SimulateFriendly $simulate)
    {
        $this->authorize('manageSquad', $career);
        $simulation = $simulate->execute($request->user(), $career, $request->all());

        return redirect()->route('careers.friendlies.show', [$career, $simulation]);
    }

    public function show(Career $career, MatchSimulation $simulation)
    {
        $this->authorize('view', $career);
        abort_unless($simulation->career_id === $career->id, 404);

        return view('simulations.show', ['career' => $career, 'simulation' => $simulation, 'result' => $simulation->result]);
    }
}
