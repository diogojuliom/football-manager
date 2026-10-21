# PRD — Football Manager Web

## MVP — Simulador de Futebol com Gestão de Clubes e Estádios

**Versão:** 2.1
**Data de atualização:** 15/10/2026
**Status:** Especificação para desenvolvimento
**Stack:** Laravel 13, PHP 8.3+, Vue 3, TypeScript, Inertia.js, MySQL 8
**Modalidade:** Single-player, com arquitetura preparada para multiplayer
**Motor de partidas:** Simulação intermediária baseada em eventos e probabilidades
**Arquitetura:** Monólito modular
**Progressão temporal:** Calendário diário interno, controlado pelo usuário, com avanço prioritário até a próxima partida

---

# 1. Visão geral

## 1.1. Objetivo

Desenvolver um jogo de gerenciamento de futebol executado diretamente no navegador, inspirado nas mecânicas de jogos como Brasfoot, com identidade visual, código-fonte e regras de simulação próprios.

O usuário assumirá o comando de um clube, podendo gerenciar elenco, escalações, estratégias, partidas, campeonatos, estádio e finanças.

O jogo deverá oferecer uma experiência intuitiva, dinâmica e estrategicamente interessante, sem exigir conhecimento avançado sobre gestão de futebol.

A evolução do clube deverá depender tanto do desempenho esportivo quanto das decisões administrativas do usuário.

## 1.2. Diretriz central do MVP

**Minha recomendação é incluir essas funcionalidades como parte oficial do MVP, mas começar com um sistema financeiro simples: saldo do clube, receitas de bilheteria, despesas operacionais e investimentos em infraestrutura. Assim o gerenciamento de estádio terá consequências reais, sem precisarmos implementar todo um sistema econômico de uma vez.**

Essa diretriz é obrigatória para a definição do escopo.

O estádio não deverá ser apenas uma funcionalidade visual. Ele fará parte efetiva da economia e da estratégia esportiva do clube.

## 1.3. Princípios de desenvolvimento

1. Priorizar um single-player completamente funcional.
2. Permitir múltiplas carreiras independentes por usuário.
3. Separar regras esportivas, inteligência artificial, finanças e persistência.
4. Desenvolver um motor de simulação próprio e determinístico.
5. Garantir que as decisões do usuário tenham consequências mensuráveis.
6. Permitir várias temporadas consecutivas.
7. Manter a aplicação acessível e fácil de utilizar.
8. Preparar as entidades e serviços para multiplayer futuro, sem implementar funcionalidades multiplayer no MVP.
9. Desenvolver código testável, modular e sustentável.
10. Utilizar um calendário diário interno, com progressão exclusivamente controlada pelo usuário.
11. Priorizar a ação de avançar diretamente até a próxima partida, sem exigir interação diária.
12. Não avançar o tempo da carreira automaticamente enquanto o usuário estiver desconectado.

---

# 2. Escopo funcional do MVP

## 2.1. Configuração inicial

O MVP deverá disponibilizar:

| Elemento                      | Configuração                                |
| ----------------------------- | ------------------------------------------- |
| Modalidade                    | Single-player                               |
| Liga inicial                  | Uma liga fictícia                           |
| Clubes                        | 10                                          |
| Jogadores por clube           | Aproximadamente 20                          |
| Formato                       | Pontos corridos, turno e returno            |
| Rodadas por temporada         | 18                                          |
| Partidas por temporada        | 90                                          |
| Clubes controlados pela IA    | 9                                           |
| Clube controlado pelo usuário | 1                                           |
| Progressão temporal           | Calendário diário interno                   |
| Ação principal de progressão  | Próxima partida                             |
| Progressão offline automática | Não permitida                               |
| Temporadas                    | Consecutivas                                |
| Estádios                      | Um estádio por clube                        |
| Finanças                      | Saldo, bilheteria, despesas e investimentos |

Os clubes e jogadores deverão ser fictícios inicialmente.

O uso futuro de clubes, escudos, nomes e informações reais deverá observar os direitos de utilização desses conteúdos.

## 2.2. Módulos obrigatórios

| Código | Módulo                                  | MVP |
| ------ | --------------------------------------- | --- |
| M01    | Autenticação e usuários                 | Sim |
| M02    | Criação e gerenciamento de carreiras    | Sim |
| M03    | Clubes e jogadores                      | Sim |
| M04    | Escalação e táticas                     | Sim |
| M05    | Motor de simulação de partidas          | Sim |
| M06    | Inteligência artificial dos adversários | Sim |
| M07    | Campeonatos e calendário                | Sim |
| M08    | Classificação e estatísticas            | Sim |
| M09    | Gerenciamento de estádios               | Sim |
| M10    | Precificação de ingressos               | Sim |
| M11    | Demanda dinâmica de torcedores          | Sim |
| M12    | Obras e ampliação de estádios           | Sim |
| M13    | Gestão financeira simplificada          | Sim |
| M14    | Progressão entre temporadas             | Sim |
| M15    | Dashboard e histórico do clube          | Sim |
| M16    | Progressão temporal diária controlada   | Sim |

---

# 3. Autenticação e carreiras

## RF01 — Usuários

O sistema deverá permitir cadastro, login, logout e recuperação de acesso.

Cada usuário poderá possuir diversas carreiras independentes.

## RF02 — Criação de carreira

Ao criar uma carreira, o usuário deverá informar:

- Nome do treinador.
- Nome da carreira.
- Clube que deseja administrar.
- Dificuldade inicial, quando aplicável.

A criação deverá instanciar um universo completo contendo clubes, jogadores, estádios, finanças iniciais, competição e calendário.

Cada carreira deverá ser isolada de todas as demais.

## RF03 — Salvamento

O jogo deverá salvar automaticamente o progresso das ações confirmadas.

O usuário poderá sair e retornar posteriormente, retomando o último estado consistente e persistido da carreira.

Não poderá ocorrer compartilhamento acidental de elencos, resultados, finanças ou infraestrutura entre carreiras.

O salvamento deverá incluir a data interna, o estado do calendário, partidas, obras, finanças, escalações e acontecimentos já processados.

Uma operação explicitamente iniciada antes da desconexão poderá ser concluída pelo servidor, desde que não desencadeie novas progressões não autorizadas.

## RF04 — Progressão temporal da carreira

A progressão temporal deverá utilizar um **calendário diário como motor interno**, mas a experiência principal do usuário deverá ser **avançar diretamente até a próxima partida do clube que administra**.

Cada carreira possuirá seu próprio calendário, completamente independente do relógio real e das demais carreiras.

### 3.1. Regras obrigatórias de progressão

1. Cada carreira possuirá uma data interna independente, armazenada e controlada pelo backend.
2. O calendário deverá evoluir cronologicamente, processando os acontecimentos correspondentes a cada dia.
3. A ação principal do usuário será **"Próxima partida"**, avançando automaticamente até a data do próximo compromisso do seu clube, sem exigir avanços manuais intermediários.
4. O usuário também poderá utilizar as opções **"Avançar 1 dia"** e **"Próximo acontecimento"**.
5. Durante o avanço, o sistema deverá processar recuperação física, obras, partidas dos clubes controlados pela IA e demais acontecimentos programados, respeitando suas datas.
6. Ao alcançar a data de uma partida do clube do usuário, a progressão deverá ser interrompida no pré-jogo, permitindo revisar escalação, táticas e ingressos antes de iniciar a simulação.
7. **O tempo da carreira somente poderá avançar mediante uma ação explícita do usuário.**
8. **O jogo não deverá avançar automaticamente enquanto o usuário estiver desconectado ou com o navegador fechado.** Não deverão existir cron jobs, workers ou agendamentos capazes de iniciar avanços de calendário por conta própria.
9. Ao retornar ao jogo, o usuário deverá encontrar a carreira no último estado persistido, independentemente do tempo real transcorrido.
10. O avanço deverá ser transacional, idempotente e protegido contra processamento concorrente ou duplicado.
11. O fechamento do navegador durante uma operação já autorizada não deverá provocar perda de dados nem resultados duplicados. O sistema deverá concluir ou recuperar essa operação de maneira segura, sem iniciar novos avanços não solicitados.
12. Essa regra se aplica ao single-player do MVP. No futuro multiplayer, o avanço poderá depender da confirmação dos participantes, preservando o mesmo calendário interno.

