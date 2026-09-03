# Agenda Social — Documentação do Sistema

> Sistema PHP de **redação editorial + publicação no Instagram** via Graph API da Meta (Content Publishing).
> Pasta: `/www/wwwroot/publishdev.com.br/agendamentos` · URL: `https://publishdev.com.br/agendamentos`
> **Versão atual: 3.10.3** · Última atualização deste doc: 2026-08-05
> Desenvolvido por **@alequizao** · (82) 98871-7072 · alexjuniorcalado@gmail.com

> ⚠️ **REGRA DE MANUTENÇÃO (obrigatória):** a CADA alteração de código,
> 1. **suba a versão** em `config.php` (`APP_VERSAO`), que reflete na sidebar (`partials/head.php`) e no `sw.js` (`APP_CACHE`);
> 2. **atualize o Manual de uso** (`manual.php`) e estes `.md`.
> Sem isso o PWA não força atualização e o manual fica defasado.

---

## 1. Visão geral

Painel administrativo onde você cadastra **clientes** (cada um com sua conta do Instagram),
conecta cada conta colando um **token da Meta**, e **agenda publicações** (feed, carrossel, reel, story).
Um **cron a cada minuto** publica o que estiver agendado e vencido.

- **Linguagem:** PHP 8 (declare strict_types), PDO/MySQL.
- **Sem framework.** Sem Bootstrap (foi removido por conflito). Sem Composer.
- **Front-end:** HTML server-side + `app.css` + Font Awesome. `agenda.js` é **órfão** (não referenciado).
- **Fuso:** `America/Maceio` (-03:00).

---

## 2. Banco de dados (IMPORTANTE)

- **Banco DEDICADO:** `agendamentos-meta` — host, usuário e senha definidos em `config.php` (não versionado).
- **NÃO usa** o banco `seune` nem as variáveis `DB_*` do `.env` compartilhado. O usuário foi explícito sobre isso.
- `config.php` **fixa as credenciais direto** no código (não lê `DB_*` do `.env`).
- **`DB_PREFIX = ''`** → tabelas **sem prefixo**.
- Importar esquema: `mysql -u agendamentos-meta -p agendamentos-meta < database.sql`

### Tabelas
| Tabela | Função |
|---|---|
| `usuarios` | Logins do painel (`papel` admin/editor). Admin inicial: **agendamentos / agendamentos**. |
| `login_log` | Tentativas de login (throttle). |
| `clientes` | Cada cliente + sua conta IG: `ig_user_id`, `fb_page_id`, `access_token`, `token_expira_em`. |
| `publicacoes` | Posts agendados. `tipo` (feed/carrossel/story/reel), `status` (agendado→processando→publicado/erro/cancelado), `ig_container_id`, `ig_media_id`, `tentativas`. |
| `publicacao_midia` | Mídias de cada post (carrossel = várias linhas). `arquivo` (disco) + `url_publica` (URL absoluta que a Meta baixa). |

> Obs.: o ENUM de `publicacoes.tipo` **já inclui `story`**, mas a publicação de story depende do tipo de token (ver §5).

---

## 3. Mapa de arquivos

| Arquivo | Papel |
|---|---|
| `config.php` | Credenciais do banco (fixas), `BASE_URL`, `GRAPH_VERSION`, `IG_APP_ID/SECRET`, throttle, fuso, `DEBUG`. |
| `init.php` | **Núcleo.** `db()`, login/CSRF/throttle, helpers de mídia/avatar, e **toda a camada Graph** (`graph_get`/`graph_post`/`graph_host`, perfil, insights, troca/renovação de token). |
| `index.php` | Redireciona para dashboard/login. |
| `login.php` / `logout.php` | Autenticação. Login aceita **usuário texto** (`agendamentos`), não só e-mail. |
| `dashboard.php` | Painel. Mostra dados ao vivo do perfil (`ig_perfil()`) — **1 chamada Graph por cliente conectado a cada load**. |
| `cliente.php` / `cliente_form.php` / `cliente_excluir.php` | CRUD de clientes. |
| `cliente_instagram.php` | **Conexão IG.** Cola o token, valida, descobre `ig_user_id`/username, troca por token longo. |
| `publicacao_form.php` | Criar/editar publicação + upload de mídia. |
| `publicacao_excluir.php` | Excluir publicação. |
| `lib_publicador.php` | **Lógica de publicação** (máquina de estados, containers, resume de vídeo/reel). |
| `cron_publicar.php` | Entrypoint CLI do cron (com `flock`). Chama `processar_publicacao()`. |
| `partials/head.php` / `foot.php` | Shell da UI (sidebar + topbar) centralizado. |
| `app.css` | Tema único (ver §6). |
| `database.sql` | Esquema + admin inicial. |
| `uploads/clientes/` · `uploads/publicacoes/` | Mídias no disco. |
| `logs/cron.log` · `logs/cron.lock` | Saída e lock do cron. |

