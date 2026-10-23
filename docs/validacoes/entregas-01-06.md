# Auditoria do Primeiro Marco — Núcleo Esportivo

Data: 22/10/2026. Referência: [PRD 2.1](../../plan/PRD.md), RF01–RF09, RF16–RF17, RF04 integral no escopo esportivo, seções 15, 17 e 18, e [planos das entregas](../../plan/entregas/). Base analisada: `a1edf562d564d94854cf7b9d1e5d9db7de41bced`, branch `main`.

**Resultado:** critérios funcionais das Entregas 01–06 validados, com correções aplicadas e todos os testes finais aprovados. Permanecem limitações de fundação técnica e operação descritas abaixo; isso não constitui aprovação integral da stack ou de todas as funcionalidades futuras do MVP. Nenhuma funcionalidade das Entregas 07 em diante foi acrescentada.

## Ambiente e proteção dos dados

- PHP 8.4.26, Laravel 10.50.3, SQLite 3.46.1, MySQL 8.0.46, Node 24.19.0 e Chromium 154.
- PHPUnit usou SQLite em memória e o banco `football_manager_test` de um contêiner MySQL descartável, acessível apenas em `127.0.0.1:13306`.
- Concorrência, migrações, medições e navegador usaram bancos separados e descartáveis. Nenhuma operação foi executada em produção.
- O teste multiprocesso exige `APP_ENV=testing` e um nome de banco de auditoria explícito antes de executar `migrate:fresh`. Não deve ser apontado para bancos de desenvolvimento com dados a preservar.
- A árvore de trabalho estava limpa no início. Os arquivos alterados pertencem à auditoria; PRD, versões publicadas dos motores e saves de desenvolvimento foram preservados.

## Situação por entrega

| Entrega | Critérios examinados e evidências | Situação |
| --- | --- | --- |
| 01 — Dados-base e clubes | Dez clubes fictícios, 200 jogadores, dez estádios, competição e finanças iniciais; atributos e posições válidos; seeds versionadas, checksum, repetição e rollback; relacionamentos e unicidade por versão. `GameDatabaseSeederTest` verifica integridade e rejeição de dados inválidos nos dois bancos. | Aceite funcional atendido. |
| 02 — Carreiras | Criação transacional do universo, seleção do clube, proprietário/membership, nove IAs, isolamento de cópias, autorização, idempotência, preservação de data/estado e retomada. `CareerTest`, `SessionTest`, `PasswordRecoveryTest` e testes de progressão cobrem usuários diferentes, múltiplas carreiras, chaves estrangeiras, falhas e desconexão. Recuperação de acesso do RF01 completada nesta auditoria. | Aceite funcional atendido; SMTP real depende da configuração do ambiente. |
| 03 — Elenco e táticas | Quatro formações, onze titulares, reservas conforme regra congelada, mentalidade/pressão/ritmo/construção, pertencimento ao clube, disponibilidade, goleiro, duplicações, controle de revisão e persistência entre sessões. `SquadTest` cobre autorização, rollback e bloqueio durante processamento. | Aceite atendido. |
| 04 — Motor de partidas | Motor puro baseado em acontecimentos, posse, oportunidades, gols, xG, assistências, cartões, substituições, fadiga, contexto de mando e estatísticas individuais. Replays de referência V1/V2 preservados, replay após JSON/MySQL e de todos os 90 jogos de uma temporada. Invariantes ampliadas para V2 humana e IA. | Aceite atendido; calibração estatística definitiva permanece na Entrega 12. |
| 05 — IA dos clubes | Seleção determinística com disponibilidade e adequação posicional; estratégias por força/mando; reações a placar, expulsões e desgaste; limites e intervalo de substituições; partidas completas entre duas IAs. Recomendações administrativas respeitam saldo/reserva, sem executar finanças ou obras futuras. `ClubAiTest`, `AiFriendlyTest` e `MatchBalanceTest`. | Aceite esportivo atendido; administração segue o limite inicial da entrega. |
| 06 — Competições e progressão | Liga completa, classificação, artilharia, processamento diário, três ações de avanço, pré-jogo persistente, confirmação, resultados únicos, recuperação de falhas e encerramento com campeão/histórico. `ProgressionTest`, `SportsCoreAuditTest` e integração multiprocesso nos dois bancos. | Todos os critérios de aceite esportivos atendidos. |

