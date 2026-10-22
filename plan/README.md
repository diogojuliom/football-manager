# Plano de implementação — Football Manager Web

O [PRD original](PRD.md) foi preservado integralmente, conforme o texto enviado (versão 2.1, atualização de 15/10/2026).

A pasta [entregas](entregas/) contém um arquivo Markdown para cada uma das 13 entregas da seção 17, mantendo a numeração, o escopo e os critérios de aceite originais. As Entregas 01 a 09 foram implementadas. A Entrega 00 permanece pendente em parte, pois o projeto atual usa Blade e não inclui Vue, TypeScript e Inertia; as Entregas 10 a 12 permanecem planejadas. O PRD original foi mantido intacto.

## Entregas

| Número | Entrega | Estado |
| --- | --- | --- |
| 00 | [Fundação técnica](entregas/entrega-00-fundacao-tecnica.md) | Parcial (stack de interface pendente) |
| 01 | [Dados-base e clubes](entregas/entrega-01-dados-base-e-clubes.md) | Implementada |
| 02 | [Carreiras](entregas/entrega-02-carreiras.md) | Implementada |
| 03 | [Elenco e táticas](entregas/entrega-03-elenco-e-taticas.md) | Implementada |
| 04 | [Motor de partidas](entregas/entrega-04-motor-de-partidas.md) | Implementada |
| 05 | [IA dos clubes](entregas/entrega-05-ia-dos-clubes.md) | Implementada |
| 06 | [Competições, calendário e progressão temporal](entregas/entrega-06-competicoes-calendario-e-progressao-temporal.md) | Implementada |
| 07 | [Estádios e torcida](entregas/entrega-07-estadios-e-torcida.md) | Implementada |
| 08 | [Ingressos e finanças](entregas/entrega-08-ingressos-e-financas.md) | Implementada |
| 09 | [Obras e infraestrutura](entregas/entrega-09-obras-e-infraestrutura.md) | Implementada |
| 10 | [Temporadas consecutivas](entregas/entrega-10-temporadas-consecutivas.md) | Planejada |
| 11 | [Interface e experiência](entregas/entrega-11-interface-e-experiencia.md) | Planejada |
| 12 | [Balanceamento e estabilização](entregas/entrega-12-balanceamento-e-estabilizacao.md) | Planejada |

## Orientações para implementação futura

- Seguir a sequência proposta no PRD, da Entrega 00 à Entrega 12.
- Consultar as regras detalhadas do PRD antes de implementar cada entrega; os arquivos individuais não substituem a especificação completa.
- Aplicar o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).
- Preservar o isolamento entre carreiras, o determinismo das partidas e a progressão temporal somente mediante ação explícita do usuário, sem avanço automático offline.
- Registrar evidências de validação antes de considerar uma entrega concluída.
