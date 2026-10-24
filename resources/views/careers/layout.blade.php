<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Football Manager Web')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $gameCareer = $career ?? null;
    $gameClub = $gameCareer?->controlledClub?->club;
@endphp
<body class="game-page">
<a class="skip-link" href="#main-content">Ir para o conteúdo</a>
<div class="game-shell" data-game-shell>
    <header class="game-topbar">
        <button class="icon-button menu-button" type="button" data-menu-toggle aria-controls="game-sidebar" aria-expanded="false" aria-label="Abrir menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        @if($gameCareer)
            <div class="topbar-title"><strong>@yield('page-label', 'Football Manager')</strong><small>@yield('page-context', $gameCareer->name)</small></div>
        @else
            <a class="game-brand" href="{{ route('home') }}" aria-label="Football Manager Web"><span class="game-brand-mark" aria-hidden="true">FM</span><span><strong>FOOTBALL MANAGER</strong><small>WEB EDITION</small></span></a>
        @endif
        @if($gameCareer)
            <div class="topbar-context" aria-label="Contexto da carreira">
                <span>▣ {{ $gameCareer->current_date->format('d/m/Y') }}</span>
                <strong>R$ {{ number_format(($gameClub?->balance_cents ?? 0) / 100, 2, ',', '.') }}</strong>
            </div>
        @endif
        @auth
            <div class="topbar-account">
                <span class="user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 2)) }}</span>
                <span class="user-name">{{ auth()->user()->name }}</span>
                <form method="post" action="{{ route('logout') }}" data-loading-form data-loading-label="Saindo…">
                    @csrf
                    <button class="icon-button" type="submit" aria-label="Encerrar sessão" title="Encerrar sessão"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M14 4h4a3 3 0 013 3v10a3 3 0 01-3 3h-4"/></svg></button>
                </form>
            </div>
        @endauth
    </header>
    <div class="sidebar-scrim" data-menu-close></div>
    <aside class="game-sidebar" id="game-sidebar" aria-label="Navegação da carreira">
        @if($gameCareer && $gameClub)
            <div class="club-identity">
                <span class="club-monogram" aria-hidden="true">{{ collect(explode(' ', $gameClub->name))->map(fn ($word) => mb_substr($word, 0, 1))->take(3)->implode('') }}</span>
                <span><strong>{{ $gameClub->name }}</strong><small>{{ $gameCareer->coach_name }}</small></span>
            </div>
            <nav class="game-nav">
                <p class="nav-label">Carreira</p>
                <a @class(['active' => request()->routeIs('careers.show')]) href="{{ route('careers.show', $gameCareer) }}"><span aria-hidden="true">⌂</span>Visão geral</a>
                <a @class(['active' => request()->routeIs('careers.squad.*')]) href="{{ route('careers.squad.edit', $gameCareer) }}"><span aria-hidden="true">♟</span>Elenco e táticas</a>
                <a @class(['active' => request()->routeIs('careers.stadium.*')]) href="{{ route('careers.stadium.show', $gameCareer) }}"><span aria-hidden="true">▰</span>Estádio e obras</a>
                <a @class(['active' => request()->routeIs('careers.tickets.*', 'careers.matches.tickets.*')]) href="{{ route('careers.tickets.index', $gameCareer) }}"><span aria-hidden="true">◇</span>Ingressos</a>
                <a @class(['active' => request()->routeIs('careers.finances.*')]) href="{{ route('careers.finances.show', $gameCareer) }}"><span aria-hidden="true">$</span>Finanças</a>
                <a @class(['active' => request()->routeIs('careers.friendlies.*')]) href="{{ route('careers.friendlies.create', $gameCareer) }}"><span aria-hidden="true">⚽</span>Amistosos</a>
                <p class="nav-label">Jogo</p>
                <a @class(['active' => request()->routeIs('careers.matches.pre-match')]) href="{{ route('careers.show', $gameCareer) }}#calendario"><span aria-hidden="true">▦</span>Calendário</a>
                <a href="{{ route('careers.show', $gameCareer) }}#classificacao"><span aria-hidden="true">≡</span>Classificação</a>
                <a @class(['active' => request()->routeIs('careers.results.*')]) href="{{ route('careers.show', $gameCareer) }}#estatisticas"><span aria-hidden="true">↗</span>Estatísticas</a>
                <a href="{{ route('careers.show', $gameCareer) }}#historico"><span aria-hidden="true">◷</span>Histórico</a>
            </nav>
        @else
            <nav class="game-nav guest-nav">
                <p class="nav-label">Football Manager</p>
                <a href="{{ route('home') }}">Início</a>
                @auth<a href="{{ route('careers.index') }}">Minhas carreiras</a><a href="{{ route('careers.create') }}">Nova carreira</a>
                @else<a href="{{ route('login') }}">Entrar</a><a href="{{ route('register') }}">Criar conta</a>@endauth
            </nav>
        @endif
        <div class="sidebar-footer">
            @if($gameCareer)<span>Calendário interno</span><strong>Avança somente por sua ação</strong>
            @else<span>Football Manager Web</span><strong>Construa sua história</strong>@endif
        </div>
    </aside>
    <main class="game-main" id="main-content" tabindex="-1">
        @if(session('status'))
            <div class="notice notice-success" role="status" data-dismissible><span aria-hidden="true">✓</span><p>{{ session('status') }}</p><button type="button" aria-label="Fechar aviso" data-dismiss>×</button></div>
        @endif
        @if($errors->any())
            <div class="notice notice-error" role="alert" id="form-errors"><span aria-hidden="true">!</span><div><strong>Confira os dados informados:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
        @endif
        @yield('content')
    </main>
</div>
</body>
</html>