### 3.2. Modalidades de progressão

**Avançar 1 dia**

O sistema deverá avançar exatamente um dia do calendário interno, processando os acontecimentos correspondentes.

Caso o próximo dia contenha uma partida do clube do usuário, deverá parar no pré-jogo.

**Próxima partida**

Esta será a ação principal, visualmente destacada no dashboard.

O sistema deverá identificar a próxima partida do clube controlado pelo usuário e avançar automaticamente até sua data.

Todos os acontecimentos intermediários deverão ser processados em ordem cronológica.

A progressão deverá parar antes do início da partida, aguardando a confirmação do usuário.

**Próximo acontecimento**

O sistema deverá identificar o próximo acontecimento relevante da carreira.

Inicialmente, serão considerados acontecimentos relevantes:

- Partida do clube controlado pelo usuário.
- Conclusão de obra do estádio.
- Encerramento da temporada.
- Evento administrativo que exija uma decisão do usuário.

Acontecimentos meramente informativos, como a recuperação física diária, não deverão interromper essa modalidade.

O avanço sempre deverá respeitar os pontos de parada obrigatórios, inclusive quando o usuário solicitar a progressão até um acontecimento posterior.

### 3.3. Processamento diário

O calendário deverá funcionar como uma sequência de datas internas.

Em cada dia processado, o sistema deverá identificar e executar os acontecimentos aplicáveis, incluindo:

- Recuperação física dos atletas.
- Atualização do estado das obras.
- Conclusão de projetos com prazo atingido.
- Preparação administrativa das partidas.
- Definição de preços pelos clubes controlados pela IA.
- Simulação de jogos agendados entre clubes da IA.
- Atualização de resultados e classificações.
- Lançamentos financeiros decorrentes dos acontecimentos.
- Atualização do engajamento da torcida.
- Verificação de encerramentos e transições de temporada.

As regras deverão distinguir acontecimentos diários de acontecimentos executados apenas em situações específicas.

Não deverão existir lançamentos financeiros, alterações físicas ou mudanças de estado artificiais simplesmente porque o usuário avançou o calendário.

### 3.4. Exemplo de progressão

Considere uma carreira cuja data atual seja 01/08/2027, com a próxima partida marcada para 07/08/2027.

O usuário poderá ajustar sua equipe, definir os ingressos e iniciar uma obra em 01/08.

Ao selecionar "Próxima partida", o sistema deverá processar os dias intermediários:

- 02/08: recuperação física e atualização de obras.
- 03/08: acontecimentos programados e decisões aplicáveis da IA.
- 04/08: processamento de partidas de outros clubes, se houver.
- 05/08: atualização dos estados esportivos e administrativos.
- 06/08: recuperação física e avanço dos projetos.
- 07/08: chegada ao pré-jogo do clube do usuário.

A simulação da partida de 07/08 não deverá ser iniciada automaticamente.

O usuário deverá poder revisar suas decisões antes de clicar em **"Jogar partida"**.

### 3.5. Preparação e execução das partidas

No dia de uma partida do clube controlado pelo usuário, o sistema deverá manter a carreira no estado de pré-jogo.

O usuário poderá:

- Revisar os titulares.
- Alterar reservas.
- Ajustar formação e táticas.
- Consultar a condição física.
- Conferir a previsão de público.
- Alterar o preço dos ingressos, quando for mandante.

Ao confirmar "Jogar partida", o sistema deverá:

1. Validar a escalação.
2. Consolidar o preço dos ingressos.
3. Determinar o público realizado.
4. Congelar os dados de entrada.
5. Executar a simulação.
6. Registrar placar e eventos.
7. Atualizar estatísticas e classificação.
8. Aplicar desgaste físico.
9. Registrar receitas e despesas.
10. Atualizar o engajamento da torcida.
11. Apresentar o pós-jogo.

A carreira deverá permanecer na data da partida até que o usuário solicite uma nova progressão.

Quando houver outros jogos agendados para o mesmo dia, o sistema deverá processá-los segundo uma ordem determinística, respeitando a integridade da rodada. Jogos da IA necessários à consolidação da rodada poderão ser concluídos durante o processamento autorizado da partida do usuário.

Nenhum confronto já concluído poderá ser simulado novamente.

### 3.6. Progressão sem conexão

**O calendário da carreira não deverá avançar com base no tempo real transcorrido.**

Exemplo:

O usuário encerra sua sessão com a carreira em 10/08/2027.

Ele retorna ao jogo sete dias reais depois.

A carreira deverá continuar em 10/08/2027, com os mesmos estados persistidos.

Não deverão ocorrer automaticamente durante sua ausência:

- Jogos.
- Recuperação física.
- Obras.
- Receitas.
- Despesas.
- Alterações administrativas.
- Transições de temporada.

A única exceção é a conclusão ou recuperação segura de uma operação que o usuário já tenha solicitado antes da desconexão.

O simples acesso à aplicação, login ou abertura do dashboard não deverá provocar progressão temporal.

### 3.7. Segurança e integridade da progressão

Cada solicitação deverá possuir identificação de idempotência.

O backend deverá impedir duas progressões simultâneas na mesma carreira.

Operações críticas deverão utilizar transações, bloqueios e mecanismos de recuperação apropriados.

Falhas durante o avanço não poderão gerar duplicação de resultados, lançamentos financeiros, recuperação física ou conclusão de obras.

O processamento de grandes intervalos poderá ser otimizado, desde que produza resultados equivalentes ao processamento cronológico diário.

A mudança de data deverá refletir apenas acontecimentos efetivamente confirmados e persistidos.

---

# 4. Gerenciamento esportivo

## RF05 — Jogadores e atributos

Cada atleta deverá possuir atributos individuais de 0 a 100:

- Defesa.
- Desarme.
- Passe.
- Criatividade.
- Finalização.
- Velocidade.
- Resistência.
- Disciplina.
- Controle emocional.
- Bolas paradas.
- Habilidade de goleiro.

Também deverá possuir idade, posição principal, posições secundárias, condição física, moral e estatísticas esportivas.

O desempenho deverá considerar a posição ocupada e as condições da partida.

## RF06 — Escalação

O treinador poderá selecionar titulares e reservas, respeitando regras da competição.

Formações iniciais:

- 4-4-2.
- 4-3-3.
- 4-2-3-1.
- 3-5-2.

Deverão existir validações para impedir atletas duplicados, indisponíveis ou escalações inválidas.

## RF07 — Configurações táticas

O usuário poderá configurar:

**Mentalidade:** defensiva, equilibrada ou ofensiva.

**Pressão:** baixa, normal ou alta.

**Ritmo:** lento, normal ou rápido.

**Construção de jogadas:** passes curtos, equilibrada ou jogo direto.

As escolhas deverão influenciar o motor de partidas, com efeitos calibrados e coerentes.

---

# 5. Motor de simulação de partidas

## RF08 — Simulação intermediária

O motor deverá simular partidas cronologicamente, gerando eventos e resultados com base em regras probabilísticas.

Não deverá simplesmente sortear um placar final.

O resultado será consequência dos acontecimentos produzidos durante a simulação.

## 5.1. Fatores considerados

- Qualidade técnica dos jogadores.
- Força ofensiva e defensiva.
- Formação e estratégias.
- Condição física.
- Moral.
- Mando de campo.
- Apoio da torcida.
- Acontecimentos durante a partida.
- Aleatoriedade controlada.

## 5.2. Eventos

O motor deverá gerar, conforme as regras implementadas:

- Posse de bola.
- Construção de jogadas.
- Finalizações.
- Defesas.
- Gols.
- Assistências.
- Faltas.
- Cartões.
- Expulsões.
- Substituições.
- Desgaste físico.

## 5.3. Estatísticas

Cada partida deverá produzir:

