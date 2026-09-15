<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * Agenda Social — Mesa de Redação (publicação de notícias no Instagram).
 *
 * Credenciais sensiveis sao lidas do .env FORA do webroot.
 * Ajuste BASE_URL/APP_URL se mudar de dominio ou pasta.
 *
 * Desenvolvido por @alequizao · (82) 98871-7072 · alexjuniorcalado@gmail.com
 */

/* ---- Le variaveis sensiveis do .env (fora do webroot) ---- */
$envFile = '/www/wwwroot/publishdev.com.br/publishdev.com.br.env';
$env = is_file($envFile) ? (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: []) : [];

/* ---- Banco de dados DEDICADO deste sistema (nao usa o .env compartilhado) ---- */
define('DB_HOST',    'SEU_VALOR_AQUI');
define('DB_NAME',    'SEU_VALOR_AQUI');
define('DB_USER',    'SEU_VALOR_AQUI');
define('DB_PASS',    'SEU_VALOR_AQUI');
define('DB_CHARSET', 'utf8mb4');

/* Banco dedicado: tabelas sem prefixo. */
define('DB_PREFIX', '');

/* ---- URL base ABSOLUTA (a Meta precisa baixar a midia por URL publica) ---- */
define('BASE_URL', 'https://publishdev.com.br/agendamentos');
define('APP_NAME', 'SEU_VALOR_AQUI');

/* ---- Versão do sistema + assinatura do desenvolvedor (exibidos na UI/manual) ---- */
define('APP_VERSAO', '3.10.2');
define('DEV_NOME',   '@alequizao');
define('DEV_FONE',   '(82) 98871-7072');
define('DEV_EMAIL',  'alexjuniorcalado@gmail.com');
define('DEV_CREDITO', 'Desenvolvido por @alequizao · (82) 98871-7072 · alexjuniorcalado@gmail.com');

/* ---- Graph API (Meta) ---- */
define('GRAPH_VERSION', 'v21.0');

/* ---- OpenAI (modulo de Noticias por IA) ----
   A chave fica no .env (fora do webroot): OPENAI_API_KEY=sk-... */
define('OPENAI_API_KEY', $env['OPENAI_API_KEY'] ?? '');
define('OPENAI_BASE',    'https://api.openai.com/v1');

/* ---- Instagram API com Login do Instagram (graph.instagram.com) ----
   Preencha para habilitar token de LONGA DURACAO (60 dias) + renovacao automatica.
   Pegue em developers.facebook.com -> seu app -> Instagram -> API setup. */
define('IG_APP_ID', '');
define('IG_APP_SECRET', 'SEU_VALOR_AQUI');

/* ---- Login / throttle ---- */
define('LOGIN_MAX_TENTATIVAS', 5);
define('LOGIN_JANELA_SEG', 900); // 15 min

/* ---- Token do gatilho web do cron (fallback, caso use cron externo) ---- */
define('CRON_TOKEN', $env['AGENDA_CRON_TOKEN'] ?? 'troque-este-token-no-env');

/* ---- App PÚBLICO de ônibus (Tevi no Buzão, na VPS alequizao.com) ----
   Quando preenchido, a automação do Direct manda ESTE link em vez do local.php
   daqui. O app de lá é público (não usa o token da conversa) e avisa a pessoa por
   notificação no aparelho (Web Push). Deixe vazio p/ voltar ao local.php interno. */
define('ONIBUS_APP_URL', 'https://alequizao.com/tevinobuzao');

/* ---- Fonte dos dados de ônibus (CittaMobi) — lidos do .env (credenciais) ---- */
define('ONIBUS_URL_LINHAS', $env['ONIBUS_URL_LINHAS'] ?? 'https://linhas.cittamobi.com.br');
define('ONIBUS_URL_CORE',   $env['ONIBUS_URL_CORE']   ?? 'https://core.cittamobi.com.br');
define('ONIBUS_TOKEN_ENV',  $env['ONIBUS_TOKEN']      ?? '');
define('ONIBUS_CIDADE_ENV', $env['ONIBUS_CIDADE_SLUG'] ?? '');

/* ---- Fuso horario ---- */
date_default_timezone_set('America/Maceio');

/* ---- Erros: ligue DEBUG so em desenvolvimento ---- */
define('DEBUG', false);
if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
