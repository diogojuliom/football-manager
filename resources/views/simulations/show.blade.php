@extends('careers.layout')
@section('title', 'Resultado da partida — '.$career->name)
@section('content')
@php
    $official = $official ?? false;
    $names = [];
    foreach (['home', 'away'] as $side) {
        foreach ($result[$side]['players'] as $player) { $names[$player['player_id']] = $player['name']; }
    }
    $reasons = ['chasing_goal' => 'Buscar empate ou virada', 'protecting_lead' => 'Proteger a vantagem', 'numerical_disadvantage' => 'Reorganizar após expulsão', 'numerical_advantage' => 'Explorar vantagem numérica', 'conserve_energy' => 'Poupar energia', 'fatigue' => 'Desgaste físico', 'booking' => 'Jogador amarelado', 'refresh' => 'Renovar a equipe'];
    $labels = ['kickoff' => 'Início da partida', 'possession' => 'Posse de bola', 'build_up' => 'Construção de jogada', 'opportunity' => 'Oportunidade', 'shot' => 'Finalização', 'save' => 'Defesa do goleiro', 'goal' => 'Gol', 'assist' => 'Assistência', 'foul' => 'Falta', 'yellow_card' => 'Cartão amarelo', 'red_card' => 'Expulsão', 'substitution' => 'Substituição', 'positions_change' => 'Troca de posições', 'tactical_change' => 'Ajuste tático', 'fatigue' => 'Desgaste físico', 'injury' => 'Lesão', 'half_time' => 'Intervalo', 'full_time' => 'Fim da partida', 'abandoned' => 'Partida interrompida'];
@endphp
<header class="page-header"><div class="page-header-copy"><a class="breadcrumb" href="{{ $official ? route('careers.show', $career) : route('careers.friendlies.create', $career) }}">← {{ $official ? 'Voltar à carreira' : 'Voltar aos amistosos' }}</a><p class="eyebrow">{{ $official ? 'Partida oficial' : 'Amistoso' }} · Encerrada</p><h1>Relatório da partida</h1></div></header>
<section class="score-hero"><span class="badge">{{ $result['minutes'] }} minutos</span><div class="match-up"><strong>{{ $result['home']['name'] }}</strong><span class="score">{{ $result['home']['stats']['goals'] }} <em>×</em> {{ $result['away']['stats']['goals'] }}</span><strong>{{ $result['away']['name'] }}</strong></div><p>Motor {{ $simulation->engine_version }} · Seed: {{ $simulation->seed }}</p></section>
@if($result['status'] === 'abandoned')<p role="alert">Partida interrompida por equipe com menos de sete jogadores. O placar acima é o registro até a interrupção.</p>@endif
@if($official)
<p>Resultado oficial registrado: classificação e artilharia atualizadas, desgaste e suspensões aplicados. A data permanece no dia da partida até sua próxima progressão.</p>
@else
<p>Resultado de treinamento: a liga, a data interna, o saldo e a condição física oficial dos atletas foram preservados.</p>
@endif
<section class="panel"><div class="panel-header"><div><h2>Estatísticas das equipes</h2><p>Comparativo completo do desempenho coletivo</p></div></div>
<div class="table-scroll"><table><thead><tr><th>Indicador</th><th>{{ $result['home']['name'] }}</th><th>{{ $result['away']['name'] }}</th></tr></thead><tbody>
@foreach(['possession' => 'Posse (%)', 'opportunities' => 'Oportunidades', 'shots' => 'Finalizações', 'shots_on_target' => 'Finalizações no gol', 'xg' => 'Gols esperados (xG)', 'saves' => 'Defesas', 'fouls' => 'Faltas', 'yellow_cards' => 'Amarelos', 'red_cards' => 'Expulsões', 'substitutions' => 'Substituições'] as $key => $label)
<tr><th>{{ $label }}</th><td>{{ $result['home']['stats'][$key] }}</td><td>{{ $result['away']['stats'][$key] }}</td></tr>
@endforeach
</tbody></table></div></section>
@if(($official && isset($attendance)) || (! $official && $simulation->attendance_plan))
@php($crowd = $result['attendance'] ?? null)
<p>{{ $official ? $attendance->stadium_name : $simulation->attendance_plan['stadium_name'] }} · Público: <strong>{{ number_format($crowd['present'], 0, ',', '.') }}</strong> / {{ number_format($crowd['capacity'], 0, ',', '.') }} ({{ number_format($crowd['occupancy'] * 100, 1, ',', '.') }}%).</p>
<p>Previsão: {{ number_format($official ? $attendance->forecast : $simulation->attendance_plan['forecast'], 0, ',', '.') }} · Importância: {{ $official ? $attendance->importance : $simulation->attendance_plan['importance'] }}/100.</p>
<p>Preço registrado: R$ {{ number_format(($official ? $attendance->ticket_price_cents : $simulation->attendance_plan['input']['price_cents']) / 100, 2, ',', '.') }}.</p>
@if($official && $finances->has('ticket_revenue'))
<p>Bilheteria: R$ {{ number_format($finances['ticket_revenue']->amount_cents / 100, 2, ',', '.') }} · Despesa operacional: R$ {{ number_format(-$finances['match_operating_cost']->amount_cents / 100, 2, ',', '.') }} · Resultado líquido: R$ {{ number_format(($finances['ticket_revenue']->amount_cents + $finances['match_operating_cost']->amount_cents) / 100, 2, ',', '.') }}.</p>
@elseif($official)
<p>Esta partida anterior à implementação financeira não possui lançamentos retroativos.</p>
@else
<p>Amistosos não geram lançamentos financeiros oficiais.</p>
@endif
@else
<p>Público não registrado para esta partida anterior à Entrega 07.</p>
@endif
<section class="panel"><div class="panel-header"><div><h2>Eventos destacados</h2><p>Momentos decisivos em ordem cronológica</p></div></div><ol class="event-list">
@foreach($result['events'] as $event)
    @if(in_array($event['type'], ['goal', 'assist', 'yellow_card', 'red_card', 'substitution', 'abandoned', 'tactical_change'], true))
    <li><time>{{ $event['minute'] }}'</time><span>{{ $labels[$event['type']] }}: {{ $names[$event['player_id']] ?? '' }}
        @if($event['type'] === 'substitution') no lugar de {{ $names[$event['data']['out_player_id']] }} @endif
        @if(isset($event['data']['reason']) && isset($reasons[$event['data']['reason']])) — {{ $reasons[$event['data']['reason']] }} @endif
        @if($event['type'] === 'tactical_change') ({{ $result[$event['side']]['name'] }}) @endif
        @if($event['type'] === 'red_card' && $event['data']['reason'] === 'second_yellow') (segundo amarelo) @endif
    </span></li>
    @endif