| Estatística              | Obrigatória |
| ------------------------ | ----------- |
| Placar                   | Sim         |
| Posse de bola            | Sim         |
| Finalizações             | Sim         |
| Finalizações no gol      | Sim         |
| Gols esperados (xG)      | Sim         |
| Cartões                  | Sim         |
| Substituições            | Sim         |
| Estatísticas individuais | Sim         |
| Público presente         | Sim         |
| Taxa de ocupação         | Sim         |
| Receita de bilheteria    | Sim         |

## 5.4. Determinismo

Cada partida deverá utilizar uma seed e uma versão do motor.

A mesma entrada, com a mesma seed e versão das regras, deverá produzir o mesmo resultado.

O motor deverá funcionar independentemente de banco de dados, requisições HTTP e autenticação.

## 5.5. Influência do público

O apoio da torcida poderá produzir um pequeno bônus de mando de campo.

Esse bônus deverá considerar a ocupação e o engajamento da torcida, mas possuir limites rígidos.

Um estádio lotado poderá favorecer modestamente o mandante, sem substituir a qualidade dos atletas nem provocar resultados artificiais.

O público será determinado antes da simulação esportiva e não poderá ser recalculado em função do resultado final.

---

# 6. Inteligência artificial

## RF09 — Gestão dos clubes adversários

Os clubes não controlados pelo usuário deverão ser administrados automaticamente.

A IA deverá:

- Definir escalações.
- Selecionar formações.
- Considerar a condição dos atletas.
- Selecionar estratégias.
- Realizar substituições.
- Reagir ao contexto esportivo.

O MVP utilizará algoritmos de decisão baseados em regras, sem depender de serviços externos de inteligência artificial.

## RF10 — Gestão administrativa pela IA

Os clubes adversários também deverão:

- Definir preços de ingressos.
- Estimar a procura por bilhetes.
- Considerar obras quando houver recursos e demanda.
- Administrar despesas operacionais.
- Preservar a saúde financeira.

**A IA deverá obedecer às mesmas restrições financeiras e administrativas aplicadas ao clube controlado pelo usuário.**

Não será permitido conceder recursos ilimitados ou capacidade adicional aos clubes controlados pelo computador.

Todas as decisões da IA relacionadas ao calendário deverão ocorrer no tempo interno da carreira, exclusivamente durante progressões autorizadas.

---

# 7. Gerenciamento de estádios

## RF11 — Estádio do clube

Cada clube deverá possuir um estádio.

As informações mínimas serão:

| Campo                   | Descrição                      |
| ----------------------- | ------------------------------ |
| Nome                    | Nome do estádio                |
| Capacidade              | Total de lugares               |
| Capacidade disponível   | Lugares utilizáveis no momento |
| Nível de infraestrutura | Indicador de qualidade         |
| Custo operacional       | Despesa operacional básica     |
| Público médio           | Média histórica da temporada   |
| Ocupação média          | Percentual de lugares ocupados |
| Receita acumulada       | Arrecadação de bilheteria      |

Os estádios deverão fazer parte do universo particular de cada carreira.

## RF12 — Obras e ampliação

O usuário deverá poder iniciar projetos de ampliação.

Cada obra possuirá:

- Tipo.
- Custo.
- Capacidade adicional, quando aplicável.
- Data de início.
- Prazo.
- Data prevista de conclusão.
- Status.

Tipos iniciais:

**Ampliação de capacidade**

Aumenta a quantidade de lugares disponíveis após a conclusão.

**Modernização**

Eleva o nível de infraestrutura, podendo influenciar positivamente a atratividade e o conforto percebido pelos torcedores.

## 7.1. Regras de obras

1. O clube deverá possuir saldo suficiente para iniciar a obra.
2. O investimento será debitado quando o projeto for iniciado.
3. O projeto terá prazo de execução calculado no calendário da carreira.
4. O benefício será aplicado somente após sua conclusão.
5. O andamento ocorrerá automaticamente conforme os dias internos forem avançando.
6. O estádio terá no máximo uma obra ativa por vez no MVP.
7. Não haverá cancelamento nem financiamento de obras no MVP.
8. Custos e prazos serão configuráveis e sujeitos a balanceamento.
9. A conclusão deverá ocorrer uma única vez, inclusive quando houver falhas ou novas tentativas de progressão.
10. O tempo real transcorrido com o usuário desconectado não deverá influenciar o andamento das obras.

No MVP, a obra não reduzirá automaticamente a capacidade durante a construção. Essa possibilidade poderá ser implementada posteriormente.

## 7.2. Exemplo ilustrativo

Um clube com estádio de 20.000 lugares poderá iniciar uma ampliação para 25.000 lugares.

A obra custará determinado investimento e terá um prazo no calendário da carreira.

Durante a construção, a capacidade seguirá em 20.000 lugares.

Após a conclusão, a capacidade passará a 25.000.

O aumento não garantirá público adicional: dependerá da procura efetiva por ingressos.

Uma obra de 120 dias deverá exigir o avanço de 120 dias no calendário interno, e não 120 dias de tempo real.

---

# 8. Comportamento da torcida

## RF13 — Demanda dinâmica de torcedores

O sistema deverá possuir um motor de previsão e geração de público chamado, inicialmente, `MatchAttendanceService`.

O objetivo é calcular uma demanda plausível para cada partida.

A lógica deverá considerar fatores esportivos, econômicos e comportamentais.

## 8.1. Fatores de demanda

| Fator                  | Influência esperada                       |
| ---------------------- | ----------------------------------------- |
| Tamanho da torcida     | Determina a base potencial                |
| Fidelidade             | Mantém um núcleo de público               |
| Engajamento            | Modifica o interesse ao longo da carreira |
| Momento esportivo      | Boa fase aumenta a procura                |
| Classificação          | Disputas relevantes atraem público        |
| Adversário             | Clubes atrativos aumentam o interesse     |
| Rivalidade             | Clássicos recebem maior demanda           |
| Importância da partida | Jogos decisivos atraem mais               |
| Preço do ingresso      | Valores elevados podem reduzir procura    |
| Infraestrutura         | Modernização pode melhorar atratividade   |

## 8.2. Avaliação da importância da partida

O sistema deverá calcular um índice de importância de 0 a 100, considerando:

- Rivalidade entre clubes.
- Relevância do adversário.
- Proximidade da definição do campeonato.
- Situação do clube na classificação.
- Possibilidade matemática de disputar o título.
- Relevância do resultado para o objetivo esportivo atual.

Nas primeiras rodadas, a classificação deverá receber menor peso para evitar interpretações precipitadas.

Uma partida entre clubes que disputam o título nas rodadas finais deverá receber um índice maior que uma partida entre equipes sem objetivos relevantes naquele momento.

O modelo deverá funcionar também quando não houver rebaixamento na competição.

## 8.3. Comportamento por desempenho

O sistema deverá analisar o desempenho recente da equipe, utilizando uma janela móvel de partidas e a situação na tabela.

Regras esperadas:

- Sequências de vitórias tendem a elevar a demanda.
- Sequências de derrotas tendem a reduzi-la.
- Disputas de título elevam o engajamento.
- Resultados positivos aumentam gradualmente o interesse.
- Resultados negativos reduzem gradualmente o interesse.

As variações devem ser suavizadas para evitar oscilações excessivas após uma única partida.

Uma parte da torcida deverá permanecer fiel, mesmo em situações esportivas ruins.

## 8.4. Modelo matemático

Utilizar inicialmente um modelo parametrizado de demanda.

Estrutura conceitual:

`Demanda = DemandaBase × Fase × Importância × Rivalidade × Preço × Infraestrutura`

Os fatores representam multiplicadores configuráveis.

A demanda-base deverá considerar a torcida potencial e o engajamento, evitando dependência exclusiva da capacidade física do estádio.

A influência do preço deverá utilizar uma curva de elasticidade calibrável.

O modelo deverá admitir queda significativa da demanda em preços elevados, possibilitando que um ingresso muito caro produza receita total menor.

Os multiplicadores e seus limites deverão ser definidos em configuração versionada e calibrados em testes, evitando assumir valores arbitrários como definitivos.

## 8.5. Previsão e público realizado

A previsão será utilizada na interface para auxiliar a precificação.

O público realizado será consolidado imediatamente antes do início da partida, com a aplicação de uma variação probabilística controlada.

Regras:

