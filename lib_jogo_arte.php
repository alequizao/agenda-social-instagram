<?php

declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * lib_jogo_arte.php — arte da FORCA por código (GD). Visual próprio do jogo, independente do perfil.
 * Sem IA, custo zero. Usada no início e no fim da partida (dm_jogo_arte=1).
 *
 * Paleta e fontes reaproveitadas de lib_arte_codigo.php (amarelo, vermelho, preto).
 */

require_once __DIR__ . '/lib_arte_codigo.php';

/* Linha grossa de verdade (imagesetthickness some com antialias): polígono. */
function jogo_arte_linha($img, int $x1, int $y1, int $x2, int $y2, int $esp, int $cor): void
{
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $len = max(0.001, sqrt($dx * $dx + $dy * $dy));
    $px = (int) round(-($dy / $len) * ($esp / 2));
    $py = (int) round(($dx / $len) * ($esp / 2));
    imagefilledpolygon($img, [
        $x1 + $px, $y1 + $py, $x2 + $px, $y2 + $py,
        $x2 - $px, $y2 - $py, $x1 - $px, $y1 - $py,
    ], 4, $cor);
}

/* Apaga artes de jogo com mais de 1 dia (a Meta já baixou a imagem). */
function jogo_arte_limpar(string $dir): void
{
    foreach (glob($dir . '/*.jpg') ?: [] as $f) {
        if (@filemtime($f) < time() - 86400) {
            @unlink($f);
        }
    }
}

/**
 * Gera a imagem da forca. Retorna a URL pública (BASE_URL/...) ou '' se falhar.
 * $p precisa de: chave, letras, erros, max_erros. $rotulo é a faixa de cima.
 * $revelar = mostra a palavra inteira (fim de partida).
 */
function jogo_arte_gerar(array $p, string $rotulo = 'JOGO DA FORCA', bool $revelar = false, string $handle = ''): string
{
    if (!function_exists('imagecreatetruecolor')) {
        return '';
    }
    $W = 1080;
    $H = 1080;
    $img = @imagecreatetruecolor($W, $H);
    if (!$img) {
        return '';
    }
    imageantialias($img, true);
    $c = static function (array $rgb) use ($img) {
        return imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    };
    $preto    = $c(ARTEC_PRETO);
    $amarelo  = $c(ARTEC_AMARELO);
    $vermelho = $c(ARTEC_VERMELHO);
    $branco   = $c(ARTEC_BRANCO);

    imagefilledrectangle($img, 0, 0, $W, $H, $preto);
    // faixa superior
    imagefilledrectangle($img, 0, 0, $W, 118, $amarelo);
    $fonteD = artec_fonte_display();
    arte_texto($img, mb_strtoupper($rotulo, 'UTF-8'), $fonteD, 46, 48, 82, ARTEC_PRETO);

    /* ---- forca (lado esquerdo) ---- */
    $erros = (int) $p['erros'];
    $max   = max(1, (int) $p['max_erros']);
    $etapa = (int) round(($erros / $max) * 6);
    $bx = 190;   // mastro x
    $by = 700;   // chão y
    imagefilledrectangle($img, $bx - 110, $by - 14, $bx + 120, $by, $amarelo);   // chão
    imagefilledrectangle($img, $bx - 9, 230, $bx + 9, $by, $amarelo);            // mastro
    imagefilledrectangle($img, $bx - 9, 230, $bx + 240, 248, $amarelo);          // viga
    imagefilledrectangle($img, $bx + 222, 248, $bx + 240, 310, $amarelo);        // corda
    $cor = $etapa >= 6 ? $vermelho : $branco;
    $cx  = $bx + 231;
    if ($etapa >= 1) { // cabeça (anel: círculo cheio + miolo preto)
        imagefilledellipse($img, $cx, 358, 104, 104, $cor);
        imagefilledellipse($img, $cx, 358, 76, 76, $preto);
    }
    if ($etapa >= 2) { // tronco
        imagefilledrectangle($img, $cx - 9, 410, $cx + 9, 570, $cor);
    }
    if ($etapa >= 3) {
        jogo_arte_linha($img, $cx, 440, $cx - 95, 512, 18, $cor); // braço esq
    }
    if ($etapa >= 4) {
        jogo_arte_linha($img, $cx, 440, $cx + 95, 512, 18, $cor); // braço dir
    }
    if ($etapa >= 5) {
        jogo_arte_linha($img, $cx, 570, $cx - 85, 672, 18, $cor); // perna esq
    }
    if ($etapa >= 6) {
        jogo_arte_linha($img, $cx, 570, $cx + 85, 672, 18, $cor); // perna dir
    }

    /* ---- vidas + letras usadas (lado direito) ---- */
    $vidas = max(0, $max - $erros);
    arte_texto($img, 'VIDAS', $fonteD, 28, 660, 292, ARTEC_CINZA);
    $cora = $vidas > 1 ? ARTEC_BRANCO : ARTEC_VERMELHO;
    arte_texto($img, $vidas . '/' . $max, $fonteD, 86, 660, 400, $cora);
    $usadas = trim(implode('  ', preg_split('//u', (string) $p['letras'], -1, PREG_SPLIT_NO_EMPTY) ?: []));
    if ($usadas !== '') {
        arte_texto($img, 'LETRAS USADAS', $fonteD, 26, 660, 476, ARTEC_CINZA);
        $y = 528;
        foreach (array_slice(arte_quebrar($usadas, $fonteD, 34, 360), 0, 4) as $ln) {
            arte_texto($img, $ln, $fonteD, 34, 660, $y, ARTEC_BRANCO);
            $y += 48;
        }
    }

    /* ---- painel da palavra (rodapé) ---- */
    imagefilledrectangle($img, 0, 760, $W, $H, $c([22, 22, 26]));
    imagefilledrectangle($img, 0, 760, $W, 768, $vermelho);
    $chave  = (string) $p['chave'];
    $letras = $revelar ? $chave : (string) $p['letras'];
    $exib   = jogo_mascara($chave, $letras);
    $tam    = mb_strlen($exib, 'UTF-8') > 26 ? 52 : 74;
    $linhas = arte_quebrar($exib, $fonteD, $tam, $W - 120);
    $y = count($linhas) > 1 ? 850 : 890;
    foreach (array_slice($linhas, 0, 2) as $ln) {
        // centraliza
        $bb = imagettfbbox($tam, 0, $fonteD, $ln);
        $lw = abs($bb[2] - $bb[0]);
        arte_texto($img, $ln, $fonteD, $tam, (int) (($W - $lw) / 2), $y, $revelar ? ARTEC_AMARELO : ARTEC_BRANCO);
        $y += $tam + 18;
    }
    if ($handle !== '') {
        arte_texto($img, $handle, $fonteD, 24, 48, 1042, ARTEC_CINZA);
    }

    $dir = __DIR__ . '/uploads/jogo';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    jogo_arte_limpar($dir);
    $nome = 'forca_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $ok = @imagejpeg($img, $dir . '/' . $nome, 88);
    imagedestroy($img);
    if (!$ok) {
        return '';
    }
    @chmod($dir . '/' . $nome, 0644);
    return rtrim(BASE_URL, '/') . '/uploads/jogo/' . $nome;
}

