# Lógica do Sistema de Agendamento — Agenda Social (@tevinobuzao)

Documento que descreve TODA a lógica do sistema de geração e postagem de notícias.
Atualizado em 24/06/2026 — **versão 3.7.0** · Desenvolvido por @alequizao.

> ⚠️ **REGRA:** toda alteração de código exige (1) **bump de versão** em `config.php` `APP_VERSAO`
> (reflete na sidebar e no `sw.js`) e (2) **atualizar o `manual.php`** e estes `.md`.

> **Fluxo atual = CURADORIA (redator-chefe):** nada publica sem aprovação do editor na **Mesa de
> Redação** (`noticias.php`), EXCETO **transporte público de Maceió** (tema `onibus`), que posta sozinho.
> Teto **40/dia** + respeito ao **limite ao vivo da Meta** (`content_publishing_limit`, com margem).
> Classificador editorial em 2 camadas (palavras + IA nas dúvidas) em `lib_temas.php`. Áudio é gerado
> no **cron (CLI)** porque o `shell_exec` é bloqueado no site (FPM). Ver §11 do `SISTEMA.md`.

---

## 1. Visão geral

O sistema descobre notícias de Maceió/Alagoas por RSS, gera uma **arte "TV no Busão"**
(notícia numa TV dentro do ônibus) e **agenda** as publicações distribuídas ao longo do
dia. Você revisa a fila e **recusa** o que não quiser **antes** de ir ao ar. A publicação
em si acontece no horário agendado, via Graph API da Meta.

Dois caminhos de geração de imagem:
- **Código (orgânico, custo zero)** — baixa a foto real da matéria e compõe a arte em PHP/GD.
- **IA (OpenAI)** — gera a cena no ônibus com `gpt-image` (tem custo). Opcional.

---

## 2. Componentes (arquivos)

| Arquivo | Papel |
|---|---|
| `lib_noticias.php` | Coleta RSS, normaliza, dedup, sincroniza `noticias_descobertas`. |
| `lib_materia.php` | **Scraper** do link: og:image/imagens, título, lead, fonte, data. |
| `lib_arte_codigo.php` | **Arte por código** (GD) — 4 templates + cenário de ônibus. |
| `lib_arte.php` | Arte do modo IA (encaixa matéria na tela verde da cena) + helpers GD. |
| `lib_video.php` | Converte arte+trilha em **MP4** (quando áudio ligado) via ffmpeg. |
| `lib_gerador.php` | Orquestra a geração da mídia de UMA publicação (modo + formato + áudio). |
| `lib_agendador.php` | **Cérebro**: monta a fila distribuída de posts agendados. |
| `lib_publicador.php` | Publica na Graph API (story/feed/reels/carrossel). |
| `cron_noticias.php` | A cada minuto: sincroniza pool e roda o fluxo (auto ou aprovação). |
| `cron_publicar.php` | A cada minuto: publica o que está `agendado` e venceu. |
| `cron_stories.php` | A cada hora: captura stories ativos p/ o histórico (módulo à parte). |
| `agendados.php` | **Fila** dos posts automáticos futuros + botão **Recusar**. |
| `aprovacao.php` | Fluxo manual de aprovação (2 etapas) quando `ia_fluxo=aprovacao`. |
| `config_ia.php` | Todas as configurações do módulo. |

---

## 3. Fluxo automático (ia_fluxo = auto) — o principal

A cada minuto, `cron_noticias.php`:

1. **Gate**: só roda se `ia_ativo=1`.
2. **Sincroniza** o pool de notícias (no máximo 1×/10min) → `noticias_descobertas` (status `nova`).
3. Chama `agendador_rodar()`, que decide se cria novos posts agendados:

### 3.1 Distribuição no dia (fracionamento)

Objetivo: se aparecerem, p.ex., 200 matérias, espalhar para postar tudo até o fim do dia.

- **Janela**: só agenda entre `ia_hora_inicio` e `ia_hora_fim`.
- **Teto diário**: `ia_max_dia` (ex.: 30). `slots = max_dia - posts_de_hoje`.
- **Buffer**: mantém só `ia_buffer` posts à frente na fila (não gera 200 de uma vez).
  Conforme os posts são publicados, a fila é reabastecida.
- **Intervalo dinâmico** (se `ia_distribuir_dia=1`):

  ```
  intervalo = max(ia_min_intervalo, janela_restante_min / posts_que_ainda_queremos_hoje)
  ```

  Muitas matérias + dia inteiro pela frente → intervalo pequeno → posta com frequência.
  Poucas matérias → intervalo grande → espalha. Sem distribuição, usa `ia_intervalo_min` fixo.
- **Por ciclo**: cria no máximo `ia_por_ciclo` (padrão 3) posts por minuto, agendados em
  horários futuros espaçados pelo intervalo, sempre dentro da janela.
- **Anti-repetição**: pula matérias do mesmo assunto já usado no dia.

### 3.2 Criação de cada post (`agendador_criar_post`)

- Expande o **tipo** (`ia_tipo_post`): `feed`, `story` ou `ambos` (cria 1 de cada).
- Insere a publicação com `origem='ia'`, `status='agendado'`, `agendado_para=<horário futuro>`.
- Gera a **arte na hora** (`gerar_midia_publicacao`) conforme o modo e o formato
  (story=1080×1920, feed=1080×1350). Se `ia_audio_ativo=1` e houver trilha, vira MP4.
- Marca a notícia como usada e some da caixa "novas".

### 3.3 Revisão e recusa

Os posts ficam em **Agendados** (`agendados.php`) com miniatura e horário. Você pode
**Recusar** um a um ou **Recusar todos** — isso muda o status para `cancelado` e apaga os
arquivos, então o `cron_publicar` não publica. 

