<?php

namespace App\Http\Controllers;

use App\Application\Squads\SaveSquad;
use App\Application\Squads\SquadSheet;
use App\Domain\Career\Models\Career;
use Illuminate\Http\Request;

class SquadController extends Controller
{
    public function edit(Request $request, Career $career, SquadSheet $sheet)
    {
        $this->authorize('manageSquad', $career);
        $formation = $request->old('formation', $request->query('formation'));
        $data = $sheet->forCareer($career, is_string($formation) ? $formation : null);

        return view('squads.edit', $data + ['career' => $career, 'editable' => $sheet->editable($career)]);
    }

    public function update(Request $request, Career $career, SaveSquad $save)
    {
        $this->authorize('manageSquad', $career);
        $save->execute($request->user(), $career, $request->all());

        return redirect()->route('careers.squad.edit', $career)->with('status', 'Escalação e táticas salvas.');
    }
}
