<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#090d16">
    <title>@yield('title', 'Football Manager Web')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite('resources/js/auth.js')
</head>
<body class="auth-page">
<div class="auth-shell">
    <header class="auth-header">
        <a class="auth-brand" href="{{ route('home') }}" aria-label="Football Manager Web — início">
            <span class="auth-brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 3v18M3 12h18M17.5 7.5l-11 9M6.5 7.5l11 9"/></svg>
            </span>
            <span class="auth-brand-name"><strong>FOOTBALL MANAGER</strong><small>WEB EDITION</small></span>
        </a>
        <div class="auth-header-actions">
            <span class="auth-season">Modo Carreira</span>
            <a class="auth-header-link" href="{{ request()->routeIs('register') ? route('login') : route('register') }}">
                {{ request()->routeIs('register') ? 'Fazer Login' : 'Criar Nova Conta' }}
            </a>
        </div>
    </header>

    <main class="auth-main">
        <div class="auth-grid">
            <section class="auth-card" aria-labelledby="auth-title">
                <span class="auth-card-glow" aria-hidden="true"></span>
                <div class="auth-card-content">@yield('content')</div>
            </section>

            <aside class="auth-promo" aria-label="Sobre o Football Manager Web">
                <div class="auth-promo-content">
                    <span class="auth-badge"><span class="auth-badge-dot" aria-hidden="true"></span> Simulação Tática Avançada</span>
                    <h2>Monte seu elenco.<br>Domine as táticas.<br>Conquiste a glória.</h2>
                    <p>Comande um clube, prepare cada partida e acompanhe sua jornada pelo campeonato. Cada decisão sua move a carreira adiante.</p>
                    <div class="auth-features">
                        <div class="auth-feature">
                            <span class="auth-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V11M10 19V5M16 19v-9M22 19V7M2 19h20"/></svg></span>
                            <h3>Estatísticas Detalhadas</h3>
                            <p>Resultados, classificação e desempenho dos atletas.</p>
                        </div>
                        <div class="auth-feature">
                            <span class="auth-feature-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 3v18M3 12h18M17.5 7.5l-11 9M6.5 7.5l11 9"/></svg></span>
                            <h3>Seu Clube, Suas Táticas</h3>
                            <p>Monte a escalação e escolha como jogar.</p>
                        </div>
                    </div>
                    <div class="auth-promo-status">
                        <span class="auth-avatar-group" aria-hidden="true"><span>10</span><span>18</span><span>90</span></span>
                        <span>10 clubes · 18 rodadas</span>
                        <span class="auth-saved"><span class="auth-badge-dot" aria-hidden="true"></span> Progresso salvo</span>
                    </div>
                </div>
            </aside>
        </div>
    </main>

    <footer class="auth-footer">&copy; {{ date('Y') }} Football Manager Web. Inspirado na paixão pelo futebol.</footer>
</div>
</body>
</html>
