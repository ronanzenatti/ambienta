# Ambienta

Sistema multicampus de gestão de ambientes, reservas, alocação semestral de aulas e patrimônio, desenvolvido para a Fatec Jahu (Centro Paula Souza).

Atende dois públicos na mesma aplicação: a equipe da unidade (Direção, Coordenação, Auxiliares e Docentes), que trabalha no painel administrativo, e o público em geral, que consulta pelo celular, sem login, onde acontece cada aula.

## O que o sistema faz

- **Estrutura institucional:** campus, prédios, ambientes e categorias, com identidade visual (cor) por campus.
- **Acadêmico:** cursos, disciplinas, semestres, atribuições de aulas (professor + disciplina + semestre) e requisitos de cada disciplina (capacidade, itens e softwares).
- **Agenda:** alocação semestral das aulas, reservas pontuais (com recorrência, fila de confirmação e autorização de conflitos), bloqueios de ambiente e feriados.
- **Patrimônio:** itens com ou sem número de patrimônio, modelos, almoxarifados, movimentações com confirmação de recebimento e prazo, descartes com aprovação e catálogo de softwares.
- **Manutenção:** chamados de adequação, abertos manualmente ou pelo próprio sistema quando um ambiente não atende aos requisitos da disciplina.
- **Consulta pública:** agenda por ambiente sem login, com QR Code para a porta da sala e feed iCal (por ambiente e pessoal).
- **Painel inicial:** o que espera uma ação da pessoa, a agenda dela nos próximos dias e o andamento do que ela pediu.
- **Administração:** usuários, perfis com matriz de permissões por escopo (global, campus ou prédio), login com Google restrito a domínios permitidos, notificações (na tela e por e-mail), log de auditoria e relatórios.
- **Exportação:** toda listagem exporta para Excel e PDF.

Os requisitos completos estão em [REQUISITOS.md](REQUISITOS.md).

## Tecnologias

| Camada | Tecnologia |
|---|---|
| Back-end | PHP 8.2+, CodeIgniter 4 |
| Banco de dados | MariaDB (10.4 ou superior) |
| CSS | Tailwind CSS v4, com tema gerado a partir de `design/design-system/tokens.json` |
| JavaScript | Alpine.js e Tom Select, sem framework de SPA |
| Exportação | openspout (Excel) e dompdf (PDF) |
| Fonte | Public Sans, servida pelo próprio sistema |

Decisões que valem para todo o projeto:

- **Idiomas:** a interface existe em pt-BR (padrão), inglês e espanhol. O conteúdo digitado pelos usuários nunca é traduzido.
- **Acessibilidade:** WCAG 2.1 AA é requisito. Todo status é ponto colorido mais texto, nunca só a cor.
- **Privacidade:** nenhuma fonte, script ou folha de estilo vem de CDN ou de terceiros, para não expor o IP dos usuários de uma instituição pública.
- **Navegadores:** Chrome 111+, Safari 16.4+ e Firefox 128+.

## Requisitos para rodar

- PHP 8.2 ou superior, com as extensões `intl`, `mbstring`, `mysqli`, `dom` e `curl`
- Composer
- MariaDB 10.4 ou superior
- Node.js 20 ou superior (só para gerar o CSS e copiar os assets)

## Instalação

1. Clone o repositório e instale as dependências:

   ```bash
   composer install
   npm install
   ```

2. Crie o arquivo de ambiente a partir do modelo:

   ```bash
   cp env .env
   ```

   Preencha no `.env`:

   | Chave | Para que serve |
   |---|---|
   | `CI_ENVIRONMENT` | `development` na máquina local, `production` no servidor |
   | `app.baseURL` | Endereço do sistema |
   | `database.default.*` | Conexão com o MariaDB |
   | `admin.nome`, `admin.email`, `admin.password` | Administrador inicial, criado pelo seeder |
   | `google.clientId`, `google.clientSecret`, `google.redirectUri` | Login com Google (opcional) |
   | `email.*` | Envio de e-mails (notificações e redefinição de senha) |

   Se `admin.password` ficar vazio em produção, o seeder gera uma senha aleatória e a mostra uma única vez no terminal.

3. Crie um banco de dados vazio (`utf8mb4`) e rode as migrations e os seeders:

   ```bash
   php spark migrate
   php spark db:seed DatabaseSeeder
   ```

4. Gere o tema, os assets e o CSS:

   ```bash
   npm run build
   ```

5. Suba o servidor de desenvolvimento:

   ```bash
   php spark serve
   ```

   O sistema abre em `http://localhost:8080`. Em servidor web, aponte a raiz do site para a pasta `public/`, nunca para a raiz do projeto.

## Desenvolvimento

| Comando | O que faz |
|---|---|
| `npm run dev` | Regenera o tema e recompila o CSS a cada alteração |
| `npm run build` | Gera tema, assets e CSS minificado |
| `npm run audit:a11y` | Auditoria de acessibilidade com axe |
| `vendor/bin/phpunit` | Testes |
| `vendor/bin/phpstan analyse` | Análise estática |
| `vendor/bin/phpcs` | Padrão de código |

> **Atenção:** os testes rodam no banco configurado em `database.tests.*` e apagam os dados dele a cada execução. Nunca aponte esse grupo para um banco com dados que você queira manter.

### Banco de dados

O esquema completo está em [database/ambienta_schema_v1.sql](database/ambienta_schema_v1.sql), e as migrations em `app/Database/Migrations/` executam esse mesmo SQL. O cabeçalho do arquivo descreve as convenções de nomes. As principais:

- Tabelas no plural, em `snake_case`; chave primária `id_<tabela_no_singular>`.
- Toda tabela tem `criado_em`, `alterado_em` e `deletado_em`. A exclusão é sempre lógica.
- Regras que não podem sofrer concorrência (criar e confirmar reserva, alocar aula) ficam em stored procedures.
- O banco é MariaDB: sintaxe exclusiva do MySQL 8 não é garantida.

### Interface

O Design System fica em [design/design-system/](design/design-system/): `tokens.json` é a fonte das cores, tipografia, espaçamentos e raios, e o `README.md` descreve os padrões de tela. Nunca copie um valor de token para uma classe ou arquivo CSS. Altere o `tokens.json` e rode `npm run build:theme`.

## Tarefas agendadas

Em produção, agende no cron:

| Comando | Frequência sugerida | O que faz |
|---|---|---|
| `php spark notificacoes:enviar` | A cada poucos minutos | Envia por e-mail as notificações pendentes |
| `php spark patrimonio:lembretes` | Uma vez por dia | Lembra as movimentações de patrimônio com prazo vencido |
| `php spark feriados:sincronizar [ano]` | Uma vez por ano | Busca os feriados nacionais e grava os que faltam |

## Estrutura do projeto

```
app/
  Controllers/   Telas, por módulo (Institucional, Academico, Agendamento, Patrimonio, Chamados...)
  Libraries/     Regras de negócio, permissões, listagem padrão e exportação
  Tabelas/       Uma classe por listagem do sistema
  Views/         Telas e componentes do Design System
  Language/      Textos da interface (pt-BR, en, es)
  Database/      Migrations e seeders
database/        Esquema SQL e dados iniciais
design/          Design System (tokens e guia)
resources/       CSS e JavaScript de origem
public/          Raiz do site (assets compilados, uploads)
tests/           Testes unitários, de banco e de requisição
```

## Licença

[MIT](LICENSE).
