<?php
declare(strict_types=1);

/**
 * lib_arte_codigo.php
 * Arte "TV no Busão" 100% por código (GD), SEM IA. Vários TEMPLATES selecionáveis.
 * Estética telejornal urgente; SEMPRE exibe a FONTE da matéria.
 *
 * Design system (skill ui-ux-alequizao):
 *   amarelo-busão #FFC400 | vermelho-urgente #E11D2A | preto #0B0B0C
 *   branco #FFFFFF (manchete) | cinza #C9CDD6 (meta). Anton (manchete) + DejaVuSans (meta).
 *   Zonas seguras topo/rodapé p/ a UI do Instagram nos stories.
 *
 * Reaproveita arte_cover/arte_quebrar/arte_texto de lib_arte.php.
 */

require_once __DIR__ . '/lib_arte.php';
require_once __DIR__ . '/lib_materia.php';

const ARTEC_AMARELO  = [255, 196, 0];
const ARTEC_VERMELHO = [225, 29, 42];
const ARTEC_PRETO    = [11, 11, 12];
const ARTEC_BRANCO   = [255, 255, 255];
const ARTEC_CINZA    = [201, 205, 214];

/* Lista de templates disponíveis: id => rótulo. */
function artec_templates(): array
{
    return [
        1 => 'Telejornal (TV com moldura amarela)',
        2 => 'Capa cheia (foto imersiva + manchete embaixo)',
        3 => 'Split amarelo (foto em cima, painel preto embaixo)',
        4 => 'Plantão urgente (faixa vermelha)',
    ];
}

function artec_fonte_display(): string
{
    $anton = __DIR__ . '/fonts/Anton-Regular.ttf';
    return is_file($anton) ? $anton : ARTE_FONTE_BOLD;
}

/* Tema/categoria da matéria (selo da arte) inferido por palavras-chave do título. */
function artec_categoria(string $titulo, string $resumo = '', string $link = ''): string
{
    $t = mb_strtolower($titulo . ' ' . $resumo . ' ' . $link, 'UTF-8');
    $t = strtr($t, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
    $mapa = [
        'ESPORTE'   => ['futebol','jogo','gol','campeonato',' copa','atleta','partida','placar','csa','crb','asa ','selecao','volei','basquete','rodada','tecnico','estadio'],
        'POLICIAL'  => ['preso','prisao','tiro','baleado','morto','morte','morre','morrem','assalt','roubo','furto','droga','trafico','operacao','homicidio','acidente','crime','assassin','chacina','apreend','delegacia','bandid','atropel','colisao','capot','batida','afog','ferido','vitima'],
        'POLITICA'  => ['prefeit','governador','vereador','deputad','senador','eleicao','politic','governo','camara','senado','ministr','presidente','votacao','partido','camara municipal'],
        'ECONOMIA'  => ['economia','emprego','salario',' preco','inflacao','mercado','dolar','gasolina','imposto',' vaga','concurso','financ',' pix','aposentad'],
        'CULTURA'   => ['show','musica','festa','cantor','artista','festival','cinema','novela','bbb','forro','arraia','sao joao','teatro','cultura','live'],
        'CULINARIA' => ['receita','comida','culinaria','restaurante','prato','gastronomia','tapioca','bolo','sabor',' chef'],
        'SAUDE'     => ['saude','hospital','vacina','dengue','doenca','medico',' sus','surto','covid','samu',' upa'],
        'EDUCACAO'  => ['escola','universidade','ufal','enem','aluno','professor','educacao','ifal','matricula','bolsa'],
        'TEMPO'     => ['chuva','calor','temperatura','previsao','clima',' seca','frente fria','tempestade'],
        'TRANSITO'  => ['transito','onibus','transporte','rodovia','br-101','engarrafamento','tarifa','passagem'],
    ];
    foreach ($mapa as $cat => $palavras) {
        foreach ($palavras as $p) {
            if (mb_strpos($t, $p) !== false) {
                return $cat;
            }
        }
    }
    return 'AGORA';
}

/* Carrega uma foto de interior de ônibus (cenário de fundo). Retorna GD ou null.
   $qual: caminho específico relativo, ou '' p/ escolher uma da pasta padrão. */
function artec_fundo_onibus(string $qual = '')
{
    $base = __DIR__ . '/modelosdeimagensdentrodeonibus';
    $arq = '';
    if ($qual !== '') {
        $cand = __DIR__ . '/' . ltrim($qual, '/');
        if (is_file($cand)) { $arq = $cand; }
    }
    if ($arq === '' && is_dir($base)) {
        $fotos = glob($base . '/*.{jpg,jpeg,png}', GLOB_BRACE) ?: [];
        if ($fotos) { $arq = $fotos[array_rand($fotos)]; }
    }
    if ($arq === '' || !is_file($arq)) {
        return null;
    }
    $bin = @file_get_contents($arq);
    if ($bin === false) { return null; }
    $img = @imagecreatefromstring($bin);
    return $img !== false ? $img : null;
}

/* Retângulo de cantos arredondados. */
function artec_rrect($img, int $x1, int $y1, int $x2, int $y2, int $r, array $cor): void
{
    $c = imagecolorallocate($img, $cor[0], $cor[1], $cor[2]);
    $r = max(0, min($r, (int) (($x2 - $x1) / 2), (int) (($y2 - $y1) / 2)));
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $c);
    imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $c);
    imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $c);
    imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $c);
    imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $c);
    imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $c);
}