- O público realizado nunca poderá ultrapassar a capacidade disponível.
- Não poderá ser negativo.
- Deverá ser persistido.
- Não poderá mudar após o início da partida.
- Não poderá ser influenciado retroativamente pelo resultado.
- Deverá compor o histórico do clube e do estádio.

## 8.6. Engajamento da torcida

Cada clube deverá possuir um indicador persistente de engajamento, atualizado progressivamente.

A atualização deverá considerar resultados, sequência esportiva e contexto competitivo.

O engajamento deverá possuir valores mínimos e máximos.

Sua evolução deverá ser gradual, preservando coerência entre temporadas.

O engajamento não deverá variar simplesmente porque o usuário ficou dias reais sem acessar o jogo.

---

# 9. Preço dos ingressos

## RF14 — Precificação

O treinador poderá configurar:

- Preço padrão dos ingressos.
- Preço específico de uma partida.
- Consulta da previsão de público.
- Consulta da previsão de receita bruta.

O MVP utilizará inicialmente um ingresso de preço único por partida, sem setores diferenciados.

## 9.1. Preço padrão

Cada clube terá um preço padrão persistido.

Quando não houver um valor específico, esse preço será utilizado automaticamente.

## 9.2. Preço por partida

O usuário poderá alterar o ingresso de uma partida futura em casa.

Não será permitido alterar o valor após o fechamento da preparação da partida.

## 9.3. Prévia financeira

A tela deverá atualizar a estimativa de público e receita conforme o preço informado.

Exemplo:

| Preço  | Público previsto | Receita bruta |
| ------ | ---------------: | ------------: |
| R$ 30  |           19.000 |    R$ 570.000 |
| R$ 50  |           16.000 |    R$ 800.000 |
| R$ 80  |           11.000 |    R$ 880.000 |
| R$ 120 |            5.000 |    R$ 600.000 |

Os valores são exemplos de comportamento econômico, não parâmetros definitivos do simulador.

O modelo deverá incentivar decisões conscientes, evitando que aumentar indefinidamente o preço seja sempre a melhor alternativa.

## 9.4. Precificação pela IA

Clubes controlados pelo computador deverão analisar demanda, capacidade e preços candidatos dentro de limites definidos.

A IA buscará um equilíbrio configurável entre arrecadação e ocupação.

A decisão será tomada antes da partida e registrada no histórico.

---

# 10. Gestão financeira simplificada

## RF15 — Finanças do clube

O MVP deverá implementar um sistema financeiro simples, integrado à administração do estádio.

As funcionalidades serão:

- Saldo inicial e atual.
- Receita bruta de bilheteria.
- Despesas operacionais dos jogos.
- Investimentos em obras.
- Histórico das transações.
- Saldo projetado após decisões administrativas.

## 10.1. Receitas

No MVP, a receita recorrente principal será a bilheteria das partidas realizadas como mandante.

A receita bruta será:

`ReceitaBruta = PúblicoRealizado × PreçoIngresso`

Não haverá receitas fictícias automáticas sem regras explícitas.

## 10.2. Despesas operacionais

Cada partida como mandante deverá produzir despesas operacionais.

O modelo inicial poderá considerar:

`DespesaOperacional = CustoFixo + (Público × CustoVariávelPorTorcedor)`

Os valores devem ser configuráveis por estádio ou nível de infraestrutura.

A receita líquida da partida será:

`ReceitaLíquida = ReceitaBruta - DespesaOperacional`

O modelo não deverá simular inicialmente folha salarial, impostos detalhados, contratos de patrocínio ou financiamentos.

## 10.3. Investimentos

A construção ou modernização deverá gerar uma despesa financeira no início da obra.

Nenhuma obra poderá ser iniciada se o saldo disponível não for suficiente.

## 10.4. Saldo do clube

O saldo será alterado por lançamentos financeiros rastreáveis.

O sistema deverá manter histórico de todas as operações, identificando origem, valor, data e vínculo com a partida ou obra.

A aplicação de receitas e despesas deverá ser idempotente.

Não poderá haver duplicação de arrecadação ao consultar novamente uma partida ou repetir uma solicitação.

## 10.5. Saldo negativo

Para evitar comportamentos inconsistentes, o MVP deverá diferenciar operações voluntárias de despesas obrigatórias.

Investimentos voluntários não poderão exceder o saldo disponível.

Despesas operacionais obrigatórias poderão gerar saldo negativo, mas o clube ficará impedido de iniciar novos investimentos enquanto não possuir recursos suficientes.

Empréstimos, renegociação de dívidas e falência não fazem parte do MVP.

Os dados iniciais e os custos deverão ser calibrados para evitar déficits sistemáticos inevitáveis.

## 10.6. Indicadores financeiros

A tela financeira deverá exibir:

- Saldo atual.
- Receita acumulada na temporada.
- Despesas acumuladas.
- Investimentos realizados.
- Resultado financeiro da temporada.
- Receita média por partida em casa.
- Histórico das movimentações.

Todas as operações financeiras vinculadas à progressão deverão utilizar a data interna da carreira como data de referência do jogo.

O horário real de processamento poderá ser armazenado separadamente para fins técnicos e auditoria, mas não deverá determinar acontecimentos financeiros.

---

# 11. Competições e temporadas

## RF16 — Campeonato

A liga inicial deverá possuir dez clubes e confrontos de ida e volta.

O calendário deverá gerar:

- 18 rodadas.
- 90 partidas.
- Nove mandos de campo para cada clube.
- Nove jogos como visitante para cada clube.

O sistema deverá impedir confrontos duplicados na mesma edição e inconsistências no calendário.

## RF17 — Classificação

A classificação deverá incluir pontos, vitórias, empates, derrotas, gols marcados, gols sofridos e saldo de gols.

Os critérios de desempate deverão ser configuráveis.

Os resultados oficiais serão a fonte de verdade para reconstrução da classificação.

## RF18 — Temporadas consecutivas

**A continuidade entre temporadas será obrigatória no MVP.**

Ao terminar uma temporada, o jogador poderá iniciar a seguinte sem perder o clube ou suas decisões administrativas.

Deverão ser preservados:

- Clube controlado.
- Saldo financeiro.
- Estádio.
- Ampliações e modernizações.
- Obras em andamento.
- Torcida potencial.
- Engajamento.
- Elenco.
- Histórico esportivo.
- Histórico financeiro.

## 11.1. Transição de temporada

O sistema deverá:

1. Consolidar a classificação.
2. Registrar o campeão.
3. Consolidar estatísticas e artilharia.
4. Encerrar a edição atual.
5. Processar ajustes anuais básicos dos atletas.
6. Garantir elencos mínimos válidos, utilizando geração automática de reposição quando necessário.
7. Gerar uma nova edição do campeonato.
8. Criar um novo calendário.
9. Preservar o estado financeiro e administrativo.
10. Permitir que o usuário continue jogando.

Não serão necessárias transferências complexas para realizar a transição.

O MVP deverá possibilitar testar pelo menos três temporadas consecutivas, sem sobrescrever o histórico das anteriores.

As regras de envelhecimento, eventual aposentadoria e reposição simplificada deverão ser determinísticas e documentadas.

## 11.2. Integração com o calendário interno

O encerramento da temporada deverá ocorrer durante uma progressão explicitamente autorizada pelo usuário.

O sistema não deverá iniciar a temporada seguinte automaticamente enquanto o usuário estiver desconectado.

Quando a temporada atual terminar, o jogador deverá poder consultar os resultados e o histórico antes de confirmar a continuidade.

A criação da próxima temporada deverá preservar a cronologia da carreira e impedir que dias ou acontecimentos sejam processados duas vezes.

---

# 12. Modelo de dados MySQL

A modelagem deverá separar dados-base, entidades de carreira, registros históricos e lançamentos financeiros.

## 12.1. Dados-base

| Tabela                   | Finalidade              |
| ------------------------ | ----------------------- |
| `club_templates`         | Clubes iniciais         |
| `player_templates`       | Jogadores iniciais      |
| `competition_templates`  | Competições disponíveis |
| `competition_rulesets`   | Regras de competição    |
| `stadium_templates`      | Estádios iniciais       |
| `game_database_versions` | Versionamento dos dados |