---

## 4. Cron / publicador

- **Crontab (já instalado):**
  ```
  * * * * * /usr/bin/php /www/wwwroot/publishdev.com.br/agendamentos/cron_publicar.php >> .../logs/cron.log 2>&1
  ```
- `flock` impede execução concorrente.
- **Máquina de estados** (`processar_publicacao` em `lib_publicador.php`):
  `agendado` → `processando` → `publicado` | `erro`.
  Vídeo/Reel: cria container, **aguarda processar** (`ig_aguardar`/`ig_status_container`), faz **resume** via `ig_container_id` salvo, e então publica (`ig_publicar_container`).
- URLs de mídia **precisam ser absolutas** → `BASE_URL` correto é crítico (a Meta baixa a mídia pela URL pública).
- Há também `CRON_TOKEN` (em `config.php`/`.env`) como fallback p/ gatilho web, caso use cron externo.

---

## 5. Conexão com o Instagram (tokens) — ponto sensível

O token é **colado manualmente por cliente** em `cliente_instagram.php`. `graph_host()` (em `init.php`)
roteia por **prefixo do token**:

| Prefixo | Login | Host | Como descobre a conta | Permite Story via API? |
|---|---|---|---|---|
| `IG...` | Instagram API com Login do Instagram | `graph.instagram.com` | `/me?fields=user_id,username` (`ig_user_id = user_id`) | **NÃO** (só feed/reels) |
| `EAA...` | Facebook Login (conta Business) | `graph.facebook.com` | `/me/accounts` (página → IG Business) | **SIM** |

- O cliente real **@tevinobuzao usa token `IG...`**.
- **Token longo (60d):** tokens `IGAA...` do botão "Generate token" são curtos (~1h).
  `ig_trocar_token_longa()` troca por longo **se `IG_APP_SECRET` estiver em `config.php`**.
  O cron renova (`ig_renovar_token()`) quando faltam ≤10 dias.
  **Sem App Secret configurado, o token fica como veio (risco de expirar).**

---

## 6. Visual — padrão "Designi Alequizao"

- Admin dashboard claro: sidebar fixa, cards brancos arredondados, fundo cinza, destaque **azul `#2563EB`**, fonte **Inter**, ícones Font Awesome.
- Shell centralizado em `partials/head.php` + `foot.php`; tema todo no `app.css`.
- ⚠️ **NÃO renomear/remover** as variáveis CSS **antigas** (`--panel`, `--ink`, `--line`, `--r-sm`, `--font-display`, etc.) — elas foram só remapeadas para valores claros, mas `cliente_instagram.php` usa **muito estilo inline** com elas.
- O preview do Instagram (`.ig*`) é **intencionalmente escuro** (mockup do app).

---

## 7. Como rodar / operar

- **Acessar:** `https://publishdev.com.br/agendamentos` → login `agendamentos` / `agendamentos`.
- **Conectar um cliente:** cadastrar em Clientes → abrir "Conectar Instagram" → colar token.
- **Agendar post:** Publicações → escolher cliente, tipo, legenda, mídia, data/hora.
- **Logs do cron:** `logs/cron.log`.

---

## 8. Backups

- Pasta: `/www/wwwroot/publishdev.com.br/_backups_agendamentos/`
- Cada backup = `agendamentos_full_<timestamp>.tar.gz` (arquivos do sistema + dump `db_*.sql`).
- **Restaurar:**
  ```bash
  tar -xzf agendamentos_full_<ts>.tar.gz -C /destino
  mysql -u agendamentos-meta -p agendamentos-meta < db_agendamentos-meta_<ts>.sql
  ```

---

## 9. Módulo "Notícias TV no Busão" (IA) — v1.1+

Gera posts de notícia de **Maceió/Alagoas** automaticamente, **com aprovação** antes de publicar.

