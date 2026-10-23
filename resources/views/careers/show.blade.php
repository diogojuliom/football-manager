@extends('careers.layout')
@section('title', $career->name.' — Football Manager')
@section('content')
@php
    $club = $career->controlledClub->club;
    $clubRow = collect($standings)->firstWhere('club_id', $club->id);
    $clubPosition = collect($standings)->search(fn ($row) => $row['club_id'] === $club->id);
    $averageFitness = (int) round($club->players->avg('fitness') ?? 0);
    $stateLabel = match($career->state) {
        \App\Domain\Career\CareerState::AwaitingDecision => 'Aguardando decisão',
        \App\Domain\Career\CareerState::Processing => 'Processando ação autorizada',
        \App\Domain\Career\CareerState::PreMatch => 'Pré-jogo',
        \App\Domain\Career\CareerState::AwaitingMatch => 'Aguardando partida',
        \App\Domain\Career\CareerState::AwaitingSeason => 'Aguardando próxima temporada',
    };
    $monogram = collect(explode(' ', $club->name))->map(fn ($word) => mb_substr($word, 0, 1))->take(3)->implode('');
@endphp

<section class="dashboard-hero" aria-label="Resumo da carreira">
    <div class="hero-content">
        <p class="eyebrow">Central do treinador</p>
        <div class="hero-club">
            <span class="club-monogram" aria-hidden="true">{{ $monogram }}</span>
            <div><h1>{{ $club->name }}</h1><p>{{ $career->name }} · Treinador {{ $career->coach_name }} · Dados-base {{ $career->version->version }}</p></div>
        </div>
        <div class="hero-meta">
            <span>📅 {{ $career->current_date->format('d/m/Y') }}</span>
            <span>Temporada {{ $career->current_season_number }}</span>
            <span>{{ $stateLabel }}</span>
        </div>
        <p class="muted">Seu progresso confirmado fica salvo. O calendário só avança quando você solicita uma progressão.</p>
    </div>
    <div class="next-match-card">
        <small>Próxima partida</small>
        @if($nextMatch)
            <div class="match-up"><strong>{{ $nextMatch->homeClub->name }}</strong><span>×</span><strong>{{ $nextMatch->awayClub->name }}</strong></div>
            <p>{{ $nextMatch->scheduled_on->format('d/m/Y') }} · {{ $nextMatch->home_club_id === $club->id ? 'Em casa' : 'Fora de casa' }} · {{ $career->current_date->diffInDays($nextMatch->scheduled_on) }} dias restantes</p>
        @else
            <p>Não há mais partidas do seu clube nesta temporada.</p>
        @endif
    </div>
</section>

<div class="stat-grid" aria-label="Indicadores rápidos">
    <article class="stat-card"><span>Classificação</span><strong>{{ $clubPosition !== false ? ($clubPosition + 1).'º lugar' : '—' }}</strong><small>{{ $clubRow['points'] ?? 0 }} pontos em {{ $clubRow['played'] ?? 0 }} jogos</small></article>
    <article class="stat-card"><span>Condição do elenco</span><strong>{{ $averageFitness }}%</strong><small>{{ $club->players->where('suspended_matches', '>', 0)->count() }} suspenso(s)</small></article>
    <article class="stat-card"><span>Saldo do clube</span><strong>R$ {{ number_format($club->balance_cents / 100, 2, ',', '.') }}</strong><small>Permanente entre temporadas</small></article>
    <article class="stat-card"><span>Estádio</span><strong>{{ number_format($club->stadium->available_capacity, 0, ',', '.') }}</strong><small>{{ $club->stadium->name }} · nível {{ $club->stadium->infrastructure_level }}</small></article>
</div>

@if($season->status === 'completed')
<section class="notice notice-success"><span aria-hidden="true">✓</span><div><strong>Temporada encerrada</strong><p>Encerramento esportivo em {{ $season->ended_on->format('d/m/Y') }}. Campeão: <strong>{{ $season->summary[0]['standings'][0]['name'] }}</strong>. Classificação, artilharia e resultados finais foram preservados.</p>
@if(!$pendingOperation)<form data-progression method="post" action="{{ route('careers.progression.store', $career) }}">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="revision" value="{{ $career->progression_revision }}"><button class="primary" name="action" value="START_NEXT_SEASON">Iniciar temporada {{ $career->current_season_number + 1 }}</button></form>@endif
</div></section>
@endif

