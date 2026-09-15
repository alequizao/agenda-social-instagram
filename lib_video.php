<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * lib_video.php
 * Transforma uma arte (imagem) + trilha de áudio num MP4 curto, via ffmpeg.
 * Usado quando o áudio está ligado: story/feed com som = vídeo (reels/story de vídeo).
 *
 * Requer ffmpeg no servidor (/usr/bin/ffmpeg). Sem ffmpeg, retorna ok=false e o
 * chamador cai para a imagem estática (sem áudio).
 */

/* shell_exec disponível? (no PHP-FPM do servidor ele está em disable_functions;
   no CLI/cron está liberado). Sem shell não dá p/ rodar o ffmpeg. */
function video_shell_ok(): bool
{
    static $s = null;
    if ($s === null) {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $s = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
    }
    return $s;
}

function video_ffmpeg_ok(): bool
{
    static $ok = null;
    if ($ok === null) {
        // precisa de shell (cron/CLI). No site (FPM) o áudio é finalizado depois pelo cron.
        $ok = video_shell_ok() && (is_executable('/usr/bin/ffmpeg') || trim((string) @shell_exec('command -v ffmpeg 2>/dev/null')) !== '');
    }
    return $ok;
}

/**
 * Gera um MP4 a partir de uma imagem fixa + áudio.
 * @param string $imgAbs   caminho ABSOLUTO da imagem (jpg)
 * @param string $audioAbs caminho ABSOLUTO do áudio (mp3/m4a)
 * @param string $destAbs  caminho ABSOLUTO do mp4 de saída
 * @param int    $segundos duração (5..15)
 * @return array ['ok'=>bool,'erro'=>string]
 */
function video_de_imagem(string $imgAbs, string $audioAbs, string $destAbs, int $segundos = 7): array
{
    if (!video_ffmpeg_ok()) {
        return ['ok' => false, 'erro' => 'ffmpeg indisponível.'];
    }
    if (!is_file($imgAbs)) {
        return ['ok' => false, 'erro' => 'Imagem não encontrada.'];
    }
    if (!is_file($audioAbs)) {
        return ['ok' => false, 'erro' => 'Áudio não encontrado.'];
    }
    $segundos = max(5, min(15, $segundos));
    @mkdir(dirname($destAbs), 0775, true);

    // -loop 1: imagem fixa | -t: duração | yuv420p p/ compatibilidade IG
    // scale garante dimensões pares (exigência do H.264)
    $cmd = sprintf(
        '/usr/bin/ffmpeg -y -loop 1 -i %s -i %s -t %d ' .
        '-vf "scale=trunc(iw/2)*2:trunc(ih/2)*2,format=yuv420p" ' .
        '-c:v libx264 -preset veryfast -profile:v high -level 4.0 -r 30 ' .
        '-c:a aac -b:a 128k -ac 2 -ar 44100 -shortest -movflags +faststart %s 2>&1',
        escapeshellarg($imgAbs),
        escapeshellarg($audioAbs),
        $segundos,
        escapeshellarg($destAbs)
    );
    $saida = (string) @shell_exec($cmd);
    if (!is_file($destAbs) || filesize($destAbs) < 1000) {
        return ['ok' => false, 'erro' => 'Falha no ffmpeg: ' . mb_substr($saida, -300)];
    }
    return ['ok' => true, 'erro' => ''];
}