**Fluxo:** `cron_noticias.php` (a cada min, auto-regulado pelo intervalo) → `lib_gerador.php` →
coleta RSS (`lib_noticias.php`) → gera **cena no ônibus** com gpt-image (`lib_ia.php`) →
grava **manchete real + "Fonte: X"** sobre a arte com GD (`lib_arte.php`) → reescreve **legenda** (OpenAI texto) →
cria `publicacoes` com `status='aguardando_aprovacao'`, `origem='ia'`. O publicador **não** toca nesses até serem aprovados.

**Por que a manchete é gravada por cima (e não "desenhada" pela IA):** gpt-image erra texto e inventa imagem;
a confiabilidade vem do texto real do RSS. A cena gerada é só o "plano de fundo" (TV sem texto).

**Telas:**
- `config_ia.php` — liga/desliga, **intervalo (min)**, janela de horário, cliente alvo, tipo (feed/story), modelos/tamanho/qualidade, **preço por imagem + cotação US$→R$**, lista de feeds (`Nome | URL`), e botão **"Gerar 1 agora"** (teste).
- `aprovacao.php` — lista os rascunhos com arte + legenda editável + fonte; **Aprovar e agendar** / Reprovar / Excluir.
- Dashboard — cards "Notícias IA p/ aprovar" e **"Custo IA no mês"**.

**Custo:** cada chamada cobrada grava 1 linha em `custos_ia` (tipo, modelo, tokens, US$). Imagem usa o preço
configurável `ia_preco_imagem_usd`; texto é calculado por tokens (`ia_precos_texto()` em `lib_ia.php`).

**Config (tabela `configuracoes`, chave/valor):** `ia_ativo`, `ia_intervalo_min`, `ia_cliente_id`, `ia_tipo_post`,
`ia_hora_inicio/fim`, `ia_modelo_texto/imagem`, `ia_tamanho_imagem`, `ia_qualidade_imagem`,
`ia_preco_imagem_usd`, `ia_usd_brl`, `ia_ultima_exec`, `ia_feeds` (JSON). Helpers `cfg_get/cfg_set/cfg_feeds` em `init.php`.

**Chave:** `OPENAI_API_KEY` no `.env` (fora do webroot). Sem ela, a geração falha (telas avisam).

**Cron instalado:** `* * * * * php .../cron_noticias.php >> logs/cron_noticias.log`.

**Feeds que funcionam (Alagoas):** G1 Alagoas, Gazeta de Alagoas, Alagoas 24 Horas.

**Story:** o caminho de Story já existe no publicador, mas a Meta só aceita com **token Business `EAA...`**.
Com o `IG...` atual (@tevinobuzao), use **Feed**.

---

## 11. Mesa de Redação / modo redator-chefe (v3.7.0)

O fluxo principal do @tevinobuzao agora é **curadoria** (`ia_fluxo=curadoria`): **nada vai ao ar sem o
redator-chefe aprovar de manhã** — exceto **transporte público de Maceió (tema `onibus`)**, que posta
sozinho. Trava dura de **40 posts/dia** (`pub_cap_dia`) + respeito ao **limite ao vivo da Meta**.

**Linha editorial (temas):** `crime`, `csa_crb` (futebol local), `onibus` (auto), `evento`, `social`.
Fora da linha: atriz/ator global, novela, reality, futebol nacional/global, loteria, aleatório.

**Filtro em 2 camadas (`lib_temas.php`):**
1. palavras-chave por **manchete** (radicais p/ conjugações) → tema + veredito `na_linha|fora|duvida`;
2. **IA** (OpenAI) só nas `duvida` (`ia_filtro_ia`, cap `ia_filtro_ia_max`) — o "filtro do filtro".
A decisão final é do editor na **Mesa de Redação** (`noticias.php`).

**Colunas novas** em `noticias_descobertas` (ver `migracao_curadoria.php`): `tema`, `veredito`, `motivo`,
`auto`, `fonte_filtro`, `classificado_em`; status ganhou `aprovada`.

**Arquivos novos/alterados:** `lib_temas.php` (classificador), `lib_agendador.php` (`curadoria_rodar`,
`agendador_slots_restantes`), `cron_noticias.php` (classifica + curadoria + self-heal de áudio),
`noticias.php` (Mesa de Redação), `config_ia.php` (linha editorial), `manual.php`, PWA
(`manifest.json`, `sw.js`, `icons/`), `dashboard.php` (card de limite).

