<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require __DIR__ . '/config.php';

/* ---- Limites de runtime (o php.ini do servidor vinha com memory_limit=1024 bytes,
   o que estourava ao processar a imagem da IA com GD -> erro 500). Elevar e seguro. ---- */
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '300');

/* ---- Sessao segura (SOMENTE no contexto web) ----
   CLI/cron NUNCA deve abrir sessao: os crons rodam como root e o GC do PHP
   (gc_probability=1/1000, gc_maxlifetime=1440s) apagaria os arquivos de sessao
   do usuario web (www) em /tmp -> usuario "deslogado" / "CSRF invalido" ao salvar. */
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // O padrao do servidor e 1440s (24min), curto demais: estende p/ 8h.
    ini_set('session.gc_maxlifetime', '28800');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

/* ---- Conexao (singleton) ---- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '-03:00'");
    }
    return $pdo;
}

/* ---- Helpers ---- */
function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/* ---- Rotacao de log (24/7): se o arquivo passar de $maxMB, mantem so as
   ultimas $manter linhas. Evita que os logs do cron encham o disco. ---- */
function log_rotacionar(string $arquivo, int $maxMB = 5, int $manter = 3000): void
{
    if (!is_file($arquivo) || filesize($arquivo) < $maxMB * 1024 * 1024) {
        return;
    }
    $linhas = @file($arquivo, FILE_IGNORE_NEW_LINES);
    if ($linhas === false) {
        return;
    }
    $tail = array_slice($linhas, -$manter);
    $tmp  = $arquivo . '.tmp';
    if (@file_put_contents($tmp, implode("\n", $tail) . "\n", LOCK_EX) !== false) {
        @rename($tmp, $arquivo);
    }
}

/* ---- Configuracoes (tabela chave/valor) ---- */
function cfg_get(string $chave, ?string $padrao = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT chave, valor FROM ' . DB_PREFIX . 'configuracoes')->fetchAll() as $r) {
            $cache[$r['chave']] = $r['valor'];
        }
    }
    return array_key_exists($chave, $cache) ? $cache[$chave] : $padrao;
}

