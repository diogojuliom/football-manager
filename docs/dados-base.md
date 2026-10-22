# Dados-base — Entrega 01

A versão `1.0.0` define um universo fictício reproduzível, sem criar usuários ou carreiras. A criação e a cópia do universo para uma carreira pertencem à Entrega 02. Simulação, calendário de confrontos, movimentações financeiras e obras pertencem às entregas seguintes.

## Estrutura

Os modelos ficam em `app/Domain/GameDatabase/Models`, separando o catálogo de referência do futuro estado das carreiras.

| Tabela | Conteúdo da versão inicial |
| --- | --- |
| `game_database_versions` | Identificador da versão, nome e checksum SHA-256 do conteúdo |
| `competition_rulesets` | Liga de 10 clubes, turno e returno, 18 rodadas, 90 jogos; pontuação 3/1/0 |
| `competition_templates` | Liga do Horizonte |
| `club_templates` | 10 clubes fictícios, cores, cidades, finanças e torcida iniciais |
| `stadium_templates` | Um estádio por clube, capacidade, infraestrutura e custos operacionais |
| `player_templates` | 20 atletas por clube, total de 200, com os 11 atributos do RF05 |

As chaves estrangeiras compostas impedem vínculos entre versões distintas. Códigos são únicos por versão e cada clube possui no máximo um estádio. Exclusões de registros referenciados são bloqueadas. Os modelos validam atributos esportivos entre 0 e 100, posições, capacidade e valores iniciais antes de salvar. Escritas via SQL direto ou operações em massa do query builder não executam a validação dos modelos.

Os elencos possuem dois goleiros, quatro zagueiros, dois laterais de cada lado, dois volantes, três meias centrais, um meia ofensivo, dois pontas e dois atacantes. As siglas são `GK`, `CB`, `LB`, `RB`, `DM`, `CM`, `AM`, `LW`, `RW` e `ST`. As idades iniciais vão de 18 a 35 anos; a condição física começa em 100 e a moral em 70. Nomes e atributos são construídos por regras inteiras fixas no arquivo da versão, sem Faker, relógio real ou sorteios.

Os desempates configuráveis iniciais são vitórias, saldo de gols, gols marcados e, por último, código do clube em ordem crescente para uma resolução estável. A Entrega 06 aplica essa ordem à classificação, usando as regras congeladas da edição e os resultados oficiais.

## Valores iniciais e unidades

- Todos os valores monetários são inteiros em **centavos de real (BRL)**, identificados pelo sufixo `_cents`; não usar ponto flutuante.
- Saldos iniciais: R$ 300.000 a R$ 525.000; ingresso padrão: R$ 30.
- Estádios: 12.000 a 26.000 lugares, integralmente disponíveis; infraestrutura de 1 a 5 (níveis iniciais 2 a 4).
- Custo fixo por jogo em casa: R$ 15.000 + R$ 2.500 por nível de infraestrutura; custo variável: R$ 5 por torcedor.
- Torcida potencial: duas vezes a capacidade inicial; fidelidade e engajamento em escala de 0 a 100.

Esses números são parâmetros iniciais sujeitos ao balanceamento da Entrega 12, não evidência de equilíbrio econômico. Não há saldo atual, público médio, ocupação ou receita histórica nas tabelas-base: esses valores dependerão da carreira e de suas partidas.

## Carregar e atualizar

Na raiz do repositório, com PHP/Composer e uma conexão de desenvolvimento configurada:

```bash
composer install
php artisan migrate --seed
# Reexecutar somente a carga:
php artisan db:seed --class=GameDatabaseSeeder
```

Na máquina de nuvem preparada neste projeto, ative antes `source /workspace/.cloud-tools/football-env.sh`. Isso seleciona o PHP local e o SQLite de desenvolvimento; uma configuração MySQL própria pode ser usada sobrescrevendo as variáveis de conexão.

O `DatabaseSeeder` chama o `GameDatabaseSeeder`. A carga inteira é transacional e bloqueia a versão durante a gravação. Reexecuções usam as mesmas chaves de versão/código, preservam IDs, timestamps e valores já existentes e não duplicam registros. Registros ausentes podem ser recriados sem redefinir os demais.

O arquivo `database/seeders/data/v1.php` é uma publicação versionada. O checksum do conteúdo calculado é armazenado em `game_database_versions`. Se o conteúdo dessa mesma versão for alterado, o seed falha e faz rollback em vez de atualizar silenciosamente a publicação. Para evoluir os dados, adicione um novo arquivo e identificador de versão e registre a nova carga no seeder, preservando as publicações anteriores. O checksum compara a publicação com sua definição; não é uma auditoria de alterações manuais feitas no banco.

## Validar

```bash
# SQLite isolado em memória, inclusive se houver variáveis de conexão exportadas:
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test
```

Também é possível executar os mesmos testes contra **um banco MySQL 8 exclusivo para testes**, sobrescrevendo `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD`. A suíte usa `RefreshDatabase`, que recria as tabelas no banco selecionado; nunca selecionar um banco com dados a preservar.

A suíte verifica o universo completo, limites dos atributos, posições, regras da liga, valores financeiros, capacidade, integridade referencial entre versões, unicidade, restrição de exclusões, checksum, repetição do seed e rollback após uma falha injetada. As migrações suportam rollback em ordem inversa de dependência.

## Compatibilidade

A implementação usa Laravel 10 e PHP 8.1+ declarados no repositório existente. A migração para a stack futura do PRD permanece no escopo da Entrega 00; esta entrega não altera dependências, autenticação ou frontend.