**Limite da Meta (anti-bloqueio):** `ig_limite_publicacao()` + `pub_limite_status()` (cache 10min) leem
`GET /{ig-id}/content_publishing_limit` (`quota_usage`/`config.quota_total`). O agendador para com
margem (`ia_limite_margem`). Exibido na Mesa e no Painel.

**Áudio:** o `shell_exec` é bloqueado no PHP-FPM (site), liberado no CLI (cron). Por isso a arte+áudio é
gerada **no cron** (`curadoria_rodar`→`agendador_criar_post`) e há `gerador_finalizar_audio()` que
conserta no cron qualquer post que tenha saído como imagem. (Bug "gera sem áudio" resolvido.)

**Removidos (em `_arquivo_morto/`):** `agenda.js`, `modelo.png`, `lib_repost.php`.

---

## 10. Pendências / ideias em discussão

- **Memes por IA, 1x/hora (em planejamento — jun/2026).** Decisões do usuário:
  - Destino: **Story** → exige reconectar o cliente como **conta Business com token `EAA...`** (o `IG...` atual não posta story).
  - Gerador: **OpenAI gpt-image / DALL·E** (precisa de chave de API + custo por imagem).
  - Fluxo: **com aprovação** — a IA gera e deixa como rascunho/agendado; nada é postado sem revisão.
  - Reuso provável: cron já existe; criar gerador → salvar em `uploads/` → criar `publicacoes`(status agendado) → publicador posta após aprovação.


---

## Jogo da Forca no Direct (v3.9.3)

Automação de engajamento: o seguidor manda **"jogo"** (ou "forca"/"jogar") no Direct e o bot abre uma
partida de **forca**, letra por letra, com 6 vidas.

- **Jogo INDEPENDENTE do perfil** — não usa a marca nem o conteúdo do TV no Busão.
- **Arquivos:** `lib_jogo.php` (motor), `lib_jogo_arte.php` (arte da forca em GD, custo zero),
  `jogo.php` (aba do painel: KPIs, ranking 7 dias, jogadores, partidas, palavras difíceis,
  zerar ranking do perfil ou de uma pessoa), gancho no `webhook.php`
  (antes de ônibus/auto-resposta, inclui o payload `JOGO_*` dos botões), UI em `direct.php`
  (Configurações), tabela `jogo_partidas` (`migracao_jogo.sql`).
- **Botões (quick replies):** `jogo_botoes()` manda até 13 opções (letras não usadas + dica + parar);
  o webhook traduz `JOGO_L_<letra>`, `JOGO_DICA` e `JOGO_PARAR` em jogada.
- **Máscara com posição:** `jogo_mascara($chave,$letras,$emoji)` — ⬜ no Direct, `_` na arte.
- **Anti-abuso:** `dm_jogo_max_dia` (padrão 10 partidas por pessoa/dia).
- **Ranking:** `jogo_ranking()` / `jogo_ranking_texto()` (comando `ranking` no Direct e botão no painel).
- **Palavra da manchete (opcional, `dm_jogo_noticia=1`):** `jogo_palavra_noticia()` tira a palavra de
  `noticias_descobertas`. Desligado por padrão.
- **Dicionário pt-BR (v3.9.2):** `dicionarios/pt-BR.idx` (239 mil palavras) e
  `dicionarios/pt-BR-comuns.idx` (7.915 palavras comuns, ICF ≤ 13) — arquivos ORDENADOS, consultados por
  **busca binária** (`jogo_palavra_existe()` / `jogo_palavra_comum()`), sem carregar nada na memória
  (~0,03 ms por consulta). Toda palavra sugerida pela IA passa por aí — foi assim que "umbrela" e
  "vai e vem" pararam de aparecer. Fontes e licenças em `dicionarios/FONTE.txt`
  (pythonprobr/palavras MPL-2.0 + fserb/pt-br MIT).
- **Comando `dica`:** revela uma letra e custa 1 vida (a dica em texto já vai na abertura da partida).
- **Comandos do jogador:** uma letra · palavra inteira · `dica` (custa 1 vida) · `placar` · `parar`.
- **Configs por perfil (`config_perfil`):** `dm_jogo_ativo`, `dm_jogo_gatilho`, `dm_jogo_erros` (3–10),
  `dm_jogo_timeout` (min de inatividade; 0 = nunca), `dm_jogo_palavras` (`PALAVRA|dica` por linha),
  `dm_jogo_ia` (sortear com OpenAI), `dm_jogo_tema`, `dm_jogo_ia_modelo` (padrão `gpt-4o-mini`).
