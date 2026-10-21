# Entrega 02 — Carreiras

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

---

## Referências e conclusão

Escopo e critérios de aceite extraídos da seção 17 do [PRD original](../PRD.md). Consultar também as regras detalhadas do PRD, o plano de testes (seção 18), os critérios de aceite do MVP (seção 19), os limites de escopo (seção 20) e a definição de pronto (seção 21).

**Estado:** implementada em 21/10/2026.


## Registro da implementação

- Criação transacional e idempotente de carreiras com seleção de clube e versão dos dados-base.
- Cópias isoladas de clubes, jogadores, estádios, torcida e finanças iniciais.
- Membership do proprietário, um clube humano e nove clubes controlados pela IA.
- Primeira temporada e competição com regras congeladas e 90 partidas agendadas.
- Data interna inicial independente do relógio real e estado persistente tipado.
- Autorização por proprietário, listagem e retomada, com telas web e autenticação de sessão mínima.
- Proteções de integridade referencial entre carreiras, CSRF e repetição de solicitações.

**Validação:** 35 testes aprovados em SQLite e MySQL 8.0.46; fluxo HTTP com cadastro, sessão, criação e retomada verificado; rollback e reaplicação da migração nos dois bancos.

As ações de progressão, a simulação das partidas e a gestão das próximas temporadas ficam nas entregas correspondentes. A interface atual usa Blade, sem antecipar a atualização da stack da Entrega 00.

Consulte [Carreiras: uso, persistência e validação](../../docs/carreiras.md).
