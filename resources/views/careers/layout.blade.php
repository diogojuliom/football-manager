<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Football Manager')</title>
    <script src="{{ asset('js/progression.js') }}" defer></script>
    <style>
        body { font: 1rem/1.5 system-ui, sans-serif; margin: auto; padding: 1.5rem; max-width: 70rem; color: #172c24; background: #f6f8f7; }
        nav { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; border-bottom: 1px solid #bacbc2; padding-bottom: 1rem; }
        a { color: #125f40; } main { padding-top: 1rem; } label { display: block; margin-top: 1rem; }
        input, select, button { font: inherit; padding: .6rem; max-width: 100%; box-sizing: border-box; }
        input, select { width: 26rem; display: block; } button { cursor: pointer; margin-top: 1rem; }
        nav button { margin: 0; } table { border-collapse: collapse; width: 100%; background: white; }
        th, td { text-align: left; padding: .65rem; border-bottom: 1px solid #d8e2dc; }
        .table-scroll { overflow-x: auto; } .errors { border: 2px solid #9b2525; padding: 1rem; color: #7d1d1d; }
        .card { border: 1px solid #c5d4ca; padding: 1rem; margin: 1rem 0; background: white; }
        .primary { background: #125f40; color: white; border: 2px solid #125f40; font-weight: 700; }
    </style>
</head>
<body>
<nav aria-label="Navegação principal">
    <a href="{{ route('home') }}">Football Manager</a>
    @auth
        <a href="{{ route('careers.index') }}">Minhas carreiras</a>
        <a href="{{ route('careers.create') }}">Nova carreira</a>
        <span>{{ auth()->user()->name }}</span>
        <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Sair</button></form>
    @else
        <a href="{{ route('login') }}">Entrar</a>
        <a href="{{ route('register') }}">Criar conta</a>
    @endauth
</nav>
<main>
    @if(session('status'))
        <p role="status">{{ session('status') }}</p>
    @endif
    @if($errors->any())
        <div class="errors" role="alert"><strong>Confira os dados informados:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @yield('content')
</main>
</body>
</html>