function cfg_set(string $chave, string $valor): void
{
    db()->prepare('INSERT INTO ' . DB_PREFIX . 'configuracoes (chave, valor) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$chave, $valor]);
}

/* ---- Liga/desliga GERAL da automação (botão manual; persiste até religar).
   Pausa os crons de postagem/geração. Separado da pausa automática de rate limit. ---- */
function automacao_pausada(): bool
{
    return cfg_get('automacao_pausada', '0') === '1';
}

/* Lista de feeds RSS configurada (array de ['nome'=>,'url'=>]). */
function cfg_feeds(): array
{
    $j = json_decode((string) cfg_get('ia_feeds', '[]'), true);
    return is_array($j) ? $j : [];
}

/* ---- Configuração POR PERFIL (cliente). Cai para a global se não houver no perfil. ---- */
function pcfg_get(int $clienteId, string $chave, ?string $padrao = null): ?string
{
    static $cache = [];
    if (!isset($cache[$clienteId])) {
        $cache[$clienteId] = [];
        $st = db()->prepare('SELECT chave, valor FROM ' . DB_PREFIX . 'config_perfil WHERE cliente_id = ?');
        $st->execute([$clienteId]);
        foreach ($st->fetchAll() as $r) {
            $cache[$clienteId][$r['chave']] = $r['valor'];
        }
    }
    if (array_key_exists($chave, $cache[$clienteId])) {
        return $cache[$clienteId][$chave];
    }
    return cfg_get($chave, $padrao); // fallback global
}

function pcfg_set(int $clienteId, string $chave, string $valor): void
{
    db()->prepare('INSERT INTO ' . DB_PREFIX . 'config_perfil (cliente_id, chave, valor) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$clienteId, $chave, $valor]);
}

/* Feeds RSS do PERFIL (cliente). */
function pcfg_feeds(int $clienteId): array
{
    $j = json_decode((string) pcfg_get($clienteId, 'ia_feeds', '[]'), true);
    return is_array($j) ? $j : [];
}

/* Clientes com automação ligada no próprio perfil (ia_ativo=1). */
function perfis_ativos(PDO $db): array
{
    return $db->query('SELECT c.id, c.nome, c.ig_user_id, c.access_token
        FROM ' . DB_PREFIX . 'clientes c
        JOIN ' . DB_PREFIX . 'config_perfil p ON p.cliente_id = c.id AND p.chave = "ia_ativo" AND p.valor = "1"
        ORDER BY c.id')->fetchAll();
}

/* ---- Visibilidade de seções da interface (toggles em Configuração) ----
   chave => [rótulo amigável, ligado por padrão?]. Tudo aparece por padrão. */
function ui_secoes(): array
{
    return [
        'ui_stats_topo'   => ['Cartões de estatística no topo do painel', true],
        'ui_card_stats'   => ['Números do perfil no card (seguidores / posts / seguindo)', true],
        'ui_card_rodape'  => ['Rodapé do card (agendadas + alcance 7 dias)', true],
        'ui_card_alcance' => ['Selo de alcance dos últimos 7 dias no card', true],
    ];
}

/* Retorna true se a seção da interface deve ser exibida. */
function ui_ver(string $chave): bool
{
    $def = ui_secoes()[$chave][1] ?? true;
    return cfg_get($chave, $def ? '1' : '0') === '1';
}

function asset_v(string $path): string
{
    $full = __DIR__ . '/' . ltrim($path, '/');
    $v = is_file($full) ? filemtime($full) : time();
    return $path . '?v=' . $v;
}

/* Caminho da PRÉVIA (imagem) de uma mídia: se for vídeo, usa o pôster .jpg gerado ao lado
   (a arte original). Se não houver pôster, devolve o próprio arquivo. */
function midia_preview(string $arquivoRel): string
{
    if (preg_match('/\.(mp4|mov|webm|m4v)$/i', $arquivoRel)) {
        $jpg = preg_replace('/\.(mp4|mov|webm|m4v)$/i', '.jpg', $arquivoRel);
        if (is_file(__DIR__ . '/' . ltrim($jpg, '/'))) {
            return $jpg;
        }
    }
    return $arquivoRel;
}

/* É um arquivo de imagem (extensão)? */
function midia_eh_imagem(string $arquivoRel): bool
{
    return (bool) preg_match('/\.(jpe?g|png|gif|webp)$/i', $arquivoRel);
}

/* ---- CSRF ---- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_ok(?string $t): bool
{
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

/* ---- Autenticacao ---- */
function usuario_logado(): ?array
{
    return $_SESSION['user'] ?? null;
}

function exigir_login(): void
{
    if (!usuario_logado()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function tentar_login(string $email, string $senha): bool
{
    $st = db()->prepare('SELECT id, nome, email, senha_hash, papel, ativo FROM ' . DB_PREFIX . 'usuarios WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();

    $sucesso = $u && (int) $u['ativo'] === 1 && password_verify($senha, $u['senha_hash']);

    $log = db()->prepare('INSERT INTO ' . DB_PREFIX . 'login_log (email, ip, sucesso) VALUES (?, ?, ?)');
    $log->execute([$email, $_SERVER['REMOTE_ADDR'] ?? '', $sucesso ? 1 : 0]);

    if (!$sucesso) {
        return false;
    }

    if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
        $up = db()->prepare('UPDATE ' . DB_PREFIX . 'usuarios SET senha_hash = ? WHERE id = ?');
        $up->execute([password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'    => (int) $u['id'],
        'nome'  => $u['nome'],
        'email' => $u['email'],
        'papel' => $u['papel'],
    ];

    $up = db()->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?');
    $up->execute([$u['id']]);

    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ---- Throttle simples por sessao ---- */
function login_bloqueado(): bool
{
    $j = $_SESSION['login_try'] ?? null;
    if (!$j) {
        return false;
    }
    if (time() - $j['ts'] > LOGIN_JANELA_SEG) {
        unset($_SESSION['login_try']);
        return false;
    }
    return $j['n'] >= LOGIN_MAX_TENTATIVAS;
}

function registrar_tentativa_falha(): void
{
    $j = $_SESSION['login_try'] ?? ['n' => 0, 'ts' => time()];
    if (time() - $j['ts'] > LOGIN_JANELA_SEG) {
        $j = ['n' => 0, 'ts' => time()];
    }
    $j['n']++;
    $j['ts'] = time();
    $_SESSION['login_try'] = $j;
}

function limpar_tentativas(): void
{
    unset($_SESSION['login_try']);
}

/* ---- Utilidades ---- */
function slugify(string $txt): string
{
    $txt = trim($txt);
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
        if ($conv !== false) {
            $txt = $conv;
        }
    }
    $txt = strtolower($txt);
    $txt = preg_replace('/[^a-z0-9]+/', '-', $txt) ?? '';
    $txt = trim($txt, '-');
    return $txt !== '' ? $txt : 'cliente';
}

function cor_hex(?string $cor, string $fallback = '#168bf5'): string
{
    $cor = trim((string) $cor);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $cor) ? strtolower($cor) : $fallback;
}

function iniciais(string $nome, int $n = 2): string
{
    $ini = '';
    foreach (preg_split('/\s+/', trim($nome)) as $p) {
        if ($p !== '') {
            $ini .= mb_substr($p, 0, 1);
        }
    }
    return mb_strtoupper(mb_substr($ini, 0, $n));
}

function avatar_cliente(array $c, string $classe): string
{
    $cor  = cor_hex($c['cor_marca'] ?? null);
    $foto = (string) ($c['foto_perfil'] ?? '');
    $temFoto = $foto !== '' && is_file(__DIR__ . '/' . $foto);
    $bg = 'background:linear-gradient(135deg,' . $cor . ',color-mix(in srgb,' . $cor . ' 55%,#000));';

    $html = '<div class="' . e($classe) . '" style="' . $bg . '">';
    if ($temFoto) {
        $html .= '<img src="' . e(asset_v($foto)) . '" alt="">';
    } else {
        $html .= e(iniciais($c['nome'] ?? '?'));
    }
    $html .= '</div>';
    return $html;
}

function salvar_avatar(array $file, string $slug): ?string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return null;
    }

    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null) {
        return null;
    }

    $dir = __DIR__ . '/uploads/clientes';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $nome = $slug . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destino = $dir . '/' . $nome;
    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        return null;
    }
    return 'uploads/clientes/' . $nome;
}

/* ---- Midia de publicacao (imagem ou video) ---- */
function salvar_midia_post(array $file, int $clienteId): ?array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($file['size'] ?? 0) > 200 * 1024 * 1024) {
        return null;
    }

    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($file['tmp_name']);
    $img = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $vid = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov'];

    if (isset($img[$mime])) {
        $tipo = 'imagem';
        $ext = $img[$mime];
    } elseif (isset($vid[$mime])) {
        $tipo = 'video';
        $ext = $vid[$mime];
    } else {
        return null;
    }

    $dir = __DIR__ . '/uploads/publicacoes/' . $clienteId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $nome = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destino = $dir . '/' . $nome;
    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        return null;
    }
    return ['arquivo' => 'uploads/publicacoes/' . $clienteId . '/' . $nome, 'tipo' => $tipo];
}