<div class="dashboard-grid">
    <div class="stack">
        <section class="panel" aria-labelledby="progress-title">
            <div class="panel-header"><div><h2 id="progress-title">Central de progressão</h2><p>A próxima partida é a ação principal e para automaticamente no pré-jogo.</p></div><span class="badge">{{ $stateLabel }}</span></div>
            @if($pendingOperation)
                <div class="notice notice-warning"><span>!</span><p>Uma operação já autorizada aguarda conclusão. Retome-a para recuperar o progresso com segurança.</p></div>
                <form class="progress-actions" data-progression method="post" action="{{ route('careers.operations.resume', [$career, $pendingOperation]) }}">@csrf<button class="primary primary-action">Retomar operação autorizada</button></form>
            @elseif($decision)
                <p>Acontecimento pendente: <strong>{{ $decision->title }}</strong> ({{ $decision->scheduled_on->format('d/m/Y') }}).</p>
                <form class="progress-actions" data-progression method="post" action="{{ route('careers.progression.store', $career) }}">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="revision" value="{{ $career->progression_revision }}"><input type="hidden" name="event_id" value="{{ $decision->id }}"><button class="primary primary-action" name="action" value="ACKNOWLEDGE_EVENT">Confirmar ciência do acontecimento</button></form>
            @elseif($career->state === \App\Domain\Career\CareerState::PreMatch && $nextMatch)
                <a class="btn btn-primary primary-action" href="{{ route('careers.matches.pre-match', [$career, $nextMatch]) }}">Jogar partida</a>
            @elseif($career->state !== \App\Domain\Career\CareerState::AwaitingSeason)
                <form class="progress-actions" data-progression method="post" action="{{ route('careers.progression.store', $career) }}">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="revision" value="{{ $career->progression_revision }}"><button class="primary primary-action" name="action" value="ADVANCE_TO_NEXT_MATCH">Próxima partida</button><div class="secondary-actions"><button name="action" value="ADVANCE_ONE_DAY">Avançar 1 dia</button><button name="action" value="ADVANCE_TO_NEXT_EVENT">Próximo acontecimento</button></div></form>
            @endif
            @if($nextEvent)<p class="form-help">Próximo acontecimento: {{ $nextEvent->title }}, em {{ $nextEvent->scheduled_on->format('d/m/Y') }}.</p>@endif
        </section>

        <section class="panel" id="classificacao">
            <div class="panel-header"><div><h2>Classificação — {{ $edition->name }}</h2><p>Temporada {{ $displaySeason->number }}</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>Posição</th><th>Clube</th><th>Pontos</th><th>Jogos</th><th>Vitórias</th><th>Empates</th><th>Derrotas</th><th>Gols pró</th><th>Gols contra</th><th>Saldo</th></tr></thead><tbody>@foreach($standings as $row)<tr @class(['is-controlled' => $row['club_id'] === $club->id])><td><span class="position">{{ $loop->iteration }}</span></td><td><strong>{{ $row['name'] }}</strong></td>@foreach(['points','played','wins','draws','losses','goals_for','goals_against','goal_difference'] as $field)<td>{{ $row[$field] }}</td>@endforeach</tr>@endforeach</tbody></table></div>
        </section>

        <section class="panel" id="calendario">
            <div class="panel-header"><div><h2>Calendário da liga — Temporada {{ $displaySeason->number }}</h2><p>Rodadas, mandos e resultados oficiais</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>Data</th><th>Rodada</th><th>Mandante</th><th>Visitante</th><th>Resultado</th></tr></thead><tbody>@foreach($matches as $match)<tr><td>{{ $match->scheduled_on->format('d/m/Y') }}</td><td>{{ $match->round }}</td><td>{{ $match->homeClub->name }}</td><td>{{ $match->awayClub->name }}</td><td>@if($match->result)<a href="{{ route('careers.results.show', [$career, $match->result]) }}"><strong>{{ $match->result->home_goals }} × {{ $match->result->away_goals }}</strong></a>@else<span class="badge">Agendada</span>@endif</td></tr>@endforeach</tbody></table></div>
            <div class="pagination">{{ $matches->links() }}</div>
        </section>

        <section class="panel" id="estatisticas">
            <div class="panel-header"><div><h2>Artilharia e elenco</h2><p>Principais números esportivos</p></div><a href="{{ route('careers.squad.edit', $career) }}">Gerenciar elenco</a></div>
            <h3>Artilharia</h3>
            @if($scorers)<div class="table-scroll"><table><thead><tr><th>Jogador</th><th>Clube</th><th>Gols</th></tr></thead><tbody>@foreach(array_slice($scorers,0,20) as $row)<tr><td>{{ $row['name'] }}</td><td>{{ $row['club_name'] }}</td><td><strong>{{ $row['goals'] }}</strong></td></tr>@endforeach</tbody></table></div>@else<p class="empty-state">Nenhum gol oficial registrado.</p>@endif
            <h3>Elenco</h3>
            <div class="table-scroll"><table><thead><tr><th>Jogador</th><th>Posição</th><th>Idade</th><th>Condição física</th><th>Moral</th></tr></thead><tbody>@foreach($club->players as $player)<tr><td>{{ $player->name }}</td><td>{{ $player->primary_position }}</td><td>{{ $player->age }}</td><td>{{ $player->fitness }}</td><td>{{ $player->morale }}</td></tr>@endforeach</tbody></table></div>
        </section>
    </div>

    <aside class="stack">
        <section class="panel"><div class="panel-header"><h2>Gestão do clube</h2></div><div class="quick-links"><a class="quick-link" href="{{ route('careers.squad.edit',$career) }}"><span>♟</span>Elenco e táticas</a><a class="quick-link" href="{{ route('careers.stadium.show',$career) }}"><span>▰</span>Estádio e obras</a><a class="quick-link" href="{{ route('careers.tickets.index',$career) }}"><span>◇</span>Ingressos</a><a class="quick-link" href="{{ route('careers.finances.show',$career) }}"><span>$</span>Finanças</a><a class="quick-link" href="{{ route('careers.friendlies.create',$career) }}"><span>⚽</span>Simular amistoso</a><a class="quick-link" href="#historico"><span>◷</span>Histórico</a></div></section>
        <section class="panel"><div class="panel-header"><h2>Infraestrutura</h2></div><dl class="data-list"><div><dt>Estádio</dt><dd>{{ $club->stadium->name }}</dd></div><div><dt>Capacidade</dt><dd>{{ number_format($club->stadium->available_capacity,0,',','.') }}</dd></div><div><dt>Obra em andamento</dt><dd>{{ $activeWork ? ($activeWork->type === 'expansion' ? 'Ampliação' : 'Modernização').' até '.$activeWork->due_on->format('d/m/Y') : 'Nenhuma' }}</dd></div></dl></section>
        <section class="panel"><div class="panel-header"><h2>Acontecimentos</h2></div>@if($events->isEmpty())<p class="empty-state">Nenhum acontecimento registrado.</p>@else<ul class="timeline">@foreach($events as $event)<li><time>{{ $event->scheduled_on->format('d/m/Y') }}</time>{{ $event->title }} · {{ $event->status === 'processed' ? 'Processado' : 'Pendente' }}</li>@endforeach</ul>@endif</section>
        <section class="panel" id="historico"><div class="panel-header"><h2>Histórico de temporadas</h2></div>@if($completedSeasons->isNotEmpty())<ul class="timeline">@foreach($completedSeasons as $completed)<li><time>{{ $completed->starts_on->format('d/m/Y') }} a {{ $completed->ended_on->format('d/m/Y') }}</time><strong>Temporada {{ $completed->number }}</strong><br>Campeão: {{ $completed->summary[0]['standings'][0]['name'] }}<br><a href="{{ route('careers.show',['career'=>$career,'season'=>$completed->number]) }}">Ver classificação, artilharia e jogos</a></li>@endforeach</ul>@else<p class="empty-state">A primeira temporada ainda está em andamento.</p>@endif</section>
    </aside>
</div>
@endsection
