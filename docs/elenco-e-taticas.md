# Elenco e táticas — Entrega 03

## Usar

Com as dependências instaladas e a conexão de desenvolvimento configurada, execute `php artisan migrate --seed`. Na máquina de nuvem, ative antes `source /workspace/.cloud-tools/football-env.sh`.

Abra uma carreira e siga **Gerenciar elenco e táticas**. A tela apresenta todos os jogadores do clube humano, suas posições principais/secundárias, idade, condição física, moral, disponibilidade e os 11 atributos do RF05.

Escolha uma formação, selecione os titulares e as reservas e configure mentalidade, pressão, ritmo e construção de jogadas. O botão **Salvar escalação e táticas** confirma o conjunto inteiro na mesma transação. A seleção permanece após logout/login.

Abrir a tela não grava nada. Quando ainda não existe uma escalação salva, é apresentada uma sugestão baseada nas posições dos atletas disponíveis, sujeita à confirmação do usuário. Trocar a formação prepara outra sugestão e descarta alterações ainda não salvas no formulário, sem modificar o que está persistido.

## Formações e táticas

| Formação | Posições dos titulares, na ordem das vagas |
| --- | --- |
| 4-4-2 | GK, LB, CB, CB, RB, LW, CM, CM, RW, ST, ST |
| 4-3-3 | GK, LB, CB, CB, RB, DM, CM, CM, LW, ST, RW |
| 4-2-3-1 | GK, LB, CB, CB, RB, DM, DM, LW, AM, RW, ST |
| 3-5-2 | GK, CB, CB, CB, LW, CM, DM, CM, RW, ST, ST |

LW/RW representam também os jogadores abertos do meio-campo nas formações 4-4-2 e 3-5-2. Um jogador de linha pode ser colocado fora de suas posições principal/secundárias, com aviso visível. Um goleiro deve ocupar a primeira vaga; as outras dez exigem jogadores de linha.

| Campo persistido | Opções |
| --- | --- |
| `mentality` | `defensive`, `balanced`, `attacking` |
| `pressure` | `low`, `normal`, `high` |
| `tempo` | `slow`, `normal`, `fast` |
| `build_up` | `short`, `balanced`, `direct` |

A configuração inicial sugerida é equilibrada, pressão normal, ritmo normal e construção equilibrada. Os dados persistidos ficam prontos para consumo pelo motor da Entrega 04. Esta entrega não simula partidas, não aplica efeitos estatísticos às táticas e não altera atributos por jogar fora da posição. Estatísticas esportivas e substituições durante partidas dependem das próximas entregas.

## Validações

- Exatamente 11 titulares, na ordem das vagas da formação.
- Jogadores distintos em todo o conjunto de titulares e reservas.
- Apenas atletas do clube humano na carreira autorizada; IDs de outro clube, outra carreira ou inexistentes são rejeitados.
- Goleiro na primeira vaga e dez jogadores de linha nas demais.
- Reservas opcionais, até o limite congelado nas regras da edição atual da competição. Vagas vazias são descartadas.
- Atletas com condição física zero, lesão ativa ou suspensão pendente não podem ser titulares nem reservas.
- `injured_until` é uma data interna inclusiva: o jogador fica indisponível até esse dia. `suspended_matches` indica partidas de suspensão ainda pendentes. A consulta usa `careers.current_date`, nunca o relógio real.
- Todas as formações e opções táticas são validadas no backend.

O limite inicial é **sete reservas**. A migração acrescenta `competition_rulesets.max_substitutes` (padrão 7; validação de 0 a 12), sem alterar o arquivo publicado de dados-base `v1.php` nem seu checksum. Novas carreiras copiam a regra para a edição da liga; edições existentes sem essa chave recebem 7. Valores já presentes são preservados. Alterar a regra no modelo-base não redefine o limite das carreiras já criadas.

As flags de lesão/suspensão ficam preparadas para o motor e para os serviços de calendário futuros. Não há controles na interface para o usuário remover essas condições, nem processamento automático de recuperação/suspensão nesta entrega.

## Persistência, autorização e concorrência

- `career_lineups` mantém uma configuração por clube, com formação, táticas e revisão.
- `career_lineup_players` mantém o jogador, a unidade (`starter` ou `substitute`) e o índice da vaga.
- Restrições únicas impedem repetição de jogador e de vaga. Chaves estrangeiras compostas exigem que escalação e jogador pertençam ao mesmo clube e carreira.
- As rotas GET/PUT `/careers/{career}/squad` exigem sessão autenticada e autorização por proprietário. O clube é derivado no servidor; IDs enviados para substituir o clube ou a carreira não são utilizados.
- `SaveSquad` também verifica autorização quando chamado diretamente. Bloqueia a linha da carreira e os atletas selecionados, valida novamente a disponibilidade e salva configuração e escolhas atomicamente. Serviços futuros de progressão devem compartilhar o bloqueio da carreira.
- O formulário envia `revision`. Salvar aumenta esse número; uma aba antiga não pode sobrescrever a nova configuração. Em caso de conflito, use **Recarregar dados salvos** antes de editar novamente.
- Edição permitida nos estados `awaiting_decision`, `pre_match` e `awaiting_season`; bloqueada em `processing` e `awaiting_match`.
- Abrir ou salvar a escalação não avança a data interna, não executa partidas e não altera dados de outras carreiras.

A configuração é o plano atual do clube. Snapshots imutáveis de escalações e táticas usadas em cada partida serão integrados ao fluxo do motor; esta tabela não deve substituir o histórico de partidas.

## Testes e migração

```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

A suíte completa tem **55 testes**, aprovados em SQLite e MySQL 8.0.46. Os 20 testes desta entrega cobrem formações, opções táticas, persistência, retomada, quantidade de atletas, duplicações, disponibilidade, pertencimento, autorização, limite de reservas, entradas malformadas, conflitos de revisão, estados bloqueados, rollback após falha e integridade do banco.

Um fluxo HTTP com sessão e CSRF validou a troca de formação, a seleção de 11 titulares e 7 reservas, o salvamento das quatro opções táticas e a retomada após logout/login, sem avanço da data interna.

Rollback/reaplicação foram verificados nos dois bancos, com carreiras existentes, comparando os dados dos universos e validando o preenchimento da nova regra. O rollback da migração desta entrega remove escalações, escolhas, flags de disponibilidade e a coluna nova dos modelos de regras. Ele preserva os universos das carreiras e os snapshots históricos de regras das competições. Execute testes de rollback somente em bancos descartáveis.