/* ---- Datas em portugues ---- */
function meses_pt(): array
{
    return [1 => 'Janeiro', 'Fevereiro', 'Marco', 'Abril', 'Maio', 'Junho',
            'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
}

function rotulo_tipo(string $tipo): string
{
    return ['feed' => 'Feed', 'carrossel' => 'Carrossel', 'story' => 'Story', 'reel' => 'Reels'][$tipo] ?? $tipo;
}

/* ---- Graph API (Meta) ---- */
/* Escolhe o host conforme o tipo de token:
   IG... (Instagram API com Login do Instagram) -> graph.instagram.com
   EAA.. (Facebook Login)                        -> graph.facebook.com */
function graph_host(array $params): string
{
    $t = (string) ($params['access_token'] ?? '');
    return (stripos($t, 'IG') === 0) ? 'https://graph.instagram.com' : 'https://graph.facebook.com';
}

function graph_get(string $path, array $params, int $timeout = 25): array
{
    $url = graph_host($params) . '/' . GRAPH_VERSION . '/' . ltrim($path, '/');
    $url .= '?' . http_build_query($params);

    $body = null;
    $erroConexao = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            $erroConexao = 'Falha de conexao com a Meta: ' . $err;
            $body = null;
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            $erroConexao = 'Falha de conexao com a Meta.';
            $body = null;
        }
    }

    if ($body === null) {
        return ['ok' => false, 'erro' => $erroConexao ?: 'Falha de conexao com a Meta.'];
    }

    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'erro' => 'Resposta invalida da Meta.'];
    }
    if (isset($json['error'])) {
        return ['ok' => false, 'erro' => $json['error']['message'] ?? 'Erro retornado pela Meta.'];
    }
    return ['ok' => true, 'dados' => $json];
}

