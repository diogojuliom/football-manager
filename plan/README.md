# Plano de implementação — Football Manager Web

O [PRD original](PRD.md) foi preservado integralmente, conforme o texto enviado (versão 2.1, atualização de 15/10/2026).

A pasta [entregas](entregas/) contém um arquivo Markdown para cada uma das 13 entregas da seção 17, mantendo a numeração, o escopo e os critérios de aceite originais. As Entregas 01 a 11 foram implementadas e validadas. A Entrega 00 permanece pendente em parte, pois o projeto atual usa Blade e JavaScript progressivo e não inclui Vue, TypeScript e Inertia; a Entrega 12 permanece planejada. O PRD original foi mantido intacto.

## Entregas

| Número | Entrega | Estado |
| --- | --- | --- |
| 00 | [Fundação técnica](entregas/entrega-00-fundacao-tecnica.md) | Parcial (stack de interface pendente) |
| 01 | [Dados-base e clubes](entregas/entrega-01-dados-base-e-clubes.md) | Implementada |
| 02 | [Carreiras](entregas/entrega-02-carreiras.md) | Implementada |
| 03 | [Elenco e táticas](entregas/entrega-03-elenco-e-taticas.md) | Implementada |
| 04 | [Motor de partidas](entregas/entrega-04-motor-de-partidas.md) | Implementada e validada (V4 incremental) |
| 05 | [IA dos clubes](entregas/entrega-05-ia-dos-clubes.md) | Implementada e validada (decisões ao vivo) |
| 06 | [Competições, calendário e progressão temporal](entregas/entrega-06-competicoes-calendario-e-progressao-temporal.md) | Implementada e validada (partida interativa integrada) |
| 07 | [Estádios e torcida](entregas/entrega-07-estadios-e-torcida.md) | Implementada |
| 08 | [Ingressos e finanças](entregas/entrega-08-ingressos-e-financas.md) | Implementada |
| 09 | [Obras e infraestrutura](entregas/entrega-09-obras-e-infraestrutura.md) | Implementada |
| 10 | [Temporadas consecutivas](entregas/entrega-10-temporadas-consecutivas.md) | Implementada e validada |
| 11 | [Interface e experiência](entregas/entrega-11-interface-e-experiencia.md) | Implementada e validada |
| 12 | [Balanceamento e estabilização](entregas/entrega-12-balanceamento-e-estabilizacao.md) | Planejada |

## Evoluções do núcleo esportivo

- **Simulação interativa de partidas:** implementada com o motor `4.0.0`, processamento incremental no backend, cronômetro acelerado, sete velocidades, pausa e intervalo, substituições, 21 formações, instruções táticas, decisões contextuais da IA, retomada persistente, RNG serializado, revisão concorrente e idempotência por requisição.
- **Compatibilidade:** os motores V1, V2 e V3 e seus resultados publicados foram preservados. Partidas rápidas entre clubes da IA continuam usando o fluxo consolidado, enquanto a partida do usuário no pré-jogo utiliza a central interativa e só produz efeitos oficiais ao ser finalizada.
- **Validação visual:** o fluxo carreira → pré-jogo → partida → resultado foi validado em navegador Chromium real com banco SQLite descartável, em desktop (1440 px), tablet (768 px) e mobile (390 px), sem rolagem horizontal ou falhas de renderização. Campo, banco, placar, estatísticas, linha do tempo, substituição, alteração tática, intervalo automático, recarga e encerramento refletiram o estado oficial do backend.
- **Ritmo da partida:** as sete velocidades (`0,5×`, `0,75×`, `1×`, `1,25×`, `1,5×`, `1,75×` e `2×`) foram verificadas com temporizadores controlados. Em `1×`, os 90 minutos têm duração nominal de 300 segundos; o agendador usa tempo monotônico e compensa a latência das requisições sem enviar a velocidade ao motor esportivo.
- **Auditoria final da simulação:** os cenários T01 a T20 foram executados e aprovados em 26/10/2026, incluindo regressão completa, Chromium real e bloqueios concorrentes em MySQL 8 isolado. A [matriz de cobertura e evidências](validacoes/auditoria-final-simulacao-interativa.md) registra comandos, resultados, partidas observadas e a correção aplicada.

## Orientações para implementação futura

- Seguir a sequência proposta no PRD, da Entrega 00 à Entrega 12.
- Consultar as regras detalhadas do PRD antes de implementar cada entrega; os arquivos individuais não substituem a especificação completa.
- Aplicar o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).
- Preservar o isolamento entre carreiras, o determinismo das partidas e a progressão temporal somente mediante ação explícita do usuário, sem avanço automático offline.
- Registrar evidências de validação antes de considerar uma entrega concluída.
