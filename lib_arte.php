<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * lib_arte.php
 * A IA gera a cena (POV sobre o ombro num onibus de Maceio) com a TELA DO CELULAR VERDE (chroma key).
 * Aqui detectamos a area verde e encaixamos a MATERIA REAL nela (foto da noticia + manchete correta),
 * como se a pessoa estivesse lendo a materia de verdade. Depois marca d'agua @tevinobuzao e salva.
 */

const ARTE_FONTE_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
const ARTE_FONTE_REG  = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
const ARTE_HANDLE     = '@tevinobuzao';

/* Quebra texto em linhas que cabem em $maxLargura (px). */
function arte_quebrar(string $texto, string $fonte, float $tam, int $maxLargura): array
{
    $palavras = preg_split('/\s+/u', trim($texto)) ?: [];
    $linhas = [];
    $atual = '';
    foreach ($palavras as $p) {
        $teste = $atual === '' ? $p : $atual . ' ' . $p;
        $bbox = imagettfbbox($tam, 0, $fonte, $teste);
        if (abs($bbox[2] - $bbox[0]) <= $maxLargura || $atual === '') {
            $atual = $teste;
        } else {
            $linhas[] = $atual;
            $atual = $p;
        }
    }
    if ($atual !== '') {
        $linhas[] = $atual;
    }
    return $linhas;
}

/* Desenha texto. (sem type hint GD: PHP 7.4 = resource, PHP 8+ = GdImage) */
function arte_texto($img, string $texto, string $fonte, float $tam, int $x, int $y, array $cor): void
{
    $c = imagecolorallocate($img, $cor[0], $cor[1], $cor[2]);
    imagettftext($img, $tam, 0, $x, $y, $c, $fonte, $texto);
}

/* Desenha $src preenchendo o retangulo destino (cover, corte central). */
function arte_cover($dst, $src, int $dx, int $dy, int $dw, int $dh): void
{
    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw <= 0 || $sh <= 0 || $dw <= 0 || $dh <= 0) {
        return;
    }
    $escala = max($dw / $sw, $dh / $sh);
    $cw = (int) round($dw / $escala);
    $ch = (int) round($dh / $escala);
    $sx = (int) round(($sw - $cw) / 2);
    $sy = (int) round(($sh - $ch) / 2);
    imagecopyresampled($dst, $src, $dx, $dy, $sx, $sy, $dw, $dh, $cw, $ch);
}

/* Um pixel (int truecolor) e "verde de tela"? */
function arte_eh_verde(int $c): bool
{
    $r = ($c >> 16) & 0xFF;
    $g = ($c >> 8) & 0xFF;
    $b = $c & 0xFF;
    return $g > 110 && $g > $r + 45 && $g > $b + 45;
}

/* Monta o "print" da materia (foto da noticia + manchete) no tamanho da tela. */
function arte_card_noticia(int $cardW, int $cardH, string $titulo, string $fonte, $news)
{
    $card = imagecreatetruecolor($cardW, $cardH);
    imagefill($card, 0, 0, imagecolorallocate($card, 255, 255, 255));

    $pad = max(8, (int) round($cardW * 0.06));
    // foto da noticia no topo (cover); se nao tiver, usa uma faixa azul
    $fotoH = (int) round($cardH * 0.48);
    if ($news !== null) {
        arte_cover($card, $news, 0, 0, $cardW, $fotoH);
    } else {
        imagefilledrectangle($card, 0, 0, $cardW, $fotoH, imagecolorallocate($card, 37, 99, 235));
    }

    // manchete (texto real, preto) abaixo da foto
    $tam = max(11.0, $cardW * 0.070);
    $linhas = arte_quebrar($titulo, ARTE_FONTE_BOLD, $tam, $cardW - $pad * 2);
    $lineH = (int) round($tam * 1.3);
    $y = $fotoH + $pad + (int) round($tam);
    $maxY = $cardH - (int) round($tam * 2.2);
    foreach ($linhas as $ln) {
        if ($y > $maxY) {
            break;
        }
        arte_texto($card, $ln, ARTE_FONTE_BOLD, $tam, $pad, $y, [17, 24, 39]);
        $y += $lineH;
    }

    // rodape: fonte
    $tamF = max(9.0, $cardW * 0.045);
    arte_texto($card, 'Fonte: ' . ($fonte !== '' ? $fonte : 'redes'), ARTE_FONTE_REG, $tamF, $pad, $cardH - $pad, [107, 114, 128]);

    return $card;
}