/* ---- Instagram Login: token de longa duracao (graph.instagram.com, sem versao) ---- */
function ig_get_json(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            return ['ok' => false, 'erro' => 'Falha de conexao: ' . $err];
        }
    } else {
        $body = @file_get_contents($url);
        if ($body === false) {
            return ['ok' => false, 'erro' => 'Falha de conexao.'];
        }
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'erro' => 'Resposta invalida da Meta.'];
    }
    if (isset($json['error'])) {
        return ['ok' => false, 'erro' => $json['error']['message'] ?? 'Erro retornado pela Meta.'];
    }
    return ['ok' => true, 'dados' => $json];
}

/* Troca um token curto por um de longa duracao (60 dias). Requer IG_APP_SECRET. */
function ig_trocar_token_longa(string $shortToken): array
{
    if (!defined('IG_APP_SECRET') || IG_APP_SECRET === '') {
        return ['ok' => false, 'erro' => 'App Secret nao configurado.'];
    }
    return ig_get_json('https://graph.instagram.com/access_token?' . http_build_query([
        'grant_type'    => 'ig_exchange_token',
        'client_secret' => IG_APP_SECRET,
        'access_token'  => $shortToken,
    ]));
}

/* Renova um token de longa duracao (estende por mais 60 dias). */
function ig_renovar_token(string $longToken): array
{
    return ig_get_json('https://graph.instagram.com/refresh_access_token?' . http_build_query([
        'grant_type'   => 'ig_refresh_token',
        'access_token' => $longToken,
    ]));
}

/* Estende um token do Facebook (EAA...) por mais 60 dias.
   Requer IG_APP_ID + IG_APP_SECRET preenchidos (.env.secreto). */
function fb_renovar_token(string $token): array
{
    if (IG_APP_ID === '' || IG_APP_SECRET === '') {
        return ['ok' => false, 'erro' => 'IG_APP_ID/IG_APP_SECRET nao configurados no .env.secreto.'];
    }
    return graph_get('oauth/access_token', [
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => IG_APP_ID,
        'client_secret'     => IG_APP_SECRET,
        'fb_exchange_token' => $token,
        'access_token'      => $token, // so define o host (graph.facebook.com)
    ]);
}