## 12.2. Carreiras e clubes

| Tabela                    | Finalidade                |
| ------------------------- | ------------------------- |
| `careers`                 | Carreiras                 |
| `career_memberships`      | Participantes autorizados |
| `career_clubs`            | Clubes instanciados       |
| `career_players`          | Jogadores da carreira     |
| `career_club_controllers` | Controle humano ou IA     |
| `career_seasons`          | Temporadas                |
| `career_progressions`     | Histórico de progressões  |

A tabela `careers` deverá possuir, no mínimo:

- Identificador.
- Usuário proprietário.
- Nome.
- Data interna atual (`current_date`).
- Estado atual da carreira.
- Temporada atual.
- Identificador do clube controlado.
- Versão dos dados-base utilizados.

A data interna deverá ser armazenada independentemente do horário real do servidor.

O estado da carreira deverá permitir identificar situações como:

- Aguardando decisão do usuário.
- Processando progressão autorizada.
- Aguardando pré-jogo.
- Aguardando conclusão de partida.
- Aguardando início da próxima temporada.

A tabela `career_progressions` deverá registrar, no mínimo:

- Carreira.
- Tipo de avanço solicitado.
- Data de origem.
- Data de destino solicitada, quando aplicável.
- Data efetivamente alcançada.
- Identificador de idempotência.
- Status.
- Informações necessárias à recuperação de falhas.

O projeto deverá definir um mecanismo inequívoco para impedir que acontecimentos de uma mesma data sejam executados novamente.

## 12.3. Partidas

| Tabela                 | Finalidade                  |
| ---------------------- | --------------------------- |
| `competition_editions` | Edições da liga             |
| `competition_entries`  | Participantes               |
| `matches`              | Confrontos e resultados     |
| `match_lineups`        | Escalações                  |
| `match_lineup_players` | Atletas escalados           |
| `match_tactics`        | Táticas                     |
| `match_events`         | Eventos                     |
| `match_team_stats`     | Estatísticas dos clubes     |
| `match_player_stats`   | Estatísticas individuais    |
| `standings`            | Classificação materializada |
| `season_player_stats`  | Estatísticas da temporada   |

## 12.4. Estádios e torcida

| Tabela                  | Finalidade                     |
| ----------------------- | ------------------------------ |
| `career_stadiums`       | Estado do estádio por carreira |
| `stadium_projects`      | Obras                          |
| `career_club_support`   | Torcida e engajamento          |
| `match_ticket_policies` | Preço dos ingressos            |
| `match_attendances`     | Previsão e público realizado   |

A estrutura `match_attendances` deverá permitir armazenar, no mínimo:

- Partida.
- Capacidade disponível.
- Público previsto.
- Público realizado.
- Ocupação.
- Índice de importância.
- Preço aplicado.
- Versão do algoritmo de demanda.

A capacidade e o preço utilizados deverão representar o estado efetivo da partida, preservando o histórico mesmo após reformas ou alterações posteriores.

## 12.5. Finanças

| Tabela                          | Finalidade                       |
| ------------------------------- | -------------------------------- |
| `career_financial_transactions` | Livro de lançamentos financeiros |

Cada lançamento deverá identificar:

- Carreira.
- Clube.
- Tipo de receita ou despesa.
- Valor.
- Data do jogo.
- Origem da transação.
- Identificador de idempotência.

O saldo poderá ser mantido como projeção otimizada, mas deverá permanecer conciliável com o saldo inicial e os lançamentos registrados.

## 12.6. Integridade

Implementar:

- Chaves estrangeiras.
- Índices por carreira e clube.
- Restrições únicas.
- Transações.
- Bloqueios para operações concorrentes.
- Validação de pertencimento dos registros à mesma carreira.
- Proteção contra processamento financeiro duplicado.
- Proteção contra progressões temporais duplicadas.
- Controle de acontecimentos processados por data interna.

A estrutura deverá permitir adicionar usuários controlando diferentes clubes da mesma carreira futuramente.

---

# 13. Arquitetura Laravel

O projeto deverá utilizar um monólito modular.

Estrutura conceitual:

```text
app/
  Domain/
    Career/
      Progression/
      Calendar/
      Events/
    Clubs/
    Players/
    Football/
      Engine/
      Calculators/
      Events/
      Rules/
    Competition/
    Tactics/
    Stadium/
      Services/
      Rules/
      Projects/
    Supporters/
      Attendance/
      Engagement/
    Finance/
      Ledger/
      Services/
    AI/
      Squad/
      Tactics/
      Pricing/
      Investment/

  Application/
    Careers/
    Progression/
    Matches/
    Seasons/
    Stadiums/
    Finance/

  Http/
    Controllers/
    Requests/
    Resources/

  Policies/

resources/
  js/
    pages/
      Careers/
      Dashboard/
      Squad/
      Tactics/
      Matches/
      Competition/
      Stadium/
      Finance/
    components/
    composables/
    types/

database/
  migrations/
  seeders/

tests/
  Unit/
  Feature/
  Integration/
  Simulation/
```

A organização poderá ser refinada durante a implementação, preservando os limites entre os módulos.

## 13.1. Serviços principais

| Serviço                     | Responsabilidade                   |
| --------------------------- | ---------------------------------- |
| `CreateCareerService`       | Criar e instanciar carreira        |
| `CareerProgressionService`  | Avançar o calendário               |
| `CareerCalendarService`     | Identificar datas e acontecimentos |
| `CareerDailyEventProcessor` | Processar acontecimentos diários   |
| `FixtureGeneratorService`   | Gerar confrontos                   |
| `FootballMatchEngine`       | Simular partidas                   |
| `AiLineupService`           | Escalação automática               |
| `AiTacticsService`          | Estratégias da IA                  |
| `MatchAttendanceService`    | Previsão e público                 |
| `FanEngagementService`      | Evolução do interesse da torcida   |
| `TicketPricingService`      | Preços e receitas previstas        |
| `AiTicketPricingService`    | Preços dos clubes da IA            |
| `StadiumProjectService`     | Iniciar e concluir obras           |
| `FinancialLedgerService`    | Registrar operações                |
| `MatchSettlementService`    | Consolidar partida e finanças      |
| `StandingsService`          | Atualizar classificação            |
| `SeasonLifecycleService`    | Gerenciar temporadas               |

Os nomes são sugestões arquiteturais, não uma exigência de implementação literal.

## 13.2. Ordem das operações de partida

O processamento deverá seguir uma ordem consistente:

1. Recuperar o estado da carreira.
2. Identificar as partidas agendadas.
3. Consolidar obras que já tenham sido concluídas na data.
4. Preparar escalações.
5. Definir preços e demanda dos ingressos.
6. Consolidar público.
7. Criar snapshot imutável da partida.
8. Executar o motor esportivo.
9. Persistir eventos e resultados.
10. Aplicar desgaste físico.
11. Registrar receitas e despesas.
12. Atualizar classificações e estatísticas.
13. Atualizar o engajamento da torcida.
14. Concluir a operação autorizada.

A aplicação deverá impedir que qualquer etapa seja executada duas vezes indevidamente.

O processamento deverá possuir estratégia de recuperação para falhas intermediárias, mantendo consistência transacional e evitando estados parcialmente aplicados.

## 13.3. Funcionamento do `CareerProgressionService`

O serviço deverá ser responsável exclusivamente por executar progressões previamente solicitadas e autorizadas.

Deverá receber:

- Identificador da carreira.
- Tipo de progressão.
- Usuário solicitante.
- Identificador de idempotência.
- Contexto necessário para a progressão.

Tipos iniciais de operação:

- `ADVANCE_ONE_DAY`
- `ADVANCE_TO_NEXT_MATCH`
- `ADVANCE_TO_NEXT_EVENT`

O serviço deverá:

1. Validar que o usuário possui autorização sobre a carreira.
2. Verificar se existe uma progressão em andamento.
3. Verificar se a solicitação já foi processada.
4. Identificar a data-alvo e os pontos de parada obrigatórios.
5. Processar os acontecimentos cronologicamente.
6. Persistir as mudanças em unidades transacionais consistentes.
7. Interromper o avanço no próximo pré-jogo ou acontecimento que exija decisão.
8. Atualizar a data interna.
9. Registrar a progressão.
10. Retornar o novo estado da carreira.