/**
 * Arte de RESULTADO, feita pra pessoa postar no story.
 * Mostra a palavra, os corações gastos e o convite pra jogar.
 * $handle = @ do perfil (o CTA "manda JOGO no direct").
 */
function jogo_arte_resultado(array $p, bool $ganhou, string $handle = '', int $streak = 0): string
{
    if (!function_exists('imagecreatetruecolor')) {
        return '';
    }
    $W = 1080;
    $H = 1350; // formato de story/feed
    $img = @imagecreatetruecolor($W, $H);
    if (!$img) {
        return '';
    }
    imageantialias($img, true);
    $co = static function (array $rgb) use ($img) {
        return imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    };
    $preto   = $co(ARTEC_PRETO);
    $amarelo = $co(ARTEC_AMARELO);
    $verde   = $co([22, 163, 74]);
    $vermelho = $co(ARTEC_VERMELHO);
    $fonteD  = artec_fonte_display();

    imagefilledrectangle($img, 0, 0, $W, $H, $preto);
    imagefilledrectangle($img, 0, 0, $W, 26, $ganhou ? $verde : $vermelho);

    arte_texto($img, $ganhou ? 'ACERTEI!' : 'QUASE!', $fonteD, 120, 70, 220, $ganhou ? [255, 255, 255] : ARTEC_AMARELO);
    arte_texto($img, 'JOGO DA FORCA', $fonteD, 40, 74, 285, ARTEC_CINZA);

    // a palavra
    $palavra = mb_strtoupper((string) $p['palavra'], 'UTF-8');
    $tam = mb_strlen($palavra, 'UTF-8') > 9 ? 76 : 108;
    $bb  = imagettfbbox($tam, 0, $fonteD, $palavra);
    arte_texto($img, $palavra, $fonteD, $tam, (int) (($W - abs($bb[2] - $bb[0])) / 2), 520, ARTEC_AMARELO);

    // vidas gastas: blocos (GD não desenha emoji)
    $max   = max(1, (int) $p['max_erros']);
    $erros = min($max, (int) $p['erros']);
    $lado  = 74;
    $gap   = 18;
    $larg  = $max * $lado + ($max - 1) * $gap;
    $x     = (int) (($W - $larg) / 2);
    for ($i = 0; $i < $max; $i++) {
        $cor = $i < ($max - $erros) ? $verde : $co([60, 60, 66]);
        imagefilledrectangle($img, $x, 620, $x + $lado, 620 + $lado, $cor);
        $x += $lado + $gap;
    }
    $sobrou = $max - $erros;
    arte_texto($img, $sobrou . ' de ' . $max . ' vidas', $fonteD, 40, 74, 790, ARTEC_BRANCO);
    if ($streak > 1) {
        arte_texto($img, $streak . ' vitorias seguidas', $fonteD, 40, 74, 850, ARTEC_AMARELO);
    }

    // CTA
    imagefilledrectangle($img, 0, 1010, $W, $H, $amarelo);
    arte_texto($img, 'JOGA VOCE TAMBEM', $fonteD, 58, 74, 1105, ARTEC_PRETO);
    arte_texto($img, 'manda JOGO no direct', $fonteD, 44, 74, 1175, ARTEC_PRETO);
    if ($handle !== '') {
        arte_texto($img, $handle, $fonteD, 52, 74, 1265, [120, 60, 0]);
    }

    $dir = __DIR__ . '/uploads/jogo';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $nome = 'resultado_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $ok = @imagejpeg($img, $dir . '/' . $nome, 88);
    imagedestroy($img);
    if (!$ok) {
        return '';
    }
    @chmod($dir . '/' . $nome, 0644);
    return rtrim(BASE_URL, '/') . '/uploads/jogo/' . $nome;
}