- **IA opcional:** `jogo_palavra_ia()` pede um JSON `{palavra,dica}`, valida (só letras, 4–20 chars,
  não repetida, dica não entrega a palavra) e registra custo em `custos_ia` como `jogo`.
  Falhou? cai na lista fixa — o jogo nunca quebra por causa da OpenAI.
- **Convivência:** `jogo_tratar_direct()` devolve `tratou=false` quando não é jogada (texto longo,
  pergunta de ônibus etc.), então as outras automações do Direct seguem funcionando.
- **Mensagens saem com `origem='auto_jogo'`** em `dm_mensagens` (ENUM ampliado na migração).

### Anti-loop entre perfis conectados (importante)

Há **dois @ conectados no mesmo painel** (@alequizao e @tevinobuzao). Quando um manda mensagem
para o outro, o webhook do destinatário recebe como mensagem **normal de seguidor** — e as duas
automações passam a responder uma à outra **infinitamente** (aconteceu com o jogo em 05/08/2026).

Travas em vigor:
1. `webhook.php` — se o **@username da conversa** (ou o `ig_user_id`) for de **qualquer cliente**
   cadastrado, a mensagem é gravada mas **nenhuma automação roda** (`IGNORADO (perfil nosso…)` no log).
   Atenção: o `sender.id` do webhook vem **escopado** e NÃO é igual ao `ig_user_id` — por isso a
   comparação principal é pelo `@`.
2. `lib_jogo.php` — o gatilho do jogo só vale em mensagem com **até 40 caracteres**; texto longo que
   contenha "jogo" nunca abre partida.

Ao criar QUALQUER nova auto-resposta no Direct, respeite essas duas regras.

### v3.9.3 — palavra do dia, categorias/níveis, conquistas e cron

- **Palavra do dia** (`dm_jogo_palavra_dia`, ligado): tabela `jogo_dia` guarda UMA palavra por perfil por
  dia; a 1ª partida da pessoa no dia usa ela (`jogo_partidas.do_dia=1`) e o fim de jogo mostra a posição
  no **ranking do dia** (`jogo_ranking_dia()` — menos erros, mais rápido).
- **Ordem de sorteio** (`jogo_sortear`): palavra do dia → OpenAI no nível do jogador → categoria curada →
  manchete (se ligada) → lista fixa. Toda palavra tem dica.
- **Categorias** (`dicionarios/categorias.json`): animais, frutas, comidas, objetos, profissões, países,
  estados — cada uma com a dica pronta, custo zero. Palavras com < 5 letras são descartadas.
- **Níveis** (`dicionarios/nivel-facil|medio|dificil.idx`, por frequência ICF): `jogo_nivel_do_jogador()`
  sobe fácil → médio (3 vitórias) → difícil (8). O nível **valida** a palavra da IA (`jogo_palavra_do_nivel`).
- **Sequência e conquistas:** `jogo_sequencia()` (atual/melhor) e `jogo_conquista()` (perfeito, 3/5/10
  seguidas, primeira vitória).
- **Imagem de resultado** (`dm_jogo_resultado`): `jogo_arte_resultado()` gera um 1080×1350 com a palavra,
  as vidas em blocos e o CTA "manda JOGO no direct @perfil" — feita pra pessoa postar no story.
- **`cron_jogo.php`** (crontab a cada 5 min): expira partidas paradas **avisando a pessoa**, sorteia a
  palavra do dia adiantado, manda o **ranking semanal** na segunda (`dm_jogo_ranking_semanal`,
  `dm_jogo_ranking_hora`) e limpa artes com mais de 1 dia.
- **Aba `jogo.php`:** palavra do dia + pódio do dia, funil (iniciadas × terminadas × abandonadas),
  horários de pico, jogadores com sequência, zerar ranking do perfil ou de uma pessoa.
- **Comandos extras do jogador:** `categorias` lista os temas; escrever o nome da categoria
  (`animais`, `frutas`, `comidas`, `objetos`, `profissoes`, `paises`, `estados`) ou o nível
  (`facil`/`medio`/`dificil`) já começa a partida naquele modo (pula a palavra do dia).