O sistema não poderá calcular a data atual da carreira a partir de `now()`, `Carbon::now()` ou equivalente.

Essas funções poderão ser utilizadas para registros técnicos e auditoria, mas nunca para determinar quanto tempo do jogo transcorreu.

## 13.4. Processamento em segundo plano

O MVP deverá priorizar a execução síncrona, considerando o volume inicial reduzido.

Poderão ser utilizados jobs ou workers para concluir operações explicitamente solicitadas, caso exista necessidade técnica.

Entretanto:

- Jobs não poderão iniciar avanços espontaneamente.
- Um job deverá pertencer a uma operação de progressão identificável.
- O fechamento do navegador não deverá cancelar uma operação já autorizada de maneira inconsistente.
- O processamento não poderá ultrapassar o ponto de parada definido.
- O acesso posterior à carreira deverá recuperar o estado confirmado da operação.
- Nenhum agendamento periódico deverá avançar carreiras automaticamente.

Essa separação deverá permitir adicionar mecanismos de processamento assíncrono futuramente, sem alterar as regras do calendário.

---

# 14. Interface Vue

## 14.1. Diretrizes de experiência

A interface deverá ser clean, moderna, responsiva e intuitiva.

Priorizar leitura rápida dos indicadores, navegação simples e poucas etapas para executar ações frequentes.

## 14.2. Telas obrigatórias

| Tela             | Funcionalidade                              |
| ---------------- | ------------------------------------------- |
| Minhas carreiras | Criar e continuar jogos                     |
| Nova carreira    | Seleção do clube                            |
| Dashboard        | Visão geral, data interna e próxima partida |
| Elenco           | Administração dos jogadores                 |
| Escalação        | Definição dos titulares                     |
| Táticas          | Estratégias                                 |
| Calendário       | Jogos e rodadas                             |
| Partida          | Resultado e eventos                         |
| Classificação    | Tabela do campeonato                        |
| Estatísticas     | Artilharia e indicadores                    |
| Estádio          | Capacidade, público e infraestrutura        |
| Obras            | Ampliação e modernização                    |
| Ingressos        | Preços e previsão de público                |
| Finanças         | Receitas, despesas e saldo                  |
| Histórico        | Resultados e temporadas anteriores          |

## 14.3. Dashboard e progressão

O dashboard deverá apresentar em destaque:

- Data interna atual da carreira.
- Temporada.
- Próxima partida.
- Adversário.
- Mando de campo.
- Dias internos restantes.
- Condição geral do elenco.
- Obras em andamento.
- Acontecimentos relevantes.

A ação principal deverá ser **"Próxima partida"**.

A interface também deverá disponibilizar as ações secundárias:

- "Avançar 1 dia".
- "Próximo acontecimento".

Durante uma progressão, o usuário deverá receber feedback visual adequado, evitando múltiplos envios acidentais.

Ao chegar à data da partida, a interface deverá apresentar o pré-jogo e substituir a ação principal por **"Jogar partida"**.

Após a conclusão, deverá apresentar o pós-jogo e permitir nova progressão.

A interface não deverá utilizar um relógio em tempo real para avançar automaticamente o calendário.

## 14.4. Tela do estádio

Deverá apresentar:

- Nome e imagem ilustrativa do estádio.
- Capacidade atual.
- Público médio.
- Ocupação média.
- Receita acumulada.
- Preço padrão dos ingressos.
- Próxima partida em casa.
- Estimativa de público.
- Obras disponíveis.
- Obras em andamento.
- Histórico de público e bilheteria.

## 14.5. Tela de ingressos

Deverá permitir ajustar o preço e consultar instantaneamente as estimativas de público e receita.

O cálculo da previsão deverá utilizar a mesma lógica de domínio aplicada no processamento oficial, sem fórmulas independentes no frontend.

## 14.6. Tela financeira

Deverá apresentar um painel simples, contendo saldo, receitas, despesas, investimentos e histórico de lançamentos.

O usuário deverá conseguir identificar claramente como a bilheteria e as obras impactaram o caixa.

---

# 15. Regras de segurança e concorrência

O backend será a autoridade sobre todas as operações oficiais.

O frontend não poderá determinar arbitrariamente resultados, receitas, capacidade ou valores financeiros.

Implementar:

- Autorização por carreira.
- Validação de dados.
- Proteção CSRF.
- Idempotência.
- Transações financeiras.
- Bloqueios para operações concorrentes.
- Auditoria de mudanças críticas.
- Prevenção de alterações em partidas concluídas.
- Prevenção de progressões simultâneas.
- Proteção contra execução repetida de acontecimentos diários.

A compra de uma obra deverá validar o saldo e realizar o débito de maneira atômica.

A simulação de uma partida deverá possuir resultado oficial único.

Uma requisição para avançar a carreira não poderá executar novamente um período já processado.

---

# 16. Preparação para multiplayer

A arquitetura deverá prever conceitualmente que diferentes usuários possam controlar clubes de uma mesma carreira no futuro.

Para isso:

1. Separar entidades de usuário, carreira e clube controlado.
2. Permitir representar controladores humanos e IA.
3. Não depender da sessão HTTP no motor de simulação.
4. Centralizar as ações em serviços de aplicação.
5. Manter processamento de partidas e finanças idempotente.
6. Preservar estados e eventos oficiais.
7. Permitir evolução posterior para processamento assíncrono.
8. Centralizar a progressão temporal por carreira.
9. Não acoplar o avanço do calendário a um usuário específico dentro do domínio.
10. Permitir que futuramente exista um mecanismo de confirmação dos participantes antes de avançar.

O MVP não deverá implementar salas, convites, matchmaking, WebSockets ou controle simultâneo entre usuários.

---

# 17. Plano de implementação

O desenvolvimento deverá ocorrer por entregas independentes e verificáveis.

## Entrega 00 — Fundação técnica

Implementar:

- Laravel, Vue, TypeScript e Inertia.
- Autenticação.
- Conexão MySQL.
- Estrutura modular.
- Ferramentas de testes.
- Layout inicial.
- Configuração do ambiente de desenvolvimento.

**Aceite:** aplicação funcional, ambiente reproduzível e testes iniciais aprovados.

## Entrega 01 — Dados-base e clubes

Implementar:

- Clubes fictícios.
- Jogadores.
- Atributos.
- Estádios iniciais.
- Definições de competições.
- Configurações financeiras iniciais.
- Seeds versionadas.

**Aceite:** universo inicial consistente, com dez clubes, seus respectivos elencos e estádios.

## Entrega 02 — Carreiras

Implementar:

- Criação.
- Instanciação do universo.
- Isolamento.
- Seleção de clube.
- Persistência.
- Recuperação do progresso.
- Autorização.
- Data interna inicial.
- Estado persistente da carreira.

**Aceite:** carreiras independentes poderão ser utilizadas sem interferência entre si e preservarão suas respectivas datas internas.

## Entrega 03 — Elenco e táticas

Implementar:

- Listagem de jogadores.
- Escalação.
- Reservas.
- Formações.
- Configurações táticas.
- Validações.

**Aceite:** escalações e táticas persistidas corretamente.

## Entrega 04 — Motor de partidas

Implementar:

- Motor determinístico.
- Probabilidades.
- Posse.
- Oportunidades.
- Finalizações.
- Gols.
- Cartões.
- Substituições.
- Fadiga.
- Estatísticas.

O motor deverá aceitar um contexto de mando de campo, permitindo integrar posteriormente a influência do público.

**Aceite:** partidas reproduzíveis, com eventos e estatísticas coerentes.

## Entrega 05 — IA dos clubes

Implementar:

- Escalação automática.
- Estratégias.
- Substituições.
- Reações durante a partida.
- Regras iniciais de administração dos clubes.

**Aceite:** duas equipes controladas pela IA poderão disputar partidas completas.

## Entrega 06 — Competições, calendário e progressão temporal

**Objetivo:** implementar o calendário esportivo e o mecanismo central de progressão das carreiras.