/* Pergunta a Meta quando o token expira (debug_token). Retorna timestamp ou 0. */
function token_expira_timestamp(string $token): int
{
    if ($token === '' || IG_APP_ID === '' || IG_APP_SECRET === '') {
        return 0;
    }
    if (stripos($token, 'IG') === 0) {
        return 0; // graph.instagram.com nao expoe debug_token
    }
    $r = graph_get('debug_token', [
        'input_token'  => $token,
        'access_token' => IG_APP_ID . '|' . IG_APP_SECRET,
    ]);
    if (empty($r['ok'])) {
        return 0;
    }
    return (int) ($r['dados']['data']['expires_at'] ?? 0);
}

/* Data de expiracao a gravar. null = nunca expira ou desconhecida. */
function token_data_expiracao(string $token, int $expiresIn = 0): ?string
{
    if ($expiresIn > 0) {
        return date('Y-m-d H:i:s', time() + $expiresIn);
    }
    $ts = token_expira_timestamp($token);
    return $ts > 0 ? date('Y-m-d H:i:s', $ts) : null;
}

/* Renova o token de um cliente (IG... ou EAA...) e ja grava no banco. */
function cliente_renovar_token(PDO $db, array $cli): array
{
    $token = trim((string) ($cli['access_token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'erro' => 'cliente sem token', 'expira' => null];
    }
    $r = (stripos($token, 'IG') === 0) ? ig_renovar_token($token) : fb_renovar_token($token);
    if (empty($r['ok']) || empty($r['dados']['access_token'])) {
        return ['ok' => false, 'erro' => (string) ($r['erro'] ?? 'a Meta nao devolveu token'), 'expira' => null];
    }
    $novo = (string) $r['dados']['access_token'];
    $exp  = token_data_expiracao($novo, (int) ($r['dados']['expires_in'] ?? 0));
    $db->prepare('UPDATE ' . DB_PREFIX . 'clientes SET access_token=?, token_expira_em=? WHERE id=?')
       ->execute([$novo, $exp, (int) $cli['id']]);
    return ['ok' => true, 'erro' => '', 'expira' => $exp];
}

/* Clientes com token vencido ou vencendo em ate N dias (aviso no painel). */
function tokens_em_risco(PDO $db, int $dias = 7): array
{
    try {
        $st = $db->prepare('SELECT id, nome, ig_username, token_expira_em,
                  (token_expira_em <= NOW()) AS vencido
             FROM ' . DB_PREFIX . 'clientes
            WHERE access_token IS NOT NULL AND access_token <> \'\'
              AND token_expira_em IS NOT NULL
              AND token_expira_em <= (NOW() + INTERVAL ? DAY)
            ORDER BY token_expira_em');
        $st->execute([$dias]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/* Le os dados do perfil IG (funciona com token IG... ou token de pagina FB). */
function ig_perfil(string $token, string $igId = '', int $timeout = 25): array
{
    $fields = 'user_id,username,name,account_type,profile_picture_url,followers_count,follows_count,media_count,biography,website';
    $path = (stripos($token, 'IG') === 0 || $igId === '') ? 'me' : $igId;
    return graph_get($path, ['fields' => $fields, 'access_token' => $token], $timeout);
}

/**
 * Cache genérico POR PERFIL de chamadas externas (Graph etc.), gravado em config_perfil.
 * Evita HTTP síncrono a cada load de página (causa estrutural de 500/504 — auditoria 22/06).
 *
 * - Dentro do TTL: devolve o cache sem tocar a rede.
 * - Expirado + $podeAtualizar: chama $busca() UMA vez; falha => mantém o cache velho
 *   e só re-tenta depois de 5 min (não martela a Meta a cada F5).
 * - $podeAtualizar=false: nunca vai à rede (usado p/ limitar refreshes por request).
 *
 * @param callable $busca fn(): array ['ok'=>bool,'dados'=>array,...]
 * @return array ['dados'=>?array, 'live'=>bool]  live=true quando houve chamada de rede
 */
function ig_cache_por_perfil(int $clienteId, string $chave, int $ttl, bool $podeAtualizar, callable $busca): array
{
    $em  = (string) pcfg_get($clienteId, $chave . '_em', '');
    $raw = (string) pcfg_get($clienteId, $chave . '_json', '');
    $dados = $raw !== '' ? (json_decode($raw, true) ?: null) : null;

    if (($em !== '' && (time() - strtotime($em)) < $ttl) || !$podeAtualizar) {
        return ['dados' => $dados, 'live' => false];
    }
    $tent = (string) pcfg_get($clienteId, $chave . '_tent', '');
    if ($tent !== '' && (time() - strtotime($tent)) < 300) {
        return ['dados' => $dados, 'live' => false]; // falhou há pouco: serve o velho
    }
    pcfg_set($clienteId, $chave . '_tent', date('Y-m-d H:i:s'));

    $r = $busca();
    if (!empty($r['ok']) && isset($r['dados']) && is_array($r['dados'])) {
        $dados = $r['dados'];
        pcfg_set($clienteId, $chave . '_json', json_encode($dados, JSON_UNESCAPED_UNICODE));
        pcfg_set($clienteId, $chave . '_em', date('Y-m-d H:i:s'));
    }
    return ['dados' => $dados, 'live' => true];
}

/* ---- Limite de publicacao da Meta (content_publishing_limit) ----
   Quantas publicacoes (feed/story/reel via API) ja foram feitas nas ultimas 24h
   e qual o teto da conta. Usado p/ o sistema se moldar e evitar bloqueios. */
function ig_limite_publicacao(string $token, string $igId = ''): array
{
    $path = (stripos($token, 'IG') === 0 || $igId === '') ? 'me' : $igId;
    $r = graph_get($path . '/content_publishing_limit', [
        'fields'       => 'config,quota_usage',
        'access_token' => $token,
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'erro' => $r['erro']];
    }
    $d = $r['dados']['data'][0] ?? $r['dados'];
    $usado = (int) ($d['quota_usage'] ?? 0);
    $total = (int) ($d['config']['quota_total'] ?? 0);
    return ['ok' => true, 'usado' => $usado, 'total' => $total, 'restante' => max(0, $total - $usado)];
}

/* Status do limite COM CACHE por perfil (config_perfil), TTL 10min, p/ nao bater
   na Graph a cada load/cron. $forcar=true ignora o cache. */
function pub_limite_status(int $clienteId, bool $forcar = false): array
{
    $em  = (string) pcfg_get($clienteId, 'pub_limite_em', '');
    $raw = (string) pcfg_get($clienteId, 'pub_limite_json', '');
    if (!$forcar && $raw !== '' && $em !== '' && (time() - strtotime($em)) < 600) {
        $j = json_decode($raw, true);
        if (is_array($j)) { $j['cache'] = true; return $j; }
    }
    $c = db()->prepare('SELECT access_token, ig_user_id FROM ' . DB_PREFIX . 'clientes WHERE id = ? LIMIT 1');
    $c->execute([$clienteId]);
    $cli = $c->fetch();
    if (!$cli || empty($cli['access_token'])) {
        return ['ok' => false, 'erro' => 'conta sem token'];
    }
    $r = ig_limite_publicacao((string) $cli['access_token'], (string) ($cli['ig_user_id'] ?? ''));
    if ($r['ok']) {
        pcfg_set($clienteId, 'pub_limite_json', json_encode($r));
        pcfg_set($clienteId, 'pub_limite_em', date('Y-m-d H:i:s'));
    }
    $r['cache'] = false;
    return $r;
}

/* ---- Insights (estatisticas de desempenho) ----
   Exigem a permissao instagram_manage_insights no token e conta Comercial/Criador.
   O parser aceita tanto o formato novo (total_value) quanto o antigo (values[]). */

/* Extrai um mapa nome=>valor de uma resposta /insights da Graph API. */
function ig_insights_extrair(array $dados): array
{
    $out = [];
    foreach (($dados['data'] ?? []) as $m) {
        $nome = (string) ($m['name'] ?? '');
        if ($nome === '') {
            continue;
        }
        if (isset($m['total_value']['value'])) {           // formato novo (metric_type=total_value)
            $out[$nome] = (int) $m['total_value']['value'];
        } elseif (isset($m['values']) && is_array($m['values'])) { // formato antigo (serie temporal)
            $soma = 0;
            foreach ($m['values'] as $v) {
                $soma += (int) ($v['value'] ?? 0);
            }
            $out[$nome] = $soma;
        }
    }
    return $out;
}

/* Resumo de desempenho da CONTA na janela de N dias (padrao 7).
   Retorna ['ok'=>bool,'dados'=>['reach'=>..,'profile_views'=>..,'accounts_engaged'=>..,'total_interactions'=>..]]. */
function ig_insights_conta(string $token, string $igId = '', int $periodoDias = 7, int $timeout = 25): array
{
    $alvo  = (stripos($token, 'IG') === 0 || $igId === '') ? 'me' : $igId;
    $until = time();
    $since = $until - max(1, $periodoDias) * 86400;

    $r = graph_get($alvo . '/insights', [
        'metric'       => 'reach,profile_views,accounts_engaged,total_interactions',
        'period'       => 'day',
        'metric_type'  => 'total_value',
        'since'        => $since,
        'until'        => $until,
        'access_token' => $token,
    ], $timeout);

    // Fallback p/ contas/versoes que recusam o conjunto acima: ao menos o alcance.
    if (!$r['ok']) {
        $erroOriginal = $r['erro'];
        $r = graph_get($alvo . '/insights', [
            'metric'       => 'reach',
            'period'       => 'days_28',
            'access_token' => $token,
        ], $timeout);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $erroOriginal];
        }
    }
    return ['ok' => true, 'dados' => ig_insights_extrair($r['dados'])];
}

/* Desempenho de UMA midia publicada (usa o ig_media_id salvo na publicacao).
   Retorna ['ok'=>bool,'dados'=>['reach'=>..,'likes'=>..,'comments'=>..,'saved'=>..,'shares'=>..,'total_interactions'=>..]]. */
function ig_insights_midia(string $token, string $mediaId): array
{
    $mediaId = trim($mediaId);
    if ($mediaId === '') {
        return ['ok' => false, 'erro' => 'Publicacao sem ID de midia.'];
    }
    $r = graph_get($mediaId . '/insights', [
        'metric'       => 'reach,likes,comments,saved,shares,total_interactions',
        'access_token' => $token,
    ]);
    // Fallback: tipos de midia que rejeitam alguma metrica (ex.: certos reels/stories).
    if (!$r['ok']) {
        $erroOriginal = $r['erro'];
        $r = graph_get($mediaId . '/insights', [
            'metric'       => 'reach',
            'access_token' => $token,
        ]);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $erroOriginal];
        }
    }
    return ['ok' => true, 'dados' => ig_insights_extrair($r['dados'])];
}

/* ---- Graph API POST (publicacao) ---- */
function graph_post(string $path, array $params): array
{
    $url  = graph_host($params) . '/' . GRAPH_VERSION . '/' . ltrim($path, '/');
    $post = http_build_query($params);

    $body = null;
    $erroConexao = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            $erroConexao = 'Falha de conexao com a Meta: ' . $err;
            $body = null;
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => $post,
            'timeout'       => 120,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            $erroConexao = 'Falha de conexao com a Meta.';
            $body = null;
        }
    }

    if ($body === null) {
        return ['ok' => false, 'erro' => $erroConexao ?: 'Falha de conexao com a Meta.'];
    }

    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'erro' => 'Resposta invalida da Meta.'];
    }
    if (isset($json['error'])) {
        return ['ok' => false, 'erro' => $json['error']['message'] ?? 'Erro retornado pela Meta.'];
    }
    return ['ok' => true, 'dados' => $json];
}