### Critérios específicos da Entrega 06

| Critério | Validação |
| --- | --- |
| Dez clubes, 18 rodadas e 90 partidas | Nove mandos e nove visitas por clube; todos os adversários em turno/returno; cinco confrontos por rodada, sem clube repetido na rodada. |
| Calendário persistente e independente | Datas internas congeladas por carreira; GET, login, logout e passagem simulada de 80/365 dias reais não iniciam progressão. Não há cron ou worker que inicie avanços. |
| Avançar um dia | Recuperação limitada a 100, com fronteira de lesão respeitada; suspensão é cumprida por partida oficial, sem redução artificial por dia. |
| Próxima partida | Processa dias intermediários e jogos da IA antecipados; para no pré-jogo, sem simular automaticamente o clube humano. |
| Próximo acontecimento | Ignora acontecimentos meramente informativos como destino; respeita decisões obrigatórias, partidas e encerramento. |
| Cronologia e equivalência | Avanços diários e salto produzem os mesmos dias, fitness, suspensões, acontecimentos e checksums de resultados, inclusive com lesão e decisão intermediária. |
| Pré-jogo e confirmação | Escalação e táticas continuam editáveis; execução exige data, partida e estado corretos. Snapshot humano é congelado na autorização. |
| Jogos da IA e pós-jogo | Processamento nas datas aplicáveis e ordem determinística por ID; mesma data após a rodada, estatísticas/resultados consultáveis e efeitos físicos aplicados uma vez. |
| Idempotência e concorrência | Chave da operação, checksum, revisão e bloqueio por carreira; restrições únicas para dia e resultado. Duas conexões reais testadas para avanço/partida com chaves iguais e distintas. |
| Interrupção e recuperação | Falha injetada depois de partidas/efeitos reverte a transação; reserva autorizada permanece pendente. Retomada explícita usa o snapshot congelado e não duplica resultados. |
| Temporada completa | 90 resultados únicos, 18 jogos por clube, totais de gols pró/contra/artilharia reconciliados, pontuação e desempates conferidos; campeão e tabela final congelados no dia posterior ao último jogo. |

## Problemas encontrados e correções