Esta entrega deverá observar obrigatoriamente todas as regras do RF04, especialmente o calendário diário interno, a progressão prioritária até a próxima partida e a ausência de avanço automático durante períodos de desconexão.

Implementar:

### 06.1. Competições

- Geração das rodadas.
- Agendamento dos confrontos.
- Classificação.
- Artilharia.
- Persistência dos resultados.
- Encerramento esportivo.

### 06.2. Calendário interno

- Campo `careers.current_date`.
- Datas internas independentes por carreira.
- Identificação de partidas e acontecimentos futuros.
- Processamento cronológico dos dias.
- Controle de acontecimentos processados.
- Estado persistente de pré-jogo.

### 06.3. Serviço de progressão

Implementar `CareerProgressionService` com as operações:

- `ADVANCE_ONE_DAY`
- `ADVANCE_TO_NEXT_MATCH`
- `ADVANCE_TO_NEXT_EVENT`

A operação `ADVANCE_TO_NEXT_MATCH` deverá ser a principal funcionalidade de progressão utilizada pela interface.

Ao utilizá-la, o sistema deverá avançar automaticamente pelos dias intermediários, processar acontecimentos aplicáveis e parar antes da próxima partida do clube do usuário.

### 06.4. Partidas e pré-jogo

- Identificar a próxima partida do usuário.
- Interromper o avanço no pré-jogo.
- Permitir ajustes finais de escalação e táticas.
- Exigir confirmação para executar a partida.
- Simular os confrontos da IA conforme a cronologia.
- Persistir resultados sem duplicação.
- Exibir os dados do pós-jogo.

### 06.5. Recuperação e segurança

- Implementar idempotência.
- Impedir progressões concorrentes.
- Garantir consistência após falhas.
- Recuperar operações autorizadas interrompidas.
- Preservar a data da carreira entre sessões.
- Impedir progressão baseada no relógio real.
- Não utilizar cron jobs para iniciar progressões automáticas.

### 06.6. Regras de UX

O dashboard deverá apresentar:

- Data interna.
- Próxima partida.
- Dias restantes.
- Botão principal "Próxima partida".
- Opções "Avançar 1 dia" e "Próximo acontecimento".

No dia da partida, deverá apresentar a ação "Jogar partida".

### Critérios de aceite da Entrega 06

A entrega somente será considerada concluída quando:

- [ ] A carreira possuir calendário interno persistente.
- [ ] O usuário conseguir avançar um dia.
- [ ] O usuário conseguir avançar até a próxima partida.
- [ ] O usuário conseguir avançar até o próximo acontecimento relevante.
- [ ] Os dias intermediários forem processados cronologicamente.
- [ ] Os jogos da IA forem processados nas datas aplicáveis.
- [ ] A progressão parar no pré-jogo do usuário.
- [ ] A partida exigir confirmação antes de sua execução.
- [ ] A carreira não avançar automaticamente após a desconexão.
- [ ] O retorno posterior ao jogo preservar o estado consistente da carreira.
- [ ] Duas solicitações simultâneas não produzirem progressões duplicadas.
- [ ] Uma operação interrompida puder ser recuperada com segurança.
- [ ] Uma temporada completa puder ser disputada com essa mecânica.

**Aceite final:** será possível disputar uma temporada completa utilizando a progressão até a próxima partida como principal experiência de jogo, sem progressão automática offline e ainda sem os recursos administrativos de estádio finalizados.

## Entrega 07 — Estádios e torcida

Implementar:

- Cadastro e estado dos estádios.
- Capacidade disponível.
- Torcida potencial.
- Fidelidade e engajamento.
- Importância das partidas.
- Previsão de demanda.
- Público realizado.
- Influência moderada do apoio da torcida.
- Histórico de público.

**Aceite:** cada partida em casa produzirá público coerente com sua demanda e capacidade.

## Entrega 08 — Ingressos e finanças

Implementar:

- Preço padrão.
- Preço por partida.
- Previsão de receita.
- Precificação dos clubes da IA.
- Lançamentos financeiros.
- Bilheteria.
- Despesas operacionais.
- Atualização do saldo.
- Painel financeiro.

**Aceite:** partidas em casa gerarão resultados financeiros auditáveis, e alterações de preço influenciarão a demanda.

## Entrega 09 — Obras e infraestrutura

Implementar:

- Ampliação.
- Modernização.
- Custos.
- Prazos.
- Status.
- Conclusão automática dentro de progressões autorizadas.
- Impacto na capacidade e infraestrutura.
- Investimentos dos clubes controlados pela IA.

**Aceite:** será possível financiar uma obra, avançar os dias internos e receber seu benefício somente após a conclusão, sem depender de tempo real.

## Entrega 10 — Temporadas consecutivas

Implementar:

- Consolidação de temporada.
- Preservação de histórico.
- Nova edição da liga.
- Novo calendário.
- Evolução básica do elenco.
- Persistência de estádio, torcida e finanças.
- Continuidade de obras.
- Integração com o calendário interno.
- Confirmação do usuário para iniciar a próxima temporada.

**Aceite:** será possível disputar no mínimo três temporadas sequenciais sem perda de dados nem reinicialização financeira ou administrativa.

## Entrega 11 — Interface e experiência

Implementar:

- Dashboard completo.
- Controles de progressão.
- Interface de pré-jogo.
- Interface de partidas.
- Estádio.
- Obras.
- Ingressos.
- Finanças.
- Estatísticas.
- Histórico de temporadas.
- Responsividade.

**Aceite:** todas as funcionalidades do MVP poderão ser utilizadas pela interface web sem operações manuais no banco.

## Entrega 12 — Balanceamento e estabilização

Executar:

- Testes automatizados.
- Testes de desempenho.
- Simulações estatísticas.
- Balanceamento esportivo.
- Balanceamento de demanda.
- Balanceamento financeiro.
- Testes de concorrência.
- Revisão de segurança.
- Testes de temporadas completas.
- Testes de progressão temporal.
- Testes de desconexão e retomada.
- Testes de recuperação de operações interrompidas.

**Aceite:** MVP integralmente jogável, consistente e validado.

---

# 18. Plano de testes

## 18.1. Motor esportivo

Executar milhares de partidas sintéticas para validar a distribuição dos resultados, desempenho das equipes, impacto tático, gols, cartões e estatísticas.

Não considerar o motor balanceado apenas por produzir médias aparentemente plausíveis. Validar também distribuições e cenários extremos.

## 18.2. Sistema de público

Testar cenários com:

- Clube em boa fase.
- Clube em má fase.
- Clássico.
- Adversário de baixa atratividade.
- Partida decisiva.
- Partida com baixa relevância.
- Ingressos baratos.
- Ingressos caros.
- Estádio com capacidade insuficiente.
- Estádio ampliado.
- Torcida muito engajada.
- Torcida pouco engajada.

Os resultados deverão respeitar limites físicos e comportamentos econômicos definidos.

## 18.3. Sistema financeiro

Validar:

- Receita correta.
- Despesa correta.
- Saldo conciliável.
- Débito de obras.
- Bloqueio de investimentos sem recursos.
- Ausência de lançamentos duplicados.
- Persistência financeira entre temporadas.
- Processamento seguro de despesas obrigatórias.

## 18.4. Obras

Validar:

- Prazo.
- Início.
- Conclusão.
- Aplicação de capacidade.
- Concorrência.
- Impacto entre temporadas.
- Idempotência da conclusão.
- Avanço exclusivo pelo calendário interno.
- Ausência de evolução pelo tempo real.

## 18.5. Temporadas

Automatizar pelo menos três temporadas completas.

Validar 90 partidas por temporada, integridade dos históricos e continuidade de elencos, estádios, finanças e engajamento.

## 18.6. Progressão temporal

Criar testes automatizados específicos para os seguintes cenários:

**Cenário 1 — Avançar um dia**

Dada uma carreira em 01/08/2027, ao solicitar "Avançar 1 dia", a data deverá passar para 02/08/2027 e os acontecimentos do período deverão ser processados uma única vez.

**Cenário 2 — Próxima partida**

Dada uma carreira em 01/08/2027, com próxima partida em 07/08/2027, a ação deverá processar os dias intermediários e parar no pré-jogo de 07/08/2027.