/**
 * Compoe a arte final: encaixa a materia na tela verde do celular + marca d'agua.
 * @return array ['ok'=>bool, 'arquivo'=>caminho relativo, 'erro'=>string]
 */
function arte_compor(string $pngBinario, string $titulo, string $fonte, int $clienteId, string $imagemUrl = ''): array
{
    if (!function_exists('imagecreatefromstring')) {
        return ['ok' => false, 'erro' => 'Extensao GD ausente.'];
    }
    $img = @imagecreatefromstring($pngBinario);
    if ($img === false) {
        return ['ok' => false, 'erro' => 'Imagem da IA invalida.'];
    }
    $W = imagesx($img);
    $H = imagesy($img);
    imagealphablending($img, true);

    // ---- 1) acha a area verde (tela do celular) ----
    $minX = $W; $minY = $H; $maxX = -1; $maxY = -1; $verdes = 0;
    for ($y = 0; $y < $H; $y += 2) {
        for ($x = 0; $x < $W; $x += 2) {
            if (arte_eh_verde(imagecolorat($img, $x, $y))) {
                if ($x < $minX) $minX = $x;
                if ($x > $maxX) $maxX = $x;
                if ($y < $minY) $minY = $y;
                if ($y > $maxY) $maxY = $y;
                $verdes++;
            }
        }
    }

    // ---- 2) se achou uma tela razoavel, encaixa a materia real ----
    $areaMin = (int) (($W * $H) * 0.004); // ~0,4% da imagem
    if ($maxX > $minX && $maxY > $minY && $verdes >= $areaMin) {
        $bw = $maxX - $minX + 1;
        $bh = $maxY - $minY + 1;

        // foto da noticia
        $news = null;
        if ($imagemUrl !== '' && function_exists('noticias_baixar')) {
            $bin = noticias_baixar($imagemUrl);
            if (is_string($bin) && $bin !== '') {
                $tmp = @imagecreatefromstring($bin);
                if ($tmp !== false) {
                    $news = $tmp;
                }
            }
        }

        $card = arte_card_noticia($bw, $bh, $titulo, $fonte, $news);
        if ($news !== null) {
            imagedestroy($news);
        }

        // pinta o card SO onde a tela e verde (respeita cantos/angulo do celular)
        for ($y = $minY; $y <= $maxY; $y++) {
            for ($x = $minX; $x <= $maxX; $x++) {
                if (arte_eh_verde(imagecolorat($img, $x, $y))) {
                    $c = imagecolorat($card, $x - $minX, $y - $minY);
                    imagesetpixel($img, $x, $y, $c);
                }
            }
        }
        imagedestroy($card);
    }

    // ---- 3) marca d'agua discreta "@tevinobuzao" ----
    $tam = max(13.0, $W * 0.030);
    $m = (int) round($W * 0.045);
    $s = max(1, (int) round($tam * 0.06));
    arte_texto($img, ARTE_HANDLE, ARTE_FONTE_BOLD, $tam, $m + $s, $H - $m + $s, [0, 0, 0]);
    arte_texto($img, ARTE_HANDLE, ARTE_FONTE_BOLD, $tam, $m, $H - $m, [255, 255, 255]);

    // ---- 4) salva ----
    $dir = __DIR__ . '/uploads/publicacoes/' . $clienteId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $nome = 'ia-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.jpg';
    $destino = $dir . '/' . $nome;
    $ok = imagejpeg($img, $destino, 90);
    imagedestroy($img);

    if (!$ok) {
        return ['ok' => false, 'erro' => 'Falha ao salvar a arte.'];
    }
    return ['ok' => true, 'arquivo' => 'uploads/publicacoes/' . $clienteId . '/' . $nome];
}