1. **Formulários retornavam erro 500 após validação de arrays.** Valores inválidos em `old('email')`, nome, treinador, UUID ou clube chegavam ao escape HTML/conversão de string. As views agora verificam o tipo e reapresentam os erros sem quebrar. Dois testes de regressão reproduziam a falha antes da correção.
2. **Recuperação física executava uma atualização por jogador/dia.** Substituída por um único `UPDATE` com `CASE`, restrito à carreira e à disponibilidade por lesão. Mantidos teto de fitness, configuração, transação e registro único do dia. Testes verificam o número de consultas, as fronteiras e a ausência de alterações em outra carreira.
3. **Classificação e artilharia carregavam duas vezes todos os snapshots/eventos.** Classificação lê somente IDs e placares; artilharia extrai do JSON apenas nomes e estatísticas dos jogadores. O join inclui a carreira, e o calendário carrega apenas os campos do placar. A ordenação e os dados finais continuam idênticos.
4. **Filtros por data aplicavam funções sobre colunas indexadas.** Os filtros agora usam intervalos de datas, compatíveis com `DATE` do MySQL e datas/horários à meia-noite do SQLite. Nova migração reversível adiciona `matches(career_id, status, scheduled_on, id)`. `EXPLAIN` confirmou uso do índice; o pequeno sort por ID da rodada foi preservado para manter a ordem determinística. O índice existente de eventos também é utilizável. O MySQL já possui índice adequado para jogadores por clube/carreira, portanto não foi acrescentado outro equivalente.
5. **Recuperação de acesso do RF01 ausente.** Incluídos formulários e broker de senha do Laravel: token armazenado com hash, vínculo com e-mail, expiração em 60 minutos, uso único, limite de solicitações e resposta textual igual para conta inexistente/token limitado. Links usam `APP_URL`, sem confiar no cabeçalho Host. O middleware `auth.session` invalida sessões cujo hash de senha mudou. A versão da senha é registrada já no login/cadastro: sem isso, uma sessão ainda sem acesso a rotas protegidas podia sobreviver à redefinição; esse caso foi reproduzido e corrigido. Os testes de troca de usuário passaram a efetuar logout antes de autenticar outro proprietário, preservando as verificações de autorização.
6. **Validação padrão de e-mail vulnerável na dependência Laravel.** Todos os quatro fluxos de autenticação expostos validam tipo, tamanho, formato estrito e rejeitam CR/LF explicitamente. Há regressão para os separadores em cadastro, login e recuperação/redefinição. O aviso da dependência e a necessidade de atualização estão detalhados adiante.
7. **`.env` com chave preenchida estava versionado.** Removido do índice Git, preservado localmente e ignorado junto com variantes e bancos SQLite. `.env.example` permanece como modelo. A exclusão do arquivo não remove o segredo do histórico anterior e não substitui a rotação em ambientes que usaram aquela chave.
8. **Dependências JavaScript sem lockfile e com dois avisos.** Vite atualizado de 5.4.21 para 6.4.5, plugin Laravel para 1.3.0, esbuild transitivo para 0.25.12; `package-lock.json` incluído. `npm audit` final: zero avisos. As URLs do lockfile usam `registry.npmjs.org`, sem credenciais embutidas.
9. **Progressão sem feedback durante o envio.** As ações agora exibem mensagem acessível, `aria-busy` e desabilitam botões. O valor do botão acionado é preservado no POST; novo envio é bloqueado, e o retorno pelo cache de navegação restaura o formulário. Proteções transacionais permanecem no backend e o fluxo funciona sem JavaScript.
10. **Cobertura insuficiente da V2 e de concorrência reproduzível no repositório.** Ampliadas as invariantes para os modos V1, V2 humano e V2 IA; adicionadas 600 partidas sintéticas da IA atual e um teste multiprocesso com barreira de início, executável nos dois bancos.

## Segurança, arquitetura e consultas

- Autorização permanece nos controllers e nos serviços de aplicação, com verificação explícita de pertencimento de resultados, operações, adversários e jogadores à carreira. IDs de outras carreiras/usuários são rejeitados. Entradas do cliente não controlam placares, data final ou atributos oficiais.
- CSRF, escape HTML, hashing de senhas, regeneração de sessão, rate limiting e isolamento de saves foram exercitados. O navegador real confirmou HTTP 419 para mutação sem token CSRF.
- Restrições compostas impedem relacionamentos entre carreiras; unicidade protege criação, resultados, seleções e acontecimentos processados. A migração nova não regrava dados e sua reversão/reaplicação foi exercitada sobre carreiras com partidas já disputadas.
- Bindings de consulta são mantidos. A única expressão aritmética nova interpola exclusivamente configuração convertida para inteiro e limitada entre 0 e 100.
- Relações de clubes, estádios e placares são carregadas antecipadamente. O problema observado nas tabelas era excesso de dados e hidratação, não uma consulta extra por resultado. Classificação e artilharia juntas passaram de seis para quatro consultas, independentemente dos 90 resultados.
- Motores e políticas publicados não foram refatorados para eliminar duplicação entre V1/V2: essa separação preserva replays anteriores. Não se adicionou cache persistente que pudesse invalidar classificação incorretamente, nem se alterou o algoritmo esportivo durante a otimização SQL.
- A aplicação atual usa Blade e JavaScript simples. **Não há componentes Vue, Inertia ou TypeScript para auditar.** A divergência em relação à stack prevista no PRD pertence à Entrega 00 e está registrada como pendência.

## Testes executados

Resultado final, após todas as correções:

