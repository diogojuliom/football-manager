@extends('careers.layout')
@section('title', $career->name.' — Football Manager')
@section('content')
@php($club = $career->controlledClub->club)
<h1>{{ $career->name }}</h1>
<p>Treinador: {{ $career->coach_name }} · Dados-base {{ $career->version->version }}</p>
<section class="card" aria-label="Resumo da carreira">
    <h2>{{ $club->name }}</h2>
    <p>Data interna: <strong>{{ $career->current_date->format('d/m/Y') }}</strong> · Temporada {{ $career->current_season_number }}</p>
    <p>Estado: {{ match($career->state) {
        \App\Domain\Career\CareerState::AwaitingDecision => 'Aguardando decisão',
        \App\Domain\Career\CareerState::Processing => 'Processando ação autorizada',
        \App\Domain\Career\CareerState::PreMatch => 'Pré-jogo',
        \App\Domain\Career\CareerState::AwaitingMatch => 'Aguardando partida',
        \App\Domain\Career\CareerState::AwaitingSeason => 'Aguardando próxima temporada',
    } }}</p>
    <p>Saldo: R$ {{ number_format($club->balance_cents / 100, 2, ',', '.') }}</p>
    <p>{{ $club->stadium->name }} · {{ number_format($club->stadium->available_capacity, 0, ',', '.') }} lugares disponíveis</p>
    <p>Seu progresso confirmado fica salvo. O calendário só avança quando você solicita uma progressão.</p>
    @if($nextMatch)
    <p>Próxima partida: <strong>{{ $nextMatch->homeClub->name }} × {{ $nextMatch->awayClub->name }}</strong>, em {{ $nextMatch->scheduled_on->format('d/m/Y') }} ({{ $career->current_date->diffInDays($nextMatch->scheduled_on) }} dias restantes).</p>
    @else
    <p>Não há mais partidas do seu clube nesta temporada.</p>
    @endif
    @if($pendingOperation)
    <p>Uma operação já autorizada aguarda conclusão. Retome-a para recuperar o progresso com segurança.</p>
    <form data-progression method="post" action="{{ route('careers.operations.resume', [$career, $pendingOperation]) }}">@csrf<button class="primary">Retomar operação autorizada</button></form>
    @elseif($decision)
    <p>Acontecimento pendente: <strong>{{ $decision->title }}</strong> ({{ $decision->scheduled_on->format('d/m/Y') }}).</p>
    <form data-progression method="post" action="{{ route('careers.progression.store', $career) }}">
        @csrf
        <input type="hidden" name="request_id" value="{{ $requestId }}">
        <input type="hidden" name="revision" value="{{ $career->progression_revision }}">
        <input type="hidden" name="event_id" value="{{ $decision->id }}">
        <button name="action" value="ACKNOWLEDGE_EVENT">Confirmar ciência do acontecimento</button>
    </form>
    @elseif($career->state === \App\Domain\Career\CareerState::PreMatch && $nextMatch)
    <p><a class="primary" href="{{ route('careers.matches.pre-match', [$career, $nextMatch]) }}">Jogar partida</a></p>
    @elseif($career->state !== \App\Domain\Career\CareerState::AwaitingSeason)
    <form data-progression method="post" action="{{ route('careers.progression.store', $career) }}">
        @csrf
        <input type="hidden" name="request_id" value="{{ $requestId }}">
        <input type="hidden" name="revision" value="{{ $career->progression_revision }}">
        <button class="primary" name="action" value="ADVANCE_TO_NEXT_MATCH">Próxima partida</button>
        <button name="action" value="ADVANCE_ONE_DAY">Avançar 1 dia</button>
        <button name="action" value="ADVANCE_TO_NEXT_EVENT">Próximo acontecimento</button>
    </form>
    @endif
    @if($nextEvent)<p>Próximo acontecimento: {{ $nextEvent->title }}, em {{ $nextEvent->scheduled_on->format('d/m/Y') }}.</p>@endif
</section>
@if($season->status === 'completed')
<section class="card"><h2>Temporada encerrada</h2>
<p>Encerramento esportivo em {{ $season->ended_on->format('d/m/Y') }}. Campeão: <strong>{{ $season->summary[0]['standings'][0]['name'] }}</strong>.</p>
<p>Classificação, artilharia e resultados finais foram preservados. A próxima temporada só será criada após sua confirmação.</p>
@if(!$pendingOperation)
<form data-progression method="post" action="{{ route('careers.progression.store', $career) }}">
    @csrf
    <input type="hidden" name="request_id" value="{{ $requestId }}">
    <input type="hidden" name="revision" value="{{ $career->progression_revision }}">
    <button class="primary" name="action" value="START_NEXT_SEASON">Iniciar temporada {{ $career->current_season_number + 1 }}</button>