/* Gradiente vertical preto (alpha $aTopo→$aBase, 0=opaco..127=transp). */
function artec_gradiente_v($img, int $y1, int $y2, int $aTopo, int $aBase): void
{
    $W = imagesx($img);
    $h = max(1, $y2 - $y1);
    for ($y = $y1; $y <= $y2; $y++) {
        $a = (int) round($aTopo + ($aBase - $aTopo) * (($y - $y1) / $h));
        $a = max(0, min(127, $a));
        imagefilledrectangle($img, 0, $y, $W, $y, imagecolorallocatealpha($img, 0, 0, 0, $a));
    }
}

function artec_texto_sombra($img, string $txt, string $fonte, float $tam, int $x, int $y, array $cor): void
{
    $s = max(1, (int) round($tam * 0.05));
    arte_texto($img, $txt, $fonte, $tam, $x + $s, $y + $s, [0, 0, 0]);
    arte_texto($img, $txt, $fonte, $tam, $x, $y, $cor);
}

/* Texto centralizado na largura da imagem, no y (baseline) dado. */
function artec_texto_centro($img, string $txt, string $fonte, float $tam, int $yBaseline, array $cor): void
{
    $bb = imagettfbbox($tam, 0, $fonte, $txt);
    $tw = abs($bb[2] - $bb[0]);
    $x  = (int) ((imagesx($img) - $tw) / 2);
    artec_texto_sombra($img, $txt, $fonte, $tam, $x, $yBaseline, $cor);
}

/* Marca topo-esquerda: "TV NO BUSÃO" + selo "AGORA" à direita + @handle. */
function artec_marca($img, int $marg, int $yMarca, string $disp, string $reg, string $handle, string $selo): void
{
    artec_texto_sombra($img, 'NOTÍCIAS NO BUSÃO', $disp, 44, $marg, $yMarca, ARTEC_AMARELO);
    $bb = imagettfbbox(30, 0, $disp, $selo);
    $sw = abs($bb[2] - $bb[0]);
    $px2 = imagesx($img) - $marg;
    $px1 = $px2 - $sw - 56;
    artec_rrect($img, $px1, $yMarca - 46, $px2, $yMarca + 16, 12, ARTEC_VERMELHO);
    arte_texto($img, $selo, $disp, 30, $px1 + 28, $yMarca - 4, ARTEC_BRANCO);
    arte_texto($img, $handle, $reg, 24, $marg, $yMarca + 40, ARTEC_CINZA);
}

/* Rodapé padrão: "FONTE: x • data". */
function artec_rodape($img, int $marg, int $rodapeY, string $reg, string $disp, string $handle, string $fonte, string $dataFmt, string $link = ''): void
{
    imagefilledrectangle($img, $marg, $rodapeY - 54, $marg + 70, $rodapeY - 48, imagecolorallocate($img, ARTEC_AMARELO[0], ARTEC_AMARELO[1], ARTEC_AMARELO[2]));
    $fonteTxt = 'FONTE: ' . mb_strtoupper($fonte, 'UTF-8') . ($dataFmt !== '' ? '  •  ' . $dataFmt : '');
    artec_texto_sombra($img, $fonteTxt, $reg, 28, $marg, $rodapeY, ARTEC_CINZA);
}