| Verificação | Aprovados | Reprovados | Evidência |
| --- | ---: | ---: | --- |
| PHPUnit completo, SQLite | 125 | 0 | 259.780 assertions; 103,23 s |
| PHPUnit funcional, MySQL | 102 | 0 | 16.897 assertions; 104,81 s |
| Concorrência real, SQLite | 4 | 0 | Avançar/jogar, chaves iguais/distintas, duas conexões por caso |
| Concorrência real, MySQL | 4 | 0 | Mesmos cenários, sem duplicação ou operação pendente |
| Reversão/reaplicação da migração com dados | 2 | 0 | Um cenário em cada banco; jogadores e partidas preservados |
| Fluxo integrado Chromium | 1 | 0 | CSRF, cadastro, carreira, escalação, avanço, bloqueio de envio, pré-jogo, resultado, logout/login e recuperação; zero erros JavaScript |
| **Total de execuções finais acima** | **238** | **0** | Reexecuções MySQL não representam novos casos distintos do PHPUnit |

A suíte passou de 110 para 125 casos: seis novos casos de auditoria, seis de recuperação de acesso, duas parametrizações adicionais de invariantes e um teste de distribuição da IA. As falhas reproduzidas durante a investigação foram corrigidas; a tabela contabiliza a validação final. Os tempos das suítes não são benchmarks comparativos: SQLite e MySQL foram executados em paralelo.

Detalhes esportivos:

- 2.000 partidas sintéticas V1 já existentes, com cenários de força, esforço e exaustão.
- 600 partidas V2/IA adicionais: 300 equilibradas, 100 com mandante forte, 100 com visitante forte e 100 com mandante exausto. Verificam variação, vantagem de qualidade, efeito da condição física e baixa incidência de abandono.
- 100 seeds por modo nas invariantes V1/V2 humano/V2 IA, com replay repetido, reconciliação de gols/cartões/faltas/defesas/substituições, cronologia, minutos, jogadores ativos e fitness. Mantidos também os 60 cenários de reações da IA existentes.
- Temporada integral disputada e reexecutada a partir dos snapshots persistidos nos dois bancos. Os hashes de referência publicados de V1 e V2 permanecem iguais.
- Pint: 126 arquivos aprovados. `composer validate --strict --no-check-publish`, `git diff --check`, sintaxe JavaScript e build Vite aprovados; build final em aproximadamente 400 ms.

### Como reproduzir

Na nuvem preparada, primeiro carregue `source /workspace/.cloud-tools/football-env.sh`. Em uma instalação nova, crie `.env` a partir de `.env.example`, gere uma chave local e instale as dependências. Use exclusivamente bancos descartáveis nos comandos de teste.

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test

# Requer um MySQL de testes independente já disponível.
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=13306 \
DB_DATABASE=football_manager_test DB_USERNAME=root DB_PASSWORD= \
php artisan test --testsuite Feature

touch /tmp/football_concurrency_audit.sqlite
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/football_concurrency_audit.sqlite \
php tests/Support/concurrent-progression.php

# Criar previamente football_manager_audit_concurrency no MySQL descartável.
APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=13306 \
DB_DATABASE=football_manager_audit_concurrency DB_USERNAME=root DB_PASSWORD= \
php tests/Support/concurrent-progression.php