</form>
@endif
</section>
@endif
@if($completedSeasons->isNotEmpty())
<section class="card" aria-labelledby="season-history-title"><h2 id="season-history-title">Histórico de temporadas</h2>
@foreach($completedSeasons as $completed)
<p><strong>Temporada {{ $completed->number }}</strong> · {{ $completed->starts_on->format('d/m/Y') }} a {{ $completed->ended_on->format('d/m/Y') }} · Campeão: {{ $completed->summary[0]['standings'][0]['name'] }} · <a href="{{ route('careers.show', ['career' => $career, 'season' => $completed->number]) }}">Ver classificação, artilharia e jogos</a>.</p>
@endforeach
</section>
@endif
<p><a href="{{ route('careers.squad.edit', $career) }}">Gerenciar elenco e táticas</a></p>
<p><a href="{{ route('careers.stadium.show', $career) }}">Estádio e histórico de público</a></p>
<p><a href="{{ route('careers.tickets.index', $career) }}">Definir ingressos</a></p>
<p><a href="{{ route('careers.finances.show', $career) }}">Painel financeiro</a></p>
<p><a href="{{ route('careers.friendlies.create', $career) }}">Simular amistoso</a></p>
<h2>Classificação — {{ $edition->name }} — Temporada {{ $displaySeason->number }}</h2>
<div class="table-scroll"><table><thead><tr><th>Posição</th><th>Clube</th><th>Pontos</th><th>Jogos</th><th>Vitórias</th><th>Empates</th><th>Derrotas</th><th>Gols pró</th><th>Gols contra</th><th>Saldo</th></tr></thead><tbody>
@foreach($standings as $row)
<tr><td>{{ $loop->iteration }}</td><td>{{ $row['name'] }}</td>@foreach(['points', 'played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against', 'goal_difference'] as $field)<td>{{ $row[$field] }}</td>@endforeach</tr>
@endforeach
</tbody></table></div>
<h2>Artilharia</h2>
@if($scorers)
<div class="table-scroll"><table><thead><tr><th>Jogador</th><th>Clube</th><th>Gols</th></tr></thead><tbody>
@foreach(array_slice($scorers, 0, 20) as $row)<tr><td>{{ $row['name'] }}</td><td>{{ $row['club_name'] }}</td><td>{{ $row['goals'] }}</td></tr>@endforeach
</tbody></table></div>
@else<p>Nenhum gol oficial registrado.</p>@endif
<h2>Elenco</h2>
<div class="table-scroll"><table><thead><tr><th>Jogador</th><th>Posição</th><th>Idade</th><th>Condição física</th><th>Moral</th></tr></thead><tbody>
@foreach($club->players as $player)
<tr><td>{{ $player->name }}</td><td>{{ $player->primary_position }}</td><td>{{ $player->age }}</td><td>{{ $player->fitness }}</td><td>{{ $player->morale }}</td></tr>
@endforeach
</tbody></table></div>
<h2>Calendário da liga — Temporada {{ $displaySeason->number }}</h2>
<div class="table-scroll"><table><thead><tr><th>Data</th><th>Rodada</th><th>Mandante</th><th>Visitante</th><th>Resultado</th></tr></thead><tbody>
@foreach($matches as $match)
<tr><td>{{ $match->scheduled_on->format('d/m/Y') }}</td><td>{{ $match->round }}</td><td>{{ $match->homeClub->name }}</td><td>{{ $match->awayClub->name }}</td><td>@if($match->result)<a href="{{ route('careers.results.show', [$career, $match->result]) }}">{{ $match->result->home_goals }} × {{ $match->result->away_goals }}</a>@else Agendada @endif</td></tr>
@endforeach
</tbody></table></div>
{{ $matches->links() }}
<h2>Acontecimentos do calendário</h2>
@foreach($events as $event)<p>{{ $event->scheduled_on->format('d/m/Y') }} — {{ $event->title }} — {{ $event->status === 'processed' ? 'Processado' : 'Pendente' }}</p>@endforeach
@endsection
