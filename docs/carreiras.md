# Carreiras — Entrega 02

## Usar pelo navegador

Na raiz do projeto, com a conexão de desenvolvimento configurada:

```bash
composer install
php artisan migrate --seed
php artisan serve
```

Na máquina de nuvem preparada neste projeto, ative primeiro `source /workspace/.cloud-tools/football-env.sh` e inicie com `php artisan serve --no-reload --host=127.0.0.1 --port=8000`, para preservar a configuração do PHP local no processo servidor.

A página inicial permite criar uma conta ou entrar. Em **Minhas carreiras**, escolha **Nova carreira**, informe o nome da carreira, o treinador e o clube. Após criar, a página apresenta a data interna, o estado, o saldo, o estádio, o elenco e o calendário. A listagem permite retomar qualquer carreira do usuário. A leitura e o login não avançam o calendário.

As telas desta entrega usam Blade e sessões, compatíveis com o Laravel atual. Cadastro, login e logout mínimos foram acrescentados para viabilizar esse fluxo. Recuperação de senha, a migração da stack para Vue/Inertia e a interface completa do MVP continuam nas entregas de fundação/interface. Não há escolha de dificuldade nesta etapa, pois ainda não existem modificadores de dificuldade implementados.

## Universo particular

`App\Application\Careers\CreateCareer` instancia tudo na mesma transação:

- Carreira com proprietário, nome, treinador, versão dos dados-base, data interna, estado e número da temporada atual.
- Membership do proprietário e dez clubes próprios, sem compartilhamento de estado com as tabelas-base ou outras carreiras.
- Um controlador humano para o clube selecionado e nove controladores de IA; esta entrega registra o controle, não implementa a inteligência artificial.
- 200 jogadores com cópias de seus atributos, condição física e moral.
- Dez estádios, dez registros de torcida e saldos/ingressos iniciais em centavos.
- Primeira temporada, edição da liga com snapshot das regras, dez inscrições e 90 partidas agendadas.

As tabelas são `careers`, `career_memberships`, `career_clubs`, `career_club_controllers`, `career_players`, `career_stadiums`, `career_club_support`, `career_seasons`, `competition_editions`, `competition_entries` e `matches`.

O identificador do clube humano fica persistido em `career_club_controllers.career_club_id`, acessível por `Career::controlledClub()->club`; não há ponteiro redundante na tabela `careers`. A temporada atual é identificada por `(career_id, current_season_number)`. As chaves compostas bloqueiam vínculos de jogadores, estádios, torcida, controle, competição ou partidas com clubes de outra carreira.

Os dados-base e sua versão continuam referenciados para rastreabilidade. Os valores usados no jogo são cópias, incluindo as regras da competição; edições futuras da base ou reexecução do seeder não redefinem carreiras existentes.

## Calendário e estado

A configuração `config/careers.php` estabelece a data inicial **01/01/2026**, a primeira rodada 13 dias depois e intervalos de sete dias entre rodadas. São datas do universo fictício, independentes do dia real da criação. A primeira temporada possui 18 rodadas, com cinco jogos por rodada, nove mandos e nove visitas para cada clube.

O calendário inicial usa o método de rotação com turno e returno. É a agenda persistente necessária à criação do universo do RF02. A Entrega 06 integrou comandos de progressão, jogos oficiais da IA e do usuário, classificação, artilharia e encerramento esportivo. Consulte [competições e progressão](competicoes-e-progressao.md). Temporadas seguintes pertencem à Entrega 10.

`CareerState` define `awaiting_decision`, `processing`, `pre_match`, `awaiting_match` e `awaiting_season`. Uma nova carreira começa em `awaiting_decision`. Não existem cron jobs, workers ou efeitos de leitura que avancem a data. Ações confirmadas persistem seus resultados transacionalmente nessas entidades; não há endpoint para sobrescrever arbitrariamente o estado enviado pelo navegador.

## Integridade, acesso e retomada

- Todas as rotas de carreiras exigem autenticação de sessão. A listagem filtra o proprietário e `CareerPolicy` autoriza a abertura individual. Outra pessoa recebe 403. Memberships preparam a estrutura futura, mas não concedem acesso multiplayer nesta etapa.
- O backend determina o proprietário, a versão, a data, o estado, os saldos e os adversários. Campos adicionais forjados na requisição não são usados.
- `POST /careers` recebe `name`, `coach_name`, `club_template_id` e `request_id` (UUID). O formulário gera o UUID. Uma nova carreira exige uma chave nova.
- Repetir uma solicitação com a mesma chave e os mesmos dados retorna a carreira já criada. Reutilizar a chave com dados diferentes é rejeitado. A chave é única por usuário; o bloqueio da linha do proprietário serializa suas criações e a restrição única protege contra duplicações.
- Se houver falha durante a criação, a transação reverte todo o universo. A mesma solicitação poderá ser tentada novamente. Não é necessário um worker para completar uma criação parcial.
- A retomada consulta o último estado persistido no banco, inclusive após logout e passagem de tempo real. A sessão autentica o usuário; ela não armazena o progresso do jogo.
- Formulários usam CSRF, senhas são armazenadas com hash, a sessão é regenerada ao autenticar e invalidada no logout. Login e cadastro compartilham o limite de seis solicitações por minuto por IP. Saídas do usuário são escapadas pelo Blade.

## Validação

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

A suíte também pode rodar em um banco MySQL 8 **descartável e exclusivo para testes**, definindo as variáveis de conexão. `RefreshDatabase` recria as tabelas; não selecionar um banco com dados a preservar. Cache e sessões dos testes são isolados em memória, inclusive quando o shell exporta configurações de desenvolvimento.

Validação desta entrega: 35 testes aprovados em SQLite e MySQL 8.0.46, cobrindo cópias do universo, calendário, autorização, isolamento, retomada após logout e passagem de anos reais, idempotência, rollback por falha injetada, vínculos entre carreiras, campos forjados, escape de HTML, cadastro/login/logout, CSRF e limitação de tentativas. Um fluxo HTTP real com sessão e CSRF também validou cadastro, criação, repetição da requisição e retomada. Rollback e reaplicação da migração foram verificados nos dois bancos.