/* CTA "pílula" amarela com seta p/ baixo (aponta a barra de resposta do story).
   Avisa que respondendo o story a pessoa recebe o link da matéria. */
function artec_cta($img, int $W, int $marg, int $topY, string $disp, string $texto): void
{
    $texto = mb_strtoupper(trim($texto), 'UTF-8');
    if ($texto === '') {
        return;
    }
    $tam  = 27;
    $padX = 30;
    $padY = 18;
    $chev = 48; // espaço da seta à esquerda
    $bb   = imagettfbbox($tam, 0, $disp, $texto);
    $tw   = abs($bb[2] - $bb[0]);
    $x1   = $marg;
    $x2   = min($W - $marg, $x1 + $chev + $tw + $padX * 2);
    $y1   = $topY;
    $y2   = $topY + $tam + $padY * 2;

    artec_rrect($img, $x1, $y1, $x2, $y2, 16, ARTEC_AMARELO);
    // seta p/ baixo apontando a barra de resposta do Instagram
    $cx  = $x1 + $padX + 6;
    $cy  = (int) (($y1 + $y2) / 2);
    $col = imagecolorallocate($img, ARTEC_PRETO[0], ARTEC_PRETO[1], ARTEC_PRETO[2]);
    imagefilledpolygon($img, [$cx - 13, $cy - 7, $cx + 13, $cy - 7, $cx, $cy + 12], 3, $col);
    // texto preto, alinhado ao lado da seta
    $ty = $cy + (int) round($tam * 0.34);
    arte_texto($img, $texto, $disp, $tam, $x1 + $chev + $padX, $ty, ARTEC_PRETO);
}

/* Desenha manchete auto-ajustada de cima p/ baixo a partir de $yIni até $yLimite. Retorna o y final. */
function artec_manchete($img, string $titulo, string $disp, int $marg, int $yIni, int $yLimite, float $tamMax, array $cor = ARTEC_BRANCO): int
{
    $titulo = trim($titulo) !== '' ? trim($titulo) : 'Notícia de Maceió e Alagoas';
    $largura = imagesx($img) - $marg * 2;
    $tam = $tamMax;
    $linhas = arte_quebrar($titulo, $disp, $tam, $largura);
    for (; $tam >= 40; $tam -= 3) {
        $linhas = arte_quebrar($titulo, $disp, $tam, $largura);
        $lineH = (int) round($tam * 1.22);
        if ($yIni + count($linhas) * $lineH <= $yLimite) {
            break;
        }
    }
    $lineH = (int) round($tam * 1.22);
    $maxLinhas = max(1, (int) floor(($yLimite - $yIni) / $lineH));
    if (count($linhas) > $maxLinhas) {
        $linhas = array_slice($linhas, 0, $maxLinhas);
        $linhas[$maxLinhas - 1] = rtrim($linhas[$maxLinhas - 1]) . '…';
    }
    $y = $yIni + (int) round($tam);
    foreach ($linhas as $ln) {
        artec_texto_sombra($img, $ln, $disp, $tam, $marg, $y, $cor);
        $y += $lineH;
    }
    return $y;
}

/* ============================ TEMPLATES ============================ */