vendor/bin/pint --test
composer validate --strict --no-check-publish
npm ci
npm audit
npm run build
node --check resources/js/app.js
git diff --check
```

## Métricas de desempenho

Comparação local dos serviços anteriores e corrigidos sobre cópias do **mesmo banco SQLite com 90 resultados oficiais**. Classificação/artilharia: mediana de nove leituras por versão. Recuperação: uma operação diária com 200 jogadores em fitness 50 em uma carreira nova. Os tempos variam com hardware, aquecimento e carga; as contagens SQL e a igualdade do resultado são as verificações de regressão mais estáveis.

| Operação | Antes | Depois |
| --- | ---: | ---: |
| Classificação + artilharia, mediana | 117,12 ms | 18,42 ms |
| Consultas para as duas tabelas | 6 | 4 |
| Avanço de um dia com recuperação | 53,31 ms | 12,27 ms |
| Consultas totais do avanço | 240 | 40 |
| Atualizações de jogadores no avanço | 200 | 1 |

O hash conjunto de classificação e artilharia foi idêntico antes/depois: `5305112d2423bb7bd9d72b21a1215dfc41833ebf143ce4e51ca1a969ec3ae122`. Uma temporada adicional de preparação da medição, com 90 jogos, avanços e salvamentos de escalação, levou aproximadamente 3,35 s nesse ambiente. Isso não representa um SLA HTTP nem um teste de carga multiusuário.

## Dependências e limitações remanescentes

1. **Framework:** o projeto continua em Laravel 10.50.3, cuja linha encerrou suporte de segurança. A fonte [FriendsOfPHP/security-advisories](https://github.com/FriendsOfPHP/security-advisories), revisão `0d1ebad078dbc60fba7c29f758df46f13faa48e1`, foi comparada a todas as versões de `composer.lock` com Composer Semver. Encontrado um aviso aplicável: [CVE-2026-48019 / GHSA-5vg9-5847-vvmq](https://github.com/laravel/framework/security/advisories/GHSA-5vg9-5847-vvmq), sobre CRLF na regra padrão de e-mail. Os fluxos expostos receberam mitigação explícita e testes, mas o pacote continua afetado segundo o catálogo. A atualização para uma linha suportada e para a stack da Entrega 00 permanece necessária.
2. **Limite da consulta de advisories:** `composer audit` não conseguiu acessar a API Packagist neste ambiente (proxy respondeu 403 ao CONNECT). A análise alternativa acima foi executada; ela não substitui a cobertura completa da API ou uma auditoria de segurança independente. `npm audit` final retornou zero vulnerabilidades.
3. **Chave anteriormente versionada:** ambientes que reutilizaram a chave exposta devem rotacioná-la e invalidar sessões conforme o procedimento de implantação. Esta auditoria remove o arquivo do novo estado do Git, preserva o arquivo local e não reescreve o histórico remoto nem modifica produção.
4. **E-mail:** o fluxo de recuperação foi testado com notificações simuladas/transporte em memória. Entrega efetiva depende de `APP_URL` e SMTP configurados. Não foram enviados e-mails externos.
5. **Stack e UX:** Blade permanece no lugar de Vue/Inertia/TypeScript. O fluxo principal foi exercitado em Chromium; não houve matriz completa de navegadores, acessibilidade ou dispositivos, nem migração visual da Entrega 11.
6. **Realismo esportivo:** os testes detectam regressões amplas, mas não certificam calibração com campeonatos reais. Abandono por menos de sete atletas é suportado; atualmente o resultado oficial conserva o placar gerado, sem adjudicação automática de W.O. Acúmulo de amarelos e regulamento de W.O. não foram inventados nesta auditoria. A política de suspensão vigente aplica uma partida por expulsão.
7. **Escala e resiliência:** concorrência foi testada com dois processos reais por carreira; não houve teste prolongado de carga, failover de infraestrutura ou encerramento forçado do servidor de banco. Falhas transacionais e retomada após desconexão foram testadas por injeção controlada e reservas persistidas. A progressão continua síncrona para o universo de dez clubes.
8. **Escopo futuro:** previsão de público dinâmico, preços efetivos, lançamentos financeiros, obras, evolução de torcida e temporada seguinte continuam nas Entregas 07–10. Recomendações iniciais da IA não executam essas ações. O encerramento esportivo termina no estado de espera pela próxima temporada.

## Revisão para publicação

Diff de código, migração, views, testes, dependências e documentação revisado; nenhum snapshot de banco, log, arquivo temporário, credencial nova ou build gerado deve integrar o commit. A única alteração de `.env` é sua exclusão do controle de versão. O lockfile contém apenas metadados públicos. Publicação destinada exclusivamente à branch corrente `main`, por push normal, sem reescrita de histórico e sem alterações em outras branches.
