# Entrega 01 — Dados-base e clubes

Implementar:

- Clubes fictícios.
- Jogadores.
- Atributos.
- Estádios iniciais.
- Definições de competições.
- Configurações financeiras iniciais.
- Seeds versionadas.

**Aceite:** universo inicial consistente, com dez clubes, seus respectivos elencos e estádios.

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** implementada em 21/10/2026.


## Registro da implementação

- Seis tabelas de referência com chaves estrangeiras, unicidade e isolamento entre versões dos dados.
- Modelos no domínio `GameDatabase`, com relacionamentos e validações.
- Publicação `1.0.0`: uma liga fictícia, dez clubes, 200 jogadores e dez estádios, com finanças e torcida iniciais.
- Seeder transacional, repetível e protegido contra alterações silenciosas da publicação pelo checksum do conteúdo.
- Testes de integridade, validação, repetição e rollback após falha.

**Validação:** suíte completa com 16 testes aprovados em SQLite e MySQL 8.0.46. Migração, carga e rollback/reaplicação verificados em bancos descartáveis.

A implementação utiliza a versão atual do Laravel no repositório. A atualização da stack da Entrega 00 e a criação de carreiras da Entrega 02 permanecem separadas.

Consulte [Dados-base: estrutura, valores e comandos](../../docs/dados-base.md) para executar a carga e os testes.
