# Temporadas consecutivas

A transição só ocorre após a ação explícita `START_NEXT_SEASON`. Ela é reservada em `career_operations` antes do processamento e pode ser retomada com o mesmo identificador após uma interrupção. Temporada, edição, inscrições, 90 partidas, evolução dos atletas e conclusão de obras do intervalo são gravadas na mesma transação.

Cada edição conserva uma cópia das regras competitivas. A temporada encerrada conserva classificação, campeão e artilharia em `career_seasons.summary`; partidas, resultados, públicos e lançamentos financeiros continuam vinculados à carreira e à edição original.

## Política anual de atletas 1.0.0

- todos os atletas ativos envelhecem um ano;
- atletas de até 23 anos evoluem um ponto em dois terços dos atributos, segundo o identificador persistido do atleta;
- a partir de 30 anos, velocidade e resistência recuam um ponto e controle emocional pode evoluir;
- a partir de 34 anos, velocidade e resistência recuam dois pontos;
- a aposentadoria ocorre ao completar 40 anos;
- lesões e suspensões são encerradas e a condição física volta a 100 no início da temporada;
- cada clube recebe jovens determinísticos de 18 anos até voltar a ter 20 atletas e dois goleiros.

Jogadores aposentados permanecem registrados para auditoria. A origem única dos atletas da base inclui temporada, clube e posição de geração, impedindo reposições duplicadas em novas tentativas.