- **Migração:** `migracao_jogo2.sql` (tabela `jogo_dia`; colunas `categoria`, `nivel`, `do_dia`,
  `finalizada_em` em `jogo_partidas`).

## Arcade do Direct (v3.10.0)

Seis jogos novos em `lib_jogos.php`, além da forca (`lib_jogo.php`). Menu: a pessoa manda **"jogo"** (ou "jogos"/"jogar"/"arcade"/"menu").

| Jogo | Gatilhos | Como funciona |
|---|---|---|
| 🎯 Forca | `forca`, `jogo da forca` | (lib_jogo.php) a palavra letra por letra |
| 🟩 Termo | `termo`, `wordle` | 5 letras, 6 tentativas, retorno 🟩🟨⬛; palavra do dia própria (`termo_dia`), palpite validado no dicionário |
| ❓ Quiz | `quiz`, `perguntas` | 5 perguntas A–D (botões); perguntas pela IA (`dm_jogo_quiz_tema`) com 10 fixas de fallback |
| 🕵️ Enigma | `enigma`, `charada` | 3 pistas progressivas (`pista`), 5 chutes; IA com validação (pista não pode conter a resposta) + 8 fixos |
| ⭕ Velha | `velha` | tabuleiro em emoji, botões 1–9, bot com **minimax** (não perde) |
| 🔀 Anagrama | `anagrama` | palavra embaralhada de uma categoria, dica junto, 3 tentativas |
| 🔢 Adivinha | `adivinha`, `numero` | 1 a 100 em 7 chances, com maior/menor e 🔥/🌡️/🧊 |

- **Estado:** `jogo_sessoes` (JSON, uma sessão ativa por pessoa). **Resultado:** `jogo_resultados`
  (`pontos`, `detalhe`). Ranking e placar somam **arcade + forca** (`jogos_ranking`, `jogos_placar`).
- **Roteador:** `jogos_tratar_direct()` roda no `webhook.php` **antes** da forca; devolve `tratou=false`
  quando não é assunto de jogo (ônibus e auto-resposta seguem normais). Botões chegam como payload
  `JOGOS_*` (`jogos_payload_para_texto`).
- **Comandos gerais:** `jogo`/`jogos`/`jogar`/`arcade`/`menu` (menu), `ranking`, `placar`, `parar`.
- **v3.10.1:** `jogo` deixou de iniciar a forca — agora abre o menu; a forca responde a `forca`
  (`dm_jogo_gatilho` padrão `forca, jogo da forca`).
- **Ligar/desligar por jogo:** `dm_jogo_<termo|quiz|enigma|velha|anagrama|adivinha>` (Direct →
  Configurações). O interruptor geral continua sendo `dm_jogo_ativo`.
- **Manutenção:** `cron_jogo.php` também expira sessões paradas avisando a pessoa.
- **Painel:** aba Jogo mostra partidas por jogo, quem está jogando agora e o ranking geral.
- **Migração:** `migracao_jogos.sql` (`jogo_sessoes`, `jogo_resultados`, `termo_dia`) +
  `dicionarios/termo5.idx` (976 palavras de 5 letras).

### v3.10.3 — aba Jogo ao vivo (AJAX) e números unificados

- `jogo.php?ajax=live&cli=N` devolve **só o bloco que muda** (`partials/jogo_live.php`): KPIs, arcade,
  palavra do dia, funil, ranking, jogadores e partidas. A página troca o `innerHTML` de `#jogoLive`
  a cada **10s**, pausa quando a aba está em segundo plano e tem botão **Pausar/Retomar** + hora da
  última atualização.
- Os números passaram a somar **forca + arcade**: KPIs, funil, horários de pico, lista de jogadores e
  "partidas recentes" (UNION de `jogo_partidas` e `jogo_resultados`; "em andamento" soma as sessões
  ativas de `jogo_sessoes`). O bloco do arcade mostra a forca em separado (`$forcaTot`).
- Partidas do Termo marcadas como palavra do dia aparecem com o selo 🗓️.

---

## 👨‍💻 Desenvolvedor

Sistema **desenvolvido sob encomenda** por **Alex Junior (alequizao)** — Analista e
Desenvolvedor de Sistemas em Maceió, Alagoas. Programador na **Publish Digital**.

alequizao.dev@gmail.com · [WhatsApp](https://wa.me/5582988717072) ·
[@alequizao](https://instagram.com/alequizao) ·
[perfil no GitHub](https://github.com/alequizao/alequizao)
