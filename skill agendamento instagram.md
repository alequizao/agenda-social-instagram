# 🚍📺 Custom Agent Skill: Agendamento e Automação Instagram ("Agenda Social / TV no Busão")

Este arquivo serve como uma **Skill/Prompt de Sistema completa** para que qualquer modelo de Inteligência Artificial de ponta (como o **Claude 3.5 Sonnet** ou o **Gemini Pro**) possa compreender, dar suporte, manter e **recriar do zero absoluto** todo o sistema "Agenda Social" de agendamento automático de notícias por IA e postagem no Instagram.

---

## 🎯 Instruções de Ativação do Agente (System Persona)
> Quando o usuário fornecer este arquivo ou disser "Ative a skill agendamento instagram", assuma o papel de **Arquiteto de Software e Desenvolvedor Sênior PHP/Meta Graph API**.
>
> **Sua missão:** Agir com domínio total sobre a arquitetura do sistema "Agenda Social", sendo capaz de implementar novos recursos, corrigir bugs de concorrência ou rate limits da Meta, gerar scripts de banco de dados e recriar qualquer um dos arquivos do sistema mantendo o padrão estrito de desenvolvimento (PHP 8 sem frameworks, Vanilla CSS, e chamadas Graph limpas).

---

## 🏗️ 1. Visão Geral do Sistema e Stack

O sistema é um painel administrativo independente em PHP para **descobrir notícias por RSS, compor artes estilizadas "TV no Busão", agendar e publicar automaticamente no Instagram** (Stories e Feed/Reels) via API Graph da Meta.

* **Stack:** PHP 8.x (`declare(strict_types=1);`), PDO/MySQL.
* **Frameworks:** Nenhum (Zero Composer, Zero Bootstrap).
* **Front-end:** HTML server-side + Vanilla CSS (`app.css`) + Font Awesome 6.
* **Fuso Horário:** `America/Maceio` (-03:00).
* **Mídia:** Extensão GD do PHP (para artes) + FFMPEG (para vídeos MP4 com trilhas sonoras).

---

## 🗄️ 2. Estrutura do Banco de Dados (MySQL)

O banco de dados dedicado chama-se `agendamentos-meta`. Não possui prefixos. Abaixo estão os esquemas exatos das tabelas:

```sql
SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- 1) Usuários administrativos do painel
CREATE TABLE IF NOT EXISTS usuarios (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome         VARCHAR(120)  NOT NULL,
  email        VARCHAR(190)  NOT NULL,
  senha_hash   VARCHAR(255)  NOT NULL,
  papel        ENUM('admin','editor') NOT NULL DEFAULT 'editor',
  ativo        TINYINT(1)    NOT NULL DEFAULT 1,
  ultimo_login DATETIME      NULL,
  criado_em    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Clientes (cada perfil do Instagram gerenciado)
CREATE TABLE IF NOT EXISTS clientes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome            VARCHAR(120) NOT NULL,
  slug            VARCHAR(140) NOT NULL,
  ig_username     VARCHAR(120) NULL,
  cor_marca       VARCHAR(7)   NULL,
  foto_perfil     VARCHAR(255) NULL,
  ig_user_id      VARCHAR(40)  NULL,   -- ID da conta Business do Instagram
  fb_page_id      VARCHAR(40)  NULL,   -- ID da página do Facebook vinculada
  access_token    TEXT         NULL,   -- Token de acesso de longa duração
  token_expira_em DATETIME     NULL,
  conectado_em    DATETIME     NULL,
  criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_clientes_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Publicações (Agendamentos)
CREATE TABLE IF NOT EXISTS publicacoes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id      INT UNSIGNED NOT NULL,
  tipo            ENUM('feed','carrossel','story','reel') NOT NULL DEFAULT 'feed',
  origem          ENUM('manual','ia') NOT NULL DEFAULT 'manual',
  legenda         TEXT         NULL,
  fonte_nome      VARCHAR(160) NULL,
  fonte_url       VARCHAR(512) NULL,
  capa_arquivo    VARCHAR(255) NULL,   -- Capa (poster) para vídeos/reels
  agendado_para   DATETIME     NOT NULL,
  status          ENUM('aguardando_aprovacao','agendado','processando','publicado','erro','cancelado','reprovado') NOT NULL DEFAULT 'agendado',
  ig_container_id VARCHAR(60)  NULL,   -- ID do container gerado na Meta
  ig_media_id     VARCHAR(60)  NULL,   -- ID da mídia publicada final
  erro_msg        VARCHAR(500) NULL,
  tentativas      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ia_dados        TEXT         NULL,   -- JSON auxiliar da IA (título original, etc)
  publicado_em    DATETIME     NULL,
  criado_por      INT UNSIGNED NULL,
  criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pub_cliente (cliente_id),
  KEY idx_pub_agenda (status, agendado_para),
  CONSTRAINT fk_pub_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Mídias individuais das publicações (Suporta carrosséis de até 10 imagens)
CREATE TABLE IF NOT EXISTS publicacao_midia (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  publicacao_id   INT UNSIGNED NOT NULL,
  posicao         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  tipo            ENUM('imagem','video') NOT NULL DEFAULT 'imagem',
  arquivo         VARCHAR(255) NOT NULL,   -- Caminho local relativo no servidor
  url_publica     VARCHAR(512) NOT NULL,   -- URL absoluta acessível pela Meta para baixar
  ig_container_id VARCHAR(60)  NULL,
  PRIMARY KEY (id),
  KEY idx_midia_pub (publicacao_id),
  CONSTRAINT fk_midia_pub FOREIGN KEY (publicacao_id) REFERENCES publicacoes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Configurações globais (Módulo IA)
CREATE TABLE IF NOT EXISTS configuracoes (
  chave         VARCHAR(80)  NOT NULL,
  valor         TEXT         NULL,
  atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Controle de custos de IA
CREATE TABLE IF NOT EXISTS custos_ia (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  publicacao_id INT UNSIGNED NULL,
  tipo          ENUM('imagem','texto') NOT NULL DEFAULT 'imagem',
  modelo        VARCHAR(60)  NOT NULL DEFAULT '',
  descricao     VARCHAR(200) NULL,
  tokens_in     INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_out    INT UNSIGNED NOT NULL DEFAULT 0,
  custo_usd     DECIMAL(12,6) NOT NULL DEFAULT 0,
  criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_custos_data (criado_em),
  KEY idx_custos_pub (publicacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) Notícias usadas (Anti-repetição)
CREATE TABLE IF NOT EXISTS noticias_usadas (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  hash      CHAR(40)     NOT NULL,   -- SHA1 do título + link
  titulo    VARCHAR(300) NULL,
  link      VARCHAR(512) NULL,
  fonte     VARCHAR(160) NULL,
  criado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_noticia_hash (hash),
  KEY idx_noticia_data (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) Caixa de Mensagens Directs (CRM / SAC Automático)
CREATE TABLE IF NOT EXISTS dm_conversas (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_id      INT UNSIGNED NOT NULL,
  remetente_id    VARCHAR(60)  NOT NULL,
  nome            VARCHAR(160) NULL,
  ultima_msg      VARCHAR(500) NULL,
  ultima_em       DATETIME     NULL,
  ultima_recebida_em DATETIME  NULL,
  nao_lidas       INT UNSIGNED NOT NULL DEFAULT 0,
  auto_ativo      TINYINT(1)   NOT NULL DEFAULT 1,
  criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dm_conv (cliente_id, remetente_id),
  CONSTRAINT fk_dm_conv_cli FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_mensagens (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversa_id BIGINT UNSIGNED NOT NULL,
  direcao     ENUM('in','out') NOT NULL,
  texto       TEXT         NULL,
  mid         VARCHAR(190) NULL,
  origem      ENUM('humano','auto_palavra','auto_ia') NOT NULL DEFAULT 'humano',
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dm_mid (mid),
  CONSTRAINT fk_dm_msg_conv FOREIGN KEY (conversa_id) REFERENCES dm_conversas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dm_regras (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gatilho   VARCHAR(190) NOT NULL,
  resposta  TEXT         NOT NULL,
  ativo     TINYINT(1)   NOT NULL DEFAULT 1,
  posicao   SMALLINT     NOT NULL DEFAULT 0,
  criado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 🗺️ 3. Mapa de Arquivos do Projeto

### Núcleo e Login:
* **`config.php`:** Definição de credenciais de banco (fixas), `BASE_URL` (essencial para Meta baixar mídias), versão do Graph, IDs de App e fuso horário.
* **`init.php`:** Kernel do app. Define a conexão global PDO (`db()`), gerenciamento de sessão, proteção CSRF (`csrf_field()`, `csrf_ok()`), sanitização `e()`, manipulação de avatares, e **toda a integração Meta Graph** (`graph_get()`, `graph_post()`, validação e renovação de tokens).
* **`login.php` / `logout.php`:** Controle de acesso por session e criptografia de senhas com `password_hash`.

### Geração de Mídia e Automação:
* **`lib_noticias.php`:** Coleta e lê feeds RSS (G1, Gazeta de AL, Alagoas 24 Horas), normaliza os campos e previne duplicidade.
* **`lib_materia.php`:** Scraper de páginas web que extrai metadados (`og:image`, `lead`, `titulo`) caso o RSS venha incompleto.
* **`lib_arte_codigo.php`:** Renderiza artes por código usando GD. Combina 4 templates visuais e as fotos de fundo de ônibus (`modelosdeimagensdentrodeonibus/`) montando a notícia na moldura de uma "TV no ônibus".
* **`lib_arte.php`:** Composição da notícia sobre uma tela verde quando operado em modo IA (DALL-E) + utilitários GD.
* **`lib_video.php`:** Se áudio estiver habilitado e houver trilha, combina a arte JPG estática com a trilha sonora MP4 gerando um vídeo via `ffmpeg` no terminal do servidor Linux.
* **`lib_gerador.php`:** Orquestrador principal. Escolhe formato (feed: 1080x1350 ou story: 1080x1920), chama os geradores de imagem e o renderizador de vídeo.
* **`lib_agendador.php`:** Algoritmo matemático para distribuir posts dinamicamente.

### Publicação e Cronjobs:
* **`lib_publicador.php`:** Máquina de estados resiliente para publicar no Instagram. Trata uploads de vídeo que demandam processamento assíncrono (polling via `ig_aguardar()`) e lida com rate limits.
* **`cron_noticias.php`:** CLI cron executado a cada minuto. Captura novos RSS e cria rascunhos automáticos na fila.
* **`cron_publicar.php`:** CLI cron executado a cada minuto que localiza publicações com status `agendado` vencidas e envia para a Meta.
* **`cron_stories.php`:** Captura os Stories publicados nas últimas 24h para manter um histórico perpétuo de estatísticas no painel administrativo (já que a Meta deleta os dados após 24h).

### Painel e Interfaces de Usuário:
* **`dashboard.php`:** Estatísticas globais, atalhos rápidos de fila e controle financeiro de consumo da API da OpenAI.
* **`agendados.php`:** Fila dinâmica dos próximos posts. Permite pré-visualizar (modal), editar legenda, **fazer o download da postagem** e acionar o gatilho **Postar agora** via AJAX.
* **`aprovacao.php`:** Fluxo de 2 etapas usado quando `ia_fluxo=aprovacao` (revisão de manchete e posterior revisão da imagem/arte criada antes de enviar à fila).
* **`config_ia.php`:** Definições completas do motor IA (parâmetros de imagens, preços, feeds e cotação de moedas).

---

## ⚙️ 4. Fluxos de Lógica Crítica

### A. Algoritmo de Fracionamento de Horário (Intervalo Dinâmico)
Em `lib_agendador.php`, para garantir que 200 matérias coletadas não causem spam e sejam distribuídas harmonicamente:
1. Define-se a janela permitida (`ia_hora_inicio` até `ia_hora_fim`).
2. Obtém-se o teto máximo de posts diários (`ia_max_dia`).
3. Calcula-se a quantidade de minutos restantes na janela do dia atual.
4. Calcula-se o intervalo dinâmico ideal:
   $$\text{Intervalo} = \max\left(\text{ia\_min\_intervalo}, \frac{\text{Minutos Restantes}}{\text{Slots Livres Hoje}}\right)$$
5. O sistema agenda apenas dentro do `ia_buffer` (ex: mantém apenas 6 posts criados futuros na fila). À medida que posts vão sendo publicados, novos slots são preenchidos, evitando poluição visual caso o usuário recuse posts em massa.

### B. Tokens de Acesso Meta (Roteamento Inteligente)
Em `init.php`, a função `graph_host()` analisa o prefixo do Token salvo no cliente:
* **Token prefixado com `IG...`**: Originado de Instagram Login.
  * *Rota:* `graph.instagram.com`
  * *Peculiaridade:* Não permite postagem de Story via API oficial. Suporta apenas Feed/Reels.
* **Token prefixado com `EAA...`**: Originado de Facebook Login (Business).
  * *Rota:* `graph.facebook.com`
  * *Peculiaridade:* Permite publicação completa de Stories, Reels, Feed e Carrosséis.

**Renovação de Tokens:** O cron `cron_publicar.php` verifica tokens que expiram em menos de 10 dias e faz o refresh automático junto aos servidores da Meta trocando pelo Token de Longa Duração (60 dias).

### C. Máquina de Estados do Publicador de Vídeos (Idempotência)
Como vídeos levam tempo para serem processados na Meta:
1. `cron_publicar.php` seleciona posts com status `agendado` ou `processando`.
2. Se o post for novo, cria-se o container de vídeo no Instagram via API e armazena-se o ID resultante em `ig_container_id`, marcando o status como `processando`.
3. Em requisições de minutos subsequentes, o sistema detecta que já existe um `ig_container_id` e chama `ig_aguardar()`.
4. Uma vez que o status retorne `FINISHED`, o container é finalmente publicado e o post é marcado como `publicado`. Isso evita duplicações por timeout do servidor.

---

## 🛠️ 5. Recursos Adicionados (Download, Postagem Manual, Limpeza de Mídia e Tratamento de Atrasados)

### A. Como o "Postar Agora" ignora o Throttle com Segurança:
Em `lib_publicador.php`, a função `processar_publicacao` contém um bypass opcional:
```php
function processar_publicacao(PDO $db, array $p, bool $forcar = false): array {
    // ...
    // THROTTLE: espaça as publicações que vão ao ar para evitar rate limit.
    if (!$forcar) {
        $desde = pub_segundos_desde_ultima();
        if ($desde < PUB_INTERVALO_MINIMO_SEG) {
            return ['estado' => 'pendente', 'msg' => 'aguardando intervalo'];
        }
    }
    // ... publicação na Meta ...
}
```
No painel de `agendados.php`, ao disparar o POST com `acao="postar_agora"`, o backend carrega `processar_publicacao($db, $p, true)`, publicando o item instantaneamente.

### B. Como funciona o "Baixar Postagem":
O painel administrativo usa o link direto para a URL da mídia salva em disco (`publicacao_midia.arquivo`) mapeada via `asset_v()`. A tag de download nativa do HTML5 é declarada:
```html
<a class="btn-ghost" href="<?= e($mediaUrl) ?>" download="post-<?= $p['id'] ?>" target="_blank">Baixar</a>
```
Isso é replicado perfeitamente no Card Principal de Agendamentos, no Painel Lateral de Pré-visualização do Modal de Agendados, e na tela de revisão de artes recém-geradas de `aprovacao.php`.

### C. Limpeza Física Automática de Mídias (Evitando Acúmulo de Armazenamento):
Imediatamente após uma publicação bem-sucedida, o sistema executa a deleção física das imagens, vídeos e pôsters de capa do servidor, mantendo as pastas limpas:
```php
// No sucesso da publicação:
try {
    $stM = $db->prepare('SELECT arquivo FROM publicacao_midia WHERE publicacao_id=?');
    $stM->execute([$pubId]);
    foreach ($stM->fetchAll() as $m) {
        $f = __DIR__ . '/' . ltrim((string) $m['arquivo'], '/');
        if (is_file($f)) { @unlink($f); }
    }
    if (!empty($p['capa_arquivo'])) {
        $fCapa = __DIR__ . '/' . ltrim((string) $p['capa_arquivo'], '/');
        if (is_file($fCapa)) { @unlink($fCapa); }
    }
    $pastaPub = __DIR__ . '/uploads/publicacoes/' . $pubId;
    if (is_dir($pastaPub)) { @rmdir($pastaPub); }
} catch (Throwable $e) {}
```

### D. Tratamento Automático de Postagens Atrasadas (Conversão para "Erro"):
Se uma publicação agendada perdeu o horário devido à automação estar pausada ou ao cron inativo por mais de 15 minutos, ela é automaticamente movida para o status `"erro"` (através da função `pub_marcar_atrasados_como_erro()`), sumindo da fila de pendentes e aparecendo em **Erros** (`erros.php`), onde o usuário pode clicar em **Republicar** assim que o sistema normalizar:
```php
function pub_marcar_atrasados_como_erro(PDO $db): int {
    $st = $db->prepare('UPDATE publicacoes
        SET status="erro", erro_msg="Atrasado / Não publicado no horário agendado (automação pausada ou cron inativo)", tentativas=tentativas+1
        WHERE status IN ("agendado", "processando") AND agendado_para < (NOW() - INTERVAL 15 MINUTE)');
    $st->execute();
    return $st->rowCount();
}
```
Esta função é executada de forma inteligente:
1. No carregamento da página de **Agendados** (`agendados.php`).
2. No carregamento da página de **Erros** (`erros.php`).
3. No cronjob principal de publicação (`cron_publicar.php`), logo no início do script.

---

## 🚀 6. Guia de Reconstrução do Sistema (File-by-File Blueprint)

Para recriar o sistema do zero absoluto em qualquer outro servidor, execute o seguinte roteiro:

1. **Passo 1: Banco de Dados**
   Crie um banco chamado `agendamentos-meta` e execute o esquema SQL detalhado no item **2**. Insira um usuário administrador inicial encriptado usando criptografia nativa no PHP (`password_hash`).

2. **Passo 2: Arquivo de Configuração**
   Crie o arquivo `config.php` definindo constantes globais de fuso horário, credenciais de banco fixas e chaves de API necessárias (`OPENAI_API_KEY`).

3. **Passo 3: Arquivo Kernel (`init.php`)**
   Crie funções centrais como `db()` usando o padrão Singleton de conexão PDO. Implemente sanitização HTML, validação de tokens e chamadas cURL para a Graph API.

4. **Passo 4: Criação do Motor de Artes GD (`lib_arte_codigo.php`)**
   Carregue imagens de base usando `imagecreatefromjpeg`. Use funções como `imagecopyresampled` e `imagettftext` para desenhar dinamicamente manchetes do RSS, datas de publicação e a marca `@tevinobuzao` dentro das caixas delimitadas de topo/rodapé (zonas seguras).

5. **Passo 5: Escalonador e Publicador (`lib_agendador.php`, `lib_publicador.php`)**
   Codifique o loop de publicação do Instagram e a fila de fracionamento diária. Garanta que o método de upload de vídeos envie payloads respeitando os requisitos da Meta (`media_type=VIDEO`, `video_state` query).

6. **Passo 6: Scripts de CLI (Crontabs)**
   Escreva `cron_noticias.php` e `cron_publicar.php`. Use a diretiva `flock` nativa do PHP no início de cada execução CLI para prevenir instâncias concorrentes.

7. **Passo 7: Criação da Interface Visual (`app.css`, `partials/head.php`, `agendados.php`)**
   Desenhe o visual clean do painel: barra lateral fixa contendo o indicador de versão, dashboard com cards, tabela de agendados com suporte a formulários AJAX, downloads com a diretiva `download` nas tags `<a>` e modais para pré-visualização interativa.

---

### 🌟 Ao usar esta skill com o Claude/Gemini:
Forneça as diretrizes deste arquivo e peça:
* *"Com base na Skill de Agendamento Instagram, implemente uma rota para..."*
* *"Recrie o arquivo lib_publicador.php seguindo fielmente a máquina de estados..."*
* *"Otimize o frontend em agendados.php para suportar..."*
O agente irá operar com o contexto exato e histórico técnico do sistema.
