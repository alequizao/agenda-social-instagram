# 📱 Agenda Social — publicação automática no Instagram

Painel em **PHP 7.4 + MySQL** que descobre notícias por RSS, compõe as artes por código
(GD) ou por IA, agenda e **publica automaticamente no Instagram** via Meta Graph API —
feed, carrossel, story e reel — além de responder o Direct e informar horários de ônibus
em tempo real.

## ✨ Funcionalidades

### Publicação no Instagram
- **Agendamento** de feed, carrossel (até 10 mídias), story e reel
- Publicação automática por cron, com **throttle e limite diário** por conta
- **Multi-perfil**: várias contas do Instagram no mesmo painel, cada uma com seu token
- Fila com reprocessamento e registro de erros

### Mesa de Redação (curadoria)
- Descoberta de notícias por **RSS**, com classificação por tema
- Filtro em duas camadas: palavras-chave e, na dúvida, **IA**
- **Nada vai ao ar sem aprovação do editor** — exceto os avisos de ônibus
- Geração da arte no momento da aprovação (GD ou `gpt-image`)

### Direct automatizado
- Conversas estilo Instagram, com mídia e áudio
- **Respostas automáticas** por palavra-chave ou por IA
- Auto-link da matéria quando alguém responde um story

### Horário dos Ônibus (Maceió e Rio Largo)
- Rota e **previsão de chegada ao vivo**, sem IA
- Mini-app público com mapa e alerta *"seu ônibus está chegando"*
- Notificações por Direct ou **Web Push (VAPID)**

## 📸 Telas

[![Agenda Social — painel de agendamento e publicação automática no Instagram, desenvolvido por Alex Junior (alequizao)](https://image.thum.io/get/width/700/https://publishdev.com.br/agendamentos/)](https://publishdev.com.br/agendamentos/)

## 🧱 Stack

| Camada | Tecnologia |
|---|---|
| Backend | PHP 7.4 (sem framework) |
| Banco | MySQL dedicado (`agendamentos-meta`) |
| Publicação | Meta Graph API (Instagram Business) |
| IA | OpenAI (classificação de temas, textos e `gpt-image`) |
| Mídia | GD para artes, `ffmpeg` para vídeo (só no cron) |
| Front | HTML/CSS/JS puro, PWA |

## 📦 Manual de instalação

### Requisitos

| Componente | Versão | Observação |
|---|---|---|
| PHP | **7.4** | com `pdo_mysql`, `gd`, `curl`, `mbstring` |
| MySQL / MariaDB | 5.7+ / 10.3+ | banco dedicado, `utf8mb4` |
| ffmpeg | qualquer | apenas para reels — usado só pelo cron |
| Conta Meta | — | Instagram Business + app na Meta for Developers |

### 1. Arquivos e banco

```bash
git clone https://github.com/alequizao/agenda-social-instagram.git
cd agenda-social-instagram
```

```sql
CREATE DATABASE `agendamentos-meta` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Configurar

```bash
cp config.example.php config.php
```

Preencha no `config.php`: dados do banco, `IG_APP_ID` / `IG_APP_SECRET` do app da Meta,
a chave da OpenAI e o `CRON_TOKEN`. **Esse arquivo não vai para o Git.**

### 3. Criar as tabelas

```bash
php install.php          # ou importe database.sql e os migracao_*.sql em ordem
```

### 4. Conectar uma conta do Instagram

Acesse **Clientes → Conexão IG**, cole o token da Meta e o sistema descobre sozinho o
`ig_user_id` e o `username`. O token precisa dos escopos de publicação e de mensagens.

> A conta precisa ser **Instagram Business** vinculada a uma página do Facebook — contas
> pessoais não publicam pela API.

### 5. Agendar os crons

```cron
* * * * *  php /caminho/cron_publicar.php   >/dev/null 2>&1
*/10 * * * * php /caminho/cron_noticias.php >/dev/null 2>&1
* * * * *  php /caminho/cron_onibus.php     >/dev/null 2>&1
```

Cada cron usa `flock` e grava em `logs/`, respeitando a pausa geral da automação.

### 6. Webhook do Direct

Aponte o webhook do app da Meta para `https://seu-dominio/webhook.php` e assine os
eventos de mensagens. Sem isso o Direct não recebe nada.

## 🔐 Segurança

`config.php`, os tokens das contas, os uploads das artes publicadas, os logs e os dumps
do banco **ficam fora do versionamento**. O repositório traz apenas o código.

> A documentação técnica completa — banco, mapa de arquivos, fluxos e armadilhas — está
> em [`SISTEMA.md`](SISTEMA.md).

---

## 👨‍💻 Desenvolvedor

Sistema **desenvolvido sob encomenda** por **Alex Junior (alequizao)** — Analista e
Desenvolvedor de Sistemas em Maceió, Alagoas, Brasil. Programador na **Publish Digital**.

- **E-mail:** alequizao.dev@gmail.com
- **WhatsApp:** [(82) 98871-7072](https://wa.me/5582988717072)
- **Instagram:** [@alequizao](https://instagram.com/alequizao)
- **GitHub:** [@alequizao](https://github.com/alequizao) · [perfil completo](https://github.com/alequizao/alequizao)
- **Site:** [alequizao.com](https://alequizao.com)

Precisa de um sistema sob medida para o seu negócio? Entre em contato.

---

© Código proprietário, desenvolvido sob encomenda. Uso, cópia ou redistribuição
somente com autorização.