/* T1 — Telejornal: CENÁRIO de ônibus ao fundo + "TV" com moldura amarela + lower-third + manchete. */
function artec_tpl1($img, int $W, int $H, int $safeTop, int $safeBot, int $marg, array $M, $foto, $bg, string $disp, string $reg, string $handle, string $cidade, string $selo): void
{
    if ($bg !== null) {
        // cenário real do ônibus, escurecido (reconhecível, mas o foco é a "TV")
        arte_cover($img, $bg, 0, 0, $W, $H);
        if (function_exists('imagefilter')) {
            for ($i = 0; $i < 2; $i++) { @imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR); }
            @imagefilter($img, IMG_FILTER_BRIGHTNESS, -55);
        }
    } elseif ($foto !== null) {
        arte_cover($img, $foto, 0, 0, $W, $H);
        if (function_exists('imagefilter')) {
            for ($i = 0; $i < 6; $i++) { @imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR); }
            @imagefilter($img, IMG_FILTER_BRIGHTNESS, -70);
        }
    }
    imagefilledrectangle($img, 0, 0, $W, $H, imagecolorallocatealpha($img, 0, 0, 0, 58));
    artec_gradiente_v($img, 0, (int) ($H * 0.28), 35, 110);
    artec_gradiente_v($img, (int) ($H * 0.45), $H, 110, 18);

    $yMarca = $safeTop + 60;
    artec_marca($img, $marg, $yMarca, $disp, $reg, $handle, $selo);

    $tvY1 = $yMarca + 80;
    $tvH  = $H >= 1900 ? 640 : 540;
    $tvY2 = $tvY1 + $tvH;
    $b = 10;
    artec_rrect($img, $marg + 6, $tvY1 + 10, $W - $marg + 6, $tvY2 + 12, 28, [0, 0, 0]);
    artec_rrect($img, $marg, $tvY1, $W - $marg, $tvY2, 28, ARTEC_AMARELO);
    $iw = ($W - $marg * 2) - $b * 2;
    $ih = $tvH - $b * 2;
    if ($foto !== null) {
        arte_cover($img, $foto, $marg + $b, $tvY1 + $b, $iw, $ih);
    } else {
        // sem foto: preenche o "vidro" da TV escuro com a marca (em vez de amarelo vazio)
        imagefilledrectangle($img, $marg + $b, $tvY1 + $b, $marg + $b + $iw, $tvY1 + $b + $ih, imagecolorallocate($img, 20, 22, 28));
        artec_texto_centro($img, 'NOTÍCIAS NO BUSÃO', $disp, 48, $tvY1 + (int) ($tvH / 2), ARTEC_AMARELO);
    }
    $ltH = 64;
    imagefilledrectangle($img, $marg + $b, $tvY1 + $b + $ih - $ltH, $marg + $b + $iw, $tvY1 + $b + $ih, imagecolorallocatealpha($img, ARTEC_VERMELHO[0], ARTEC_VERMELHO[1], ARTEC_VERMELHO[2], 18));
    arte_texto($img, $cidade, $disp, 30, $marg + $b + 22, $tvY1 + $b + $ih - 18, ARTEC_BRANCO);
    artec_manchete($img, (string) $M['titulo'], $disp, $marg, $tvY2 + 64, $H - $safeBot - 130, $H >= 1900 ? 76 : 60);
    artec_rodape($img, $marg, $H - $safeBot - 40, $reg, $disp, $handle, (string) $M['fonte'], (string) $M['data'], (string) ($M['link'] ?? ''));
}

/* T2 — Capa cheia: foto imersiva ocupando tudo + gradiente forte embaixo + manchete na base. */
function artec_tpl2($img, int $W, int $H, int $safeTop, int $safeBot, int $marg, array $M, $foto, $bg, string $disp, string $reg, string $handle, string $cidade, string $selo): void
{
    if ($foto !== null) {
        arte_cover($img, $foto, 0, 0, $W, $H);
    }
    artec_gradiente_v($img, 0, (int) ($H * 0.30), 20, 110);
    artec_gradiente_v($img, (int) ($H * 0.40), $H, 120, 2);
    // reforço sólido no rodapé p/ leitura
    imagefilledrectangle($img, 0, (int) ($H * 0.62), $W, $H, imagecolorallocatealpha($img, 0, 0, 0, 70));

    $yMarca = $safeTop + 60;
    artec_marca($img, $marg, $yMarca, $disp, $reg, $handle, $selo);
    // pílula cidade
    artec_rrect($img, $marg, $yMarca + 64, $marg + 360, $yMarca + 116, 10, ARTEC_VERMELHO);
    arte_texto($img, $cidade, $disp, 28, $marg + 20, $yMarca + 102, ARTEC_BRANCO);

    // manchete grande ancorada acima do rodapé
    $rodapeY = $H - $safeBot - 40;
    $tituloTop = (int) ($H * 0.50);
    artec_manchete($img, (string) $M['titulo'], $disp, $marg, $tituloTop, $rodapeY - 80, $H >= 1900 ? 90 : 72);
    artec_rodape($img, $marg, $rodapeY, $reg, $disp, $handle, (string) $M['fonte'], (string) $M['data'], (string) ($M['link'] ?? ''));
}