@endforeach
</ol></section>
@foreach(['home', 'away'] as $side)
<section class="panel"><div class="panel-header"><h2>{{ $result[$side]['name'] }} — Estatísticas individuais</h2></div>
<div class="table-scroll"><table><thead><tr><th>Jogador</th><th>Minutos</th><th>Gols</th><th>Assistências</th><th>Finalizações</th><th>No gol</th><th>xG</th><th>Defesas</th><th>Amarelos</th><th>Expulsões</th><th>Condição inicial</th><th>{{ $official ? 'Condição final aplicada' : 'Condição final simulada' }}</th></tr></thead><tbody>
@foreach($result[$side]['players'] as $player)
<tr><td>{{ $player['name'] }}</td>@foreach(['minutes', 'goals', 'assists', 'shots', 'shots_on_target', 'xg', 'saves', 'yellow_cards', 'red_cards', 'initial_fitness', 'final_fitness'] as $key)<td>{{ $player[$key] }}</td>@endforeach</tr>
@endforeach
</tbody></table></div></section>
@endforeach
@foreach(['home', 'away'] as $side)
    @if(($result[$side]['controller'] ?? 'human') === 'ai')
    <section class="card">
        <h2>{{ $result[$side]['name'] }} — decisões da IA</h2>
        <p>Formação: {{ $simulation->input[$side]['tactics']['formation'] }}.</p>
        @foreach(\App\Domain\Tactics\Tactics::OPTIONS as $field => $choices)
        <p>{{ \App\Domain\Tactics\Tactics::LABELS[$field] }}: {{ $choices[$simulation->input[$side]['tactics'][$field]] }} → {{ $choices[$result[$side]['final_tactics'][$field]] }}.</p>
        @endforeach
        @if(isset($simulation->input[$side]['administration_plan']))
            @php($plan = $simulation->input[$side]['administration_plan'])
            <h3>Recomendações administrativas</h3>
            <p>Ingresso sugerido: R$ {{ number_format($plan['ticket_price_cents'] / 100, 2, ',', '.') }}. Estimativa inicial de procura: {{ $plan['estimated_attendance'] }} torcedores.</p>
            <p>Reserva operacional: R$ {{ number_format($plan['operating_reserve_cents'] / 100, 2, ',', '.') }}. Limite para investimentos: R$ {{ number_format($plan['investment_budget_cents'] / 100, 2, ',', '.') }}.</p>
            <p>{{ $official ? 'A precificação efetiva da IA consta no registro de público; obras ainda não são executadas.' : 'São recomendações: o amistoso não cobra ingressos, debita valores nem inicia obras.' }}</p>
        @endif
    </section>
    @endif
@endforeach
<details><summary>Todas as jogadas</summary>
<div class="table-scroll"><table><thead><tr><th>Ordem</th><th>Minuto</th><th>Evento</th><th>Equipe</th><th>Jogador</th></tr></thead><tbody>
@foreach($result['events'] as $event)
<tr><td>{{ $event['sequence'] }}</td><td>{{ $event['minute'] }}</td><td>{{ $labels[$event['type']] ?? \Illuminate\Support\Str::headline($event['type']) }}</td><td>{{ $event['side'] ? $result[$event['side']]['name'] : '—' }}</td><td>{{ $names[$event['player_id']] ?? '—' }}</td></tr>
@endforeach
</tbody></table></div>
</details>
@endsection