Além disso, existem as opções **Postar agora** (que força a publicação instantânea na Meta, ignorando o throttle de segurança) e **Baixar postagem** (permite o download direto do arquivo de imagem ou vídeo). Ambas estão disponíveis tanto nas ações rápidas de cada card quanto no painel de pré-visualização (modal). No painel de aprovação (`aprovacao.php`), há também um botão para baixar a arte recém-gerada por IA na Etapa 2.

### 3.4 Publicação

`cron_publicar.php` (a cada minuto) pega os `agendado` vencidos e publica via Graph API.
Máquina de estados: `agendado → processando → publicado | erro`. Reentrante (retoma vídeo).

---

## 4. Fluxo de aprovação (ia_fluxo = aprovacao)

Mantém o comportamento clássico: a cada `ia_intervalo_min`, gera **1 rascunho**
(`aguardando_aprovacao`). Em `aprovacao.php` você aprova → gera a arte → revisa →
confirma (agenda) ou refaz/reprova. Útil para curadoria fina.

---

## 5. Arte "TV no Busão" (modo código)

`arte_codigo_compor($materia, $clienteId, $opts)`:

- **Templates** (`ia_template` 1–4): 1=Telejornal (TV moldura amarela), 2=Capa cheia,
  3=Split amarelo, 4=Plantão urgente. Pré-visualizações em `modelos/`.
- **Cenário ônibus** (`ia_cenario=onibus`): usa fotos de
  `modelosdeimagensdentrodeonibus/` como fundo; a matéria fica na "TV".
- **Sempre** mostra `FONTE: <fonte> • <data>` e a marca `@tevinobuzao`.
- Fonte tipográfica: `fonts/Anton-Regular.ttf` (manchete) + DejaVuSans (meta).
- Zonas seguras de topo/rodapé para não colidir com a UI do story.

A foto vem do **scraper** (`materia_extrair`): tenta og:image e imagens do corpo; cai
para a imagem do RSS se o link não abrir.

---

## 6. Story e/ou Feed e Áudio

- `ia_tipo_post = ambos` cria um story (1080×1920) e um feed (1080×1350) por matéria.
- `ia_audio_ativo=1` + trilha (`ia_audio_arquivo`, upload em `config_ia.php`) → a arte
  vira MP4 (imagem fixa + áudio, via ffmpeg). Story de vídeo / Reels conforme o tipo.
  Sem ffmpeg ou sem trilha, cai para imagem estática automaticamente.

---

## 7. Configurações (tabela `configuracoes`, chave/valor)

| Chave | Significado | Padrão |
|---|---|---|
| `ia_ativo` | Liga/desliga a automação | 0 |
| `ia_cliente_id` | Conta (cliente) alvo | 7 (tevinobuzao) |
| `ia_tipo_post` | feed \| story \| ambos | story |
| `ia_modo_geracao` | codigo \| ia | codigo |
| `ia_fluxo` | auto \| aprovacao | auto |
| `ia_template` | 1–4 | 1 |
| `ia_cenario` | onibus \| off | onibus |
| `ia_handle` | marca exibida na arte | @tevinobuzao |
| `ia_hora_inicio` / `ia_hora_fim` | janela de horário | 6 / 22 |
| `ia_distribuir_dia` | espalhar no dia | 1 |
| `ia_max_dia` | teto de posts/dia | 30 |
| `ia_min_intervalo` | intervalo mínimo (min) | 3 |
| `ia_buffer` | posts à frente na fila | 6 |
| `ia_por_ciclo` | máx. criados por minuto | 3 |
| `ia_intervalo_min` | intervalo fixo (sem distribuição/aprovação) | 240 |
| `ia_audio_ativo` / `ia_audio_arquivo` | áudio e trilha | 0 / — |
| `ia_bloquear_palavras` | bloqueia matérias com essas palavras | — |
| `ia_exigir_palavras` | só aceita matérias com essas palavras (opcional) | — |
| `ia_exigir_foto` | descarta matérias sem foto real | 0 |
| `ia_feeds` | JSON das fontes RSS | (Alagoas) |

**Filtros de conteúdo** (aplicados no fluxo automático, em `agendador_filtrar`): antes de
agendar, a matéria é descartada (status `ignorada`) se contiver uma palavra bloqueada, se
não tiver nenhuma palavra exigida (quando a allowlist está preenchida), ou se `ia_exigir_foto`
estiver ligado e não houver imagem no RSS nem no link (via scraper).

---

## 8. Crontab (todos a cada minuto, exceto onde indicado)

```
* * * * * php cron_publicar.php  >> logs/cron.log 2>&1
* * * * * php cron_noticias.php  >> logs/cron_noticias.log 2>&1
* * * * * php cron_stories.php   >> logs/cron_stories.log 2>&1   # autorregula p/ 1x/hora
```

---

## 9. Como operar (passo a passo)

1. Em **Configuração**: escolha a conta, o modelo de arte, story/feed/ambos, a janela e
   o teto diário. Modo **código** = custo zero.
2. Ligue **Automação ligada** e salve.
3. Acompanhe em **Agendados**: revise e **recuse** o que não quiser.
4. No horário, o `cron_publicar` publica. Veja o desempenho em **Histórico & estatísticas**
   na página do cliente.

---

## 10. Limitações conhecidas

- **Stories via API** só existem por 24h → histórico de stories acumula a partir da
  captura (`cron_stories.php`), não retroage.
- **Áudio** exige ffmpeg no servidor (presente) e uma trilha enviada.
- O **scraper** depende de cada portal expor og:image/título; quando não expõe, usa a
  imagem do RSS.