/* T3 — Split amarelo: foto em cima (sem blur), painel preto embaixo c/ barra amarela + manchete. */
function artec_tpl3($img, int $W, int $H, int $safeTop, int $safeBot, int $marg, array $M, $foto, $bg, string $disp, string $reg, string $handle, string $cidade, string $selo): void
{
    imagefilledrectangle($img, 0, 0, $W, $H, imagecolorallocate($img, ARTEC_PRETO[0], ARTEC_PRETO[1], ARTEC_PRETO[2]));
    $fotoTop = $safeTop + 150;
    $fotoH = (int) ($H * 0.42);
    if ($foto !== null) {
        arte_cover($img, $foto, 0, $fotoTop, $W, $fotoH);
    }
    // marca sobre a foto (topo)
    $yMarca = $safeTop + 60;
    artec_gradiente_v($img, $safeTop, $safeTop + 160, 30, 120);
    artec_marca($img, $marg, $yMarca, $disp, $reg, $handle, $selo);
    // barra amarela divisória com cidade
    $barY = $fotoTop + $fotoH;
    imagefilledrectangle($img, 0, $barY, $W, $barY + 58, imagecolorallocate($img, ARTEC_AMARELO[0], ARTEC_AMARELO[1], ARTEC_AMARELO[2]));
    arte_texto($img, $cidade, $disp, 30, $marg, $barY + 42, ARTEC_PRETO);
    // manchete no painel preto
    artec_manchete($img, (string) $M['titulo'], $disp, $marg, $barY + 110, $H - $safeBot - 130, $H >= 1900 ? 82 : 66);
    artec_rodape($img, $marg, $H - $safeBot - 40, $reg, $disp, $handle, (string) $M['fonte'], (string) $M['data'], (string) ($M['link'] ?? ''));
}

/* T4 — Plantão urgente: faixa vermelha no topo + foto emoldurada + manchete; clima de plantão. */
function artec_tpl4($img, int $W, int $H, int $safeTop, int $safeBot, int $marg, array $M, $foto, $bg, string $disp, string $reg, string $handle, string $cidade, string $selo): void
{
    $fundoSrc = $bg !== null ? $bg : $foto;
    if ($fundoSrc !== null) {
        arte_cover($img, $fundoSrc, 0, 0, $W, $H);
        if (function_exists('imagefilter')) {
            for ($i = 0; $i < ($bg !== null ? 3 : 8); $i++) { @imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR); }
            @imagefilter($img, IMG_FILTER_BRIGHTNESS, -70);
        }
    } else {
        imagefilledrectangle($img, 0, 0, $W, $H, imagecolorallocate($img, ARTEC_PRETO[0], ARTEC_PRETO[1], ARTEC_PRETO[2]));
    }
    imagefilledrectangle($img, 0, 0, $W, $H, imagecolorallocatealpha($img, 0, 0, 0, 55));

    // faixa vermelha "PLANTÃO"
    $faixaY = $safeTop + 40;
    imagefilledrectangle($img, 0, $faixaY, $W, $faixaY + 92, imagecolorallocate($img, ARTEC_VERMELHO[0], ARTEC_VERMELHO[1], ARTEC_VERMELHO[2]));
    arte_texto($img, 'PLANTÃO • NOTÍCIAS NO BUSÃO', $disp, 34, $marg, $faixaY + 64, ARTEC_BRANCO);

    // foto emoldurada (branca) no meio-alto
    $tvY1 = $faixaY + 140;
    $tvH  = $H >= 1900 ? 600 : 500;
    $b = 8;
    artec_rrect($img, $marg, $tvY1, $W - $marg, $tvY1 + $tvH, 18, ARTEC_BRANCO);
    if ($foto !== null) {
        arte_cover($img, $foto, $marg + $b, $tvY1 + $b, ($W - $marg * 2) - $b * 2, $tvH - $b * 2);
    }
    // selo cidade sobre a foto
    artec_rrect($img, $marg + $b + 14, $tvY1 + $b + 14, $marg + $b + 14 + 330, $tvY1 + $b + 14 + 52, 8, ARTEC_VERMELHO);
    arte_texto($img, $cidade, $disp, 26, $marg + $b + 30, $tvY1 + $b + 14 + 38, ARTEC_BRANCO);

    artec_manchete($img, (string) $M['titulo'], $disp, $marg, $tvY1 + $tvH + 60, $H - $safeBot - 130, $H >= 1900 ? 80 : 64);
    artec_rodape($img, $marg, $H - $safeBot - 40, $reg, $disp, $handle, (string) $M['fonte'], (string) $M['data'], (string) ($M['link'] ?? ''));
}

/**
 * Compositor: escolhe o template e salva o JPG.
 * @param array $opts ['template'=>1..4,'formato'=>'story'|'feed','handle'=>,'cidade'=>,'selo'=>,'destino'=>caminho absoluto opcional]
 * @return array ['ok'=>bool,'arquivo'=>relativo,'erro'=>string]
 */
