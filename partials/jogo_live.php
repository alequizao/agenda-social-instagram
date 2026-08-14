  <div class="kpi-grid">
    <div class="kpi"><span class="kpi__label">Partidas hoje</span><b class="kpi__valor"><?= (int) ($k['hoje'] ?? 0) ?></b></div>
    <div class="kpi"><span class="kpi__label">Em andamento</span><b class="kpi__valor"><?= (int) ($k['ativas'] ?? 0) ?></b></div>
    <div class="kpi"><span class="kpi__label">Vitórias</span><b class="kpi__valor"><?= (int) ($k['ganhou'] ?? 0) ?></b></div>
    <div class="kpi"><span class="kpi__label">Derrotas</span><b class="kpi__valor"><?= (int) ($k['perdeu'] ?? 0) ?></b></div>
    <div class="kpi"><span class="kpi__label">Jogadores</span><b class="kpi__valor"><?= (int) ($k['jogadores'] ?? 0) ?></b></div>
  </div>

  <div class="section-head"><h2>🕹️ Arcade — partidas por jogo</h2></div>
  <div class="news-list">
    <div class="news-item"><div class="news-item__body">
      <p class="news-item__resumo">
        <b>🎯 Forca</b> — <?= (int) ($forcaTot['total'] ?? 0) ?> partida(s), <?= (int) ($forcaTot['ganhou'] ?? 0) ?> vitória(s), <?= (int) ($forcaTot['hoje'] ?? 0) ?> hoje
        <?php foreach ($arcade as $a): ?>
          <br><b><?= e((string) ($catJogos[(string) $a['jogo']]['rotulo'] ?? $a['jogo'])) ?></b>
          — <?= (int) $a['n'] ?> partida(s), <?= (int) $a['g'] ?> vitória(s), <?= (int) $a['hoje'] ?> hoje
        <?php endforeach; ?>
      </p>
      <?php if ($sessoesAtivas): ?>
        <p class="news-item__resumo">🎮 Jogando agora:
          <?php foreach ($sessoesAtivas as $i => $sa): ?>
            @<?= e((string) $sa['quem']) ?> (<?= e((string) ($catJogos[(string) $sa['jogo']]['rotulo'] ?? $sa['jogo'])) ?>)<?= $i < count($sessoesAtivas) - 1 ? ' · ' : '' ?>
          <?php endforeach; ?>
        </p>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="section-head"><h2>🗓️ Palavra do dia</h2></div>
  <?php if (!$doDia): ?>
    <div class="aviso aviso--ok">Ainda não foi sorteada hoje (o <code>cron_jogo.php</code> sorteia sozinho).</div>
  <?php else: ?>
    <div class="news-list">
      <div class="news-item"><div class="news-item__body">
        <div class="news-item__meta">
          <span class="tag tag--ok"><?= e(mb_strtoupper((string) $doDia['palavra'], 'UTF-8')) ?></span>
          <span>💡 <?= e((string) $doDia['dica']) ?></span>
          <?php if ((string) $doDia['categoria'] !== ''): ?><span><?= e((string) $doDia['categoria']) ?></span><?php endif; ?>
        </div>
        <p class="news-item__resumo">
          <?php if (!$rankDia): ?>
            Ninguém acertou a palavra de hoje ainda.
          <?php else: ?>
            <?php foreach ($rankDia as $i => $rd): ?>
              <?= ['🥇', '🥈', '🥉'][$i] ?? ($i + 1) . 'º' ?> @<?= e((string) $rd['nome']) ?>
              (<?= (int) $rd['erros'] ?> erro<?= (int) $rd['erros'] === 1 ? '' : 's' ?>)<?= $i < count($rankDia) - 1 ? ' · ' : '' ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </p>
      </div></div>
    </div>
  <?php endif; ?>

  <div class="section-head"><h2>📊 Funil e horários</h2></div>
  <div class="news-list">
    <div class="news-item"><div class="news-item__body">
      <?php
      $inic = max(1, (int) ($f['inic'] ?? 0));
      $conc = (int) round(((int) ($f['term'] ?? 0) / $inic) * 100);
      ?>
      <p class="news-item__resumo">
        🎬 <?= (int) ($f['inic'] ?? 0) ?> partidas iniciadas ·
        🏁 <?= (int) ($f['term'] ?? 0) ?> terminadas (<b><?= $conc ?>%</b>) ·
        🚪 <?= (int) ($f['abandonou'] ?? 0) ?> abandonadas/expiradas ·
        👥 <?= (int) ($f['ativos7'] ?? 0) ?> jogadores nos últimos 7 dias
      </p>
      <?php if ($pico): ?>
        <p class="news-item__resumo">⏰ Horários de pico:
          <?php foreach ($pico as $i => $h): ?>
            <b><?= str_pad((string) (int) $h['h'], 2, '0', STR_PAD_LEFT) ?>h</b> (<?= (int) $h['n'] ?>)<?= $i < count($pico) - 1 ? ' · ' : '' ?>
          <?php endforeach; ?>
        </p>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="section-head" style="display:flex;align-items:center;gap:12px;justify-content:space-between">
    <h2>🏆 Ranking geral do arcade (7 dias)</h2>
    <form method="post" onsubmit="return confirm('Zerar o ranking? Isso apaga TODAS as partidas deste perfil e não tem volta.');">
      <?= csrf_field() ?>
      <input type="hidden" name="cli" value="<?= $cliSel ?>">
      <input type="hidden" name="acao" value="zerar">
      <button class="btn-ghost"><i class="fa-solid fa-eraser"></i> Zerar ranking</button>
    </form>
  </div>
  <?php if (!$ranking): ?>
    <div class="aviso aviso--ok">Ninguém venceu ainda nos últimos 7 dias.</div>
  <?php else: ?>
    <div class="news-list">
      <?php foreach ($ranking as $i => $r): ?>
        <div class="news-item">
          <div class="news-item__body">
            <div class="news-item__meta">
              <span class="tag tag--ok"><?= ['🥇', '🥈', '🥉'][$i] ?? ($i + 1) . 'º' ?></span>
              <b>@<?= e((string) $r['nome']) ?></b>
              <span><?= (int) $r['vitorias'] ?> vitória(s) em <?= (int) $r['partidas'] ?> partida(s)</span>
            </div>
            <form method="post" class="news-item__acoes">
              <?= csrf_field() ?>
              <input type="hidden" name="cli" value="<?= $cliSel ?>">
              <input type="hidden" name="acao" value="enviar_ranking">
              <input type="hidden" name="remetente_id" value="<?= e((string) $r['remetente_id']) ?>">
              <button class="btn-ghost"><i class="fa-solid fa-paper-plane"></i> Mandar o ranking pra esta pessoa</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section-head"><h2>👥 Jogadores (<?= count($jogadores) ?>)</h2></div>
  <?php if (!$jogadores): ?>
    <div class="aviso aviso--ok">Ninguém jogou ainda neste perfil.</div>
  <?php else: ?>
    <div class="news-list">
      <?php foreach ($jogadores as $j): ?>
        <?php
        $tot  = max(1, (int) $j['partidas']);
        $taxa = (int) round(((int) $j['vitorias'] / $tot) * 100);
        ?>
        <div class="news-item">
          <div class="news-item__body">
            <div class="news-item__meta">
              <b>@<?= e((string) $j['quem']) ?></b>
              <?php if ($j['segue'] !== null): ?>
                <span class="tag"><?= ((int) $j['segue'] === 1) ? 'te segue' : 'não te segue' ?></span>
              <?php endif; ?>
              <?php if (!empty($j['seguidores'])): ?><span><?= (int) $j['seguidores'] ?> seguidores</span><?php endif; ?>
              <span>última: <?= e(date('d/m H:i', strtotime((string) $j['ultima']))) ?></span>
            </div>
            <p class="news-item__resumo">
              🎮 <?= (int) $j['partidas'] ?> partida(s) ·
              ✅ <?= (int) $j['vitorias'] ?> · 💀 <?= (int) $j['derrotas'] ?> ·
              🎯 <?= $taxa ?>% de vitória
              <?php $sq = jogo_sequencia($db, $cliSel, (string) $j['remetente_id']); ?>
              <?= $sq['atual'] > 1 ? ' · 🔥 ' . $sq['atual'] . ' seguidas' : '' ?>
              <?= $sq['melhor'] > 1 ? ' · melhor: ' . $sq['melhor'] : '' ?>
              <?= (int) $j['ativas'] > 0 ? ' · <b>jogando agora</b>' : '' ?>
            </p>
            <form method="post" class="news-item__acoes">
              <?= csrf_field() ?>
              <input type="hidden" name="cli" value="<?= $cliSel ?>">
              <input type="hidden" name="acao" value="enviar_ranking">
              <input type="hidden" name="remetente_id" value="<?= e((string) $j['remetente_id']) ?>">
              <button class="btn-ghost"><i class="fa-solid fa-trophy"></i> Mandar o ranking</button>
              <a class="btn-ghost" href="<?= BASE_URL ?>/direct.php?cli=<?= $cliSel ?>"><i class="fa-solid fa-comments"></i> Abrir no Direct</a>
            </form>
            <form method="post" class="news-item__acoes" onsubmit="return confirm('Apagar o histórico de partidas desta pessoa?');">
              <?= csrf_field() ?>
              <input type="hidden" name="cli" value="<?= $cliSel ?>">
              <input type="hidden" name="acao" value="zerar_pessoa">
              <input type="hidden" name="remetente_id" value="<?= e((string) $j['remetente_id']) ?>">
              <button class="btn-ghost"><i class="fa-solid fa-eraser"></i> Zerar histórico desta pessoa</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($dificeis): ?>
    <div class="section-head"><h2>💀 Palavras da forca que mais derrubaram</h2></div>
    <div class="news-list">
      <?php foreach ($dificeis as $d): ?>
        <div class="news-item"><div class="news-item__body">
          <b><?= e(mb_strtoupper((string) $d['palavra'], 'UTF-8')) ?></b>
          — <?= (int) $d['perdeu'] ?> derrota(s) em <?= (int) $d['n'] ?> partida(s)
        </div></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section-head"><h2>Partidas recentes (todos os jogos)</h2></div>
  <?php if (!$partidas): ?>
    <div class="aviso aviso--ok">Nenhuma partida ainda. Mande “jogo” no Direct para ver o menu.</div>
  <?php else: ?>
    <div class="news-list">
      <?php foreach ($partidas as $p): ?>
        <?php
        $rot = ['ativa' => '🎮 em andamento', 'ganhou' => '✅ ganhou', 'perdeu' => '💀 perdeu',
                'empatou' => '🤝 empatou', 'desistiu' => '🏳️ desistiu', 'expirada' => '⌛ expirou'][(string) $p['status']] ?? $p['status'];
        $nomeJogo = (string) ($catJogos[(string) $p['jogo']]['rotulo'] ?? $p['jogo']);
        ?>
        <div class="news-item">
          <div class="news-item__body">
            <div class="news-item__meta">
              <span class="tag tag--ok"><?= e($nomeJogo) ?></span>
              <span class="tag"><?= e($rot) ?></span>
              <b>@<?= e((string) $p['quem']) ?></b>
              <span><?= e(date('d/m H:i', strtotime((string) $p['criado_em']))) ?></span>
            </div>
            <p class="news-item__resumo">
              <?php
              $tit = trim((string) $p['titulo']);
              $ehDoDia = strpos($tit, 'dia:') === 0;
              $tit = $ehDoDia ? substr($tit, 4) : $tit;
              ?>
              <?php if ($tit !== ''): ?>
                <b><?= e(mb_strtoupper($tit, 'UTF-8')) ?></b><?= $ehDoDia ? ' <span class="tag">🗓️ do dia</span>' : '' ?>
              <?php endif; ?>
              <?php if ((string) $p['jogo'] === 'forca' && (string) $p['chave'] !== ''): ?>
                — <?= e(jogo_mascara((string) $p['chave'], (string) $p['letras'])) ?>
              <?php endif; ?>
              · <?= e((string) $p['extra']) ?>
              <?= trim((string) $p['dica']) !== '' ? ' · 💡 ' . e((string) $p['dica']) : '' ?>
            </p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