**Cenário 3 — Confirmação da partida**

Ao chegar ao pré-jogo, nenhuma partida do clube do usuário poderá ser simulada antes da confirmação "Jogar partida".

**Cenário 4 — Desconexão**

Dada uma carreira em 10/08/2027, sem operação em andamento, o usuário poderá desconectar e retornar posteriormente.

A carreira deverá permanecer em 10/08/2027, sem acontecimentos adicionais processados.

**Cenário 5 — Operação autorizada e navegador fechado**

Se o usuário solicitar uma progressão e fechar o navegador durante sua execução, o backend deverá concluir ou recuperar a operação sem duplicações e sem iniciar progressões adicionais.

**Cenário 6 — Requisições simultâneas**

Duas solicitações concorrentes para avançar a mesma carreira não poderão produzir datas inconsistentes ou acontecimentos duplicados.

**Cenário 7 — Obra em andamento**

Uma obra de 120 dias deverá avançar exclusivamente conforme a data interna da carreira.

O tempo real decorrido não deverá alterar seu progresso.

**Cenário 8 — Partidas da IA**

Jogos entre clubes da IA deverão ser simulados nas datas correspondentes durante as progressões autorizadas.

Não poderão ser repetidos ao consultar o calendário ou executar nova progressão.

**Cenário 9 — Recuperação física**

A recuperação deverá ocorrer conforme os dias internos processados, sem duplicações.

**Cenário 10 — Temporada seguinte**

A transição entre temporadas deverá preservar a cronologia e impedir que acontecimentos de uma temporada encerrada sejam executados novamente.

**Cenário 11 — Carreiras independentes**

A progressão de uma carreira não poderá modificar a data ou os acontecimentos de outra carreira do mesmo usuário.

**Cenário 12 — Próximo acontecimento**

A progressão deverá parar no acontecimento relevante previsto, respeitando também qualquer pré-jogo ou decisão obrigatória que ocorra antes dele.

---

# 19. Critérios de aceite do MVP

O MVP estará concluído quando:

### Gestão esportiva

- [ ] O usuário conseguir criar e continuar uma carreira.
- [ ] Existirem dez clubes com jogadores e estádios.
- [ ] O usuário conseguir gerenciar escalações e táticas.
- [ ] O motor esportivo executar partidas completas.
- [ ] A IA gerenciar os adversários.
- [ ] A liga possuir calendário consistente.
- [ ] Resultados e classificações forem atualizados corretamente.

### Progressão temporal

- [ ] Cada carreira possuir calendário diário interno e independente.
- [ ] A ação principal do dashboard for "Próxima partida".
- [ ] O usuário conseguir utilizar "Avançar 1 dia".
- [ ] O usuário conseguir utilizar "Próximo acontecimento".
- [ ] A ação "Próxima partida" processar automaticamente todos os dias intermediários e parar no pré-jogo do clube controlado pelo usuário.
- [ ] O calendário da carreira não avançar sem uma ação explícita do usuário.
- [ ] Ao desconectar e retornar posteriormente, a carreira permanecer na mesma data e estado persistidos, independentemente do tempo real transcorrido, ressalvada a conclusão segura de uma operação previamente autorizada.
- [ ] Obras, recuperação física, jogos da IA e demais acontecimentos respeitarem a ordem cronológica, mesmo quando o usuário avançar vários dias de uma só vez.
- [ ] A partida do usuário exigir confirmação antes da simulação.
- [ ] Nenhuma progressão ocorrer automaticamente por cron job ou agendamento.
- [ ] Operações repetidas não produzirem processamento duplicado.
- [ ] A recuperação após falhas preservar a consistência da carreira.

### Estádio e torcida

- [ ] Cada partida em casa gerar público coerente.
- [ ] O desempenho esportivo influenciar a demanda futura.
- [ ] Rivalidades e importância esportiva influenciarem o público.
- [ ] O preço dos ingressos influenciar a procura.
- [ ] O público não ultrapassar a capacidade do estádio.
- [ ] O usuário conseguir ampliar e modernizar estádios.
- [ ] As obras possuírem custos e prazos efetivos.
- [ ] As obras avançarem exclusivamente com o calendário interno.

### Finanças

- [ ] O clube possuir saldo financeiro rastreável.
- [ ] As bilheterias gerarem receitas.
- [ ] As despesas operacionais forem debitadas.
- [ ] A IA administrar ingressos e investimentos.
- [ ] Não ocorrerem lançamentos financeiros duplicados.

### Temporadas e persistência

- [ ] Os resultados históricos permanecerem disponíveis.
- [ ] O usuário conseguir avançar entre temporadas.
- [ ] O estádio e as finanças persistirem entre temporadas.
- [ ] Múltiplas carreiras permanecerem isoladas.
- [ ] O calendário interno persistir corretamente.
- [ ] O sistema passar nos testes críticos de integridade e segurança.
- [ ] A aplicação puder ser utilizada integralmente pelo navegador.

---

# 20. Fora do escopo do MVP

As seguintes funcionalidades não deverão ser implementadas nesta versão:

- Multiplayer.
- Mercado complexo de transferências.
- Negociação de contratos e salários.
- Folha salarial detalhada.
- Patrocínios.
- Naming rights.
- Programa de sócios-torcedores.
- Venda de produtos.
- Camarotes e setores de ingressos diferenciados.
- Empréstimos bancários.
- Administração de dívidas complexas.
- Categorias de base completas.
- Múltiplas divisões.
- Promoção e rebaixamento.
- Campeonatos continentais.
- Motor gráfico 2D com deslocamento de atletas.
- Motor gráfico 3D.
- Serviços externos de IA generativa.
- Clima dinâmico.
- Negociação com agentes e empresários.
- Progressão baseada no tempo real.
- Avanço automático de carreiras desconectadas.
- Simulação espontânea de partidas pelo servidor sem autorização do usuário.

Essas funcionalidades poderão ser previstas como futuras extensões, mas não devem acrescentar complexidade ao MVP.

---

# 21. Definição de pronto

Uma entrega somente será considerada concluída quando apresentar:

1. Código funcional.
2. Migrações e estruturas necessárias.
3. Regras de negócio documentadas.
4. Testes automatizados relevantes.
5. Validação de segurança e integridade.
6. Interface funcional, quando prevista.
7. Registro das alterações.
8. Ausência de problemas críticos conhecidos.

Nenhuma entrega deverá ser considerada concluída apenas porque os arquivos ou métodos foram criados.

---

# 22. Resultado esperado

Ao final do MVP, o usuário deverá conseguir:

Criar uma carreira, escolher um clube, administrar o elenco, definir táticas, disputar partidas, acompanhar o desempenho no campeonato, ajustar preços dos ingressos, observar a variação de público, administrar receitas e despesas, ampliar o estádio e continuar jogando por várias temporadas.

A experiência principal deverá permitir que o usuário avance diretamente até sua próxima partida, enquanto o sistema processa automaticamente os acontecimentos correspondentes aos dias intermediários.

O jogador não deverá precisar avançar manualmente cada dia para recuperar atletas, desenvolver obras ou permitir a realização de partidas entre clubes controlados pela IA.

O calendário deverá permanecer completamente independente do tempo real, garantindo que o usuário possa interromper sua carreira e retomá-la posteriormente sem perda de controle sobre os acontecimentos.

Um time que esteja vencendo deverá atrair progressivamente mais interesse da torcida.

Um time em crise deverá enfrentar maior dificuldade de ocupação, com possibilidade de utilizar preços mais acessíveis para estimular a procura.

Partidas decisivas e clássicos deverão possuir maior atratividade esportiva.

A ampliação do estádio deverá possibilitar receitas maiores quando existir demanda suficiente, mas não garantir automaticamente maior público.

O usuário deverá perceber que decisões administrativas e esportivas estão conectadas, criando consequências de longo prazo.

**O objetivo final é entregar um simulador de futebol single-player com profundidade esportiva e administrativa, inspirado na acessibilidade do Brasfoot, mas com uma economia dinâmica própria, gerenciamento de estádios, calendário diário controlado pelo usuário e capacidade de evolução para multiplayer.**