function arte_codigo_compor(array $materia, int $clienteId, array $opts = []): array
{
    if (!function_exists('imagecreatetruecolor')) {
        return ['ok' => false, 'erro' => 'Extensão GD ausente.'];
    }
    $tpl     = (int) ($opts['template'] ?? 1);
    if (!isset(artec_templates()[$tpl])) { $tpl = 1; }
    $formato = ($opts['formato'] ?? 'story') === 'feed' ? 'feed' : 'story';
    $handle  = (string) ($opts['handle'] ?? ARTE_HANDLE);
    $cidade  = mb_strtoupper((string) ($opts['cidade'] ?? 'MACEIÓ • ALAGOAS'), 'UTF-8');
    // selo = tema da matéria (esporte, culinária, política…); cai p/ "AGORA" se não detectar
    $selo    = (isset($opts['selo']) && $opts['selo'] !== '')
        ? mb_strtoupper((string) $opts['selo'], 'UTF-8')
        : artec_categoria((string) ($materia['titulo'] ?? ''), (string) ($materia['resumo'] ?? ''), (string) ($materia['link'] ?? ''));

    $W = 1080;
    $H = $formato === 'feed' ? 1350 : 1920;
    $safeTop = $formato === 'feed' ? 50 : 230;
    $safeBot = $formato === 'feed' ? 60 : 240;
    $marg = 60;
    $disp = artec_fonte_display();
    $reg  = ARTE_FONTE_REG;

    // normaliza dados + data formatada
    $dataFmt = '';
    if (!empty($materia['data'])) {
        $ts = strtotime((string) $materia['data']);
        if ($ts !== false) { $dataFmt = date('d/m/Y', $ts); }
    }
    $M = [
        'titulo' => (string) ($materia['titulo'] ?? ''),
        'fonte'  => trim((string) ($materia['fonte'] ?? '')) !== '' ? (string) $materia['fonte'] : 'redes',
        'data'   => $dataFmt,
        'link'   => (string) ($materia['link'] ?? ''),
    ];
    $urls = !empty($materia['imagens']) && is_array($materia['imagens'])
        ? $materia['imagens']
        : array_filter([(string) ($materia['imagem'] ?? '')]);
    $foto = materia_baixar_imagem($urls);

    // cenário de ônibus ao fundo (ligado por padrão; 'off' desliga)
    $cenario = (string) ($opts['cenario'] ?? 'onibus');
    $bg = $cenario === 'off' ? null : artec_fundo_onibus((string) ($opts['cenario_arquivo'] ?? ''));

    $img = imagecreatetruecolor($W, $H);
    imagealphablending($img, true);
    imagefilledrectangle($img, 0, 0, $W, $H, imagecolorallocate($img, ARTEC_PRETO[0], ARTEC_PRETO[1], ARTEC_PRETO[2]));

    $fn = 'artec_tpl' . $tpl;
    $fn($img, $W, $H, $safeTop, $safeBot, $marg, $M, $foto, $bg, $disp, $reg, $handle, $cidade, $selo);

    // CTA abaixo da fonte: "comente e receba o link" (só story; rodapé fica em H-safeBot-40)
    if ($formato === 'story' && trim((string) ($opts['cta_link'] ?? '')) !== '') {
        artec_cta($img, $W, $marg, $H - $safeBot - 40 + 26, $disp, (string) $opts['cta_link']);
    }

    if ($foto !== null) { imagedestroy($foto); }
    if ($bg !== null) { imagedestroy($bg); }

    // destino: explícito (preview) ou padrão (uploads do cliente)
    if (!empty($opts['destino'])) {
        $destino = (string) $opts['destino'];
        @mkdir(dirname($destino), 0775, true);
        $rel = $destino;
    } else {
        $dir = __DIR__ . '/uploads/publicacoes/' . $clienteId;
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $nome = 'cod-' . $formato . '-t' . $tpl . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.jpg';
        $destino = $dir . '/' . $nome;
        $rel = 'uploads/publicacoes/' . $clienteId . '/' . $nome;
    }
    $ok = imagejpeg($img, $destino, 92);
    imagedestroy($img);
    if (!$ok) {
        return ['ok' => false, 'erro' => 'Falha ao salvar a arte.'];
    }
    return ['ok' => true, 'arquivo' => $rel];
}
