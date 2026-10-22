<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070a12">
    <title>@yield('title', 'Carreiras — Football Manager Web')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    @vite('resources/js/careers.js')
</head>
<body class="career-page">
<div class="career-shell">
    <header class="career-header">
        <div class="career-header-inner">
            <a class="career-brand" href="{{ route('careers.index') }}" aria-label="Football Manager Web — minhas carreiras">
                <span class="career-brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 3v18M3 12h18M17.5 7.5l-11 9M6.5 7.5l11 9"/></svg>
                </span>
                <span class="career-brand-name"><strong>FOOTBALL MANAGER</strong><small>WEB EDITION</small></span>
            </a>
            <div class="career-account">
                <div class="career-user">
                    <span class="career-user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 2)) }}</span>
                    <span class="career-user-name">{{ auth()->user()->name }}</span>
                </div>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button class="career-logout" type="submit" aria-label="Encerrar sessão" title="Encerrar sessão">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="career-main" id="main-content">
        @if(session('status'))
            <p class="career-notice" role="status">{{ session('status') }}</p>
        @endif
        @if($errors->any())
            <div class="career-errors" role="alert" id="form-errors">
                <strong>Confira os dados informados:</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('content')
    </main>

    <footer class="career-footer">&copy; {{ date('Y') }} Football Manager Web. Simulador de gerenciamento futebolístico para navegadores.</footer>
</div>
</body>
</html>
