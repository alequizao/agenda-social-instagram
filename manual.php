<?php
require __DIR__ . '/init.php';
exigir_login();

$VERSAO = defined('APP_VERSAO') ? APP_VERSAO : '3.7.0';
$cap = (int) cfg_get('pub_cap_dia', '40');

$page_title = 'Manual de uso';
require __DIR__ . '/partials/head.php';
?>
<style>
.man{max-width:880px}
.man h2{margin:26px 0 8px;font-size:20px;display:flex;align-items:center;gap:10px}
.man h2 i{color:#2563EB}
.man h3{margin:18px 0 6px;font-size:15px}
.man p,.man li{line-height:1.6;color:var(--cor-texto,#1e293b)}
.man .card{background:var(--cor-card,#fff);border:1px solid rgba(0,0,0,.08);border-radius:14px;padding:18px 20px;margin:12px 0}
.man ol,.man ul{margin:6px 0 6px 22px}
.man .pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;color:#fff}
.man .toc{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 4px}
.man .toc a{font-size:13px;font-weight:600;text-decoration:none;background:#2563EB11;color:#2563EB;padding:6px 12px;border-radius:9px}
.man .step{display:flex;gap:12px;margin:10px 0}
.man .step .n{flex:0 0 28px;height:28px;border-radius:50%;background:#2563EB;color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center}
.man kbd{background:#1e293b;color:#fff;border-radius:6px;padding:1px 7px;font-size:12px}
.man .warn{border-left:4px solid #f59e0b;background:#f59e0b14;padding:12px 16px;border-radius:8px;margin:10px 0}
.man .ok{border-left:4px solid #16a34a;background:#16a34a14;padding:12px 16px;border-radius:8px;margin:10px 0}
</style>
<main class="wrap man">
  <div class="page-head">
    <div>
      <h1><i class="fa-solid fa-circle-question"></i> Manual de uso</h1>
      <p>Guia completo do sistema <b>Agenda Social</b> — versão <?= e($VERSAO) ?>. Você é o <b>redator-chefe</b>.</p>
    </div>
  </div>

  <div class="toc">
    <a href="#visao">Visão geral</a>
    <a href="#dia">O dia a dia</a>
    <a href="#mesa">Mesa de Redação</a>
    <a href="#filtros">Os 2 filtros</a>
    <a href="#onibus">Ônibus automático</a>
    <a href="#jogo">Jogo da Forca</a>
    <a href="#arcade">Arcade (6 jogos)</a>
    <a href="#limites">Limites & bloqueios</a>
    <a href="#audio">Áudio/vídeo</a>
    <a href="#config">Configuração</a>
    <a href="#telas">Outras telas</a>
    <a href="#pwa">Instalar no celular</a>
    <a href="#problemas">Problemas</a>
  </div>

  <div class="card" id="visao">
    <h2><i class="fa-solid fa-eye"></i> 1. Visão geral</h2>
    <p>O sistema lê os portais de notícias de Maceió/Alagoas, <b>classifica cada matéria por tema</b>
       (crimes, CSA/CRB, ônibus, eventos, ações sociais) e descarta o que está fora da linha
       (atriz/ator global, novela, reality, futebol nacional, loteria, assuntos aleatórios).</p>
    <p>De manhã, você abre a <b>Mesa de Redação</b>, vê tudo já filtrado e <b>decide o que vai ao ar hoje</b>.
       O sistema gera a arte (com áudio), distribui ao longo do dia e publica no Instagram nos horários certos.</p>
    <div class="ok"><b>Regra de ouro:</b> nada vai ao ar sem a sua aprovação — <b>exceto transporte público de Maceió</b>,
       que posta sozinho. E nunca passamos de <b><?= $cap ?> posts por dia</b>.</div>
  </div>

  <div class="card" id="dia">
    <h2><i class="fa-solid fa-mug-hot"></i> 2. O dia a dia (passo a passo)</h2>
    <div class="step"><div class="n">1</div><div>Abra a <b>Mesa de Redação</b> no menu. Clique em <kbd>Buscar agora</kbd> para trazer as últimas notícias.</div></div>
    <div class="step"><div class="n">2</div><div>Fique na aba <b>⭐ Da linha</b>: são as matérias que combinam com o perfil. Leia o título e a fonte.</div></div>
    <div class="step"><div class="n">3</div><div>Clique <b>Aprovar p/ hoje</b> nas que você quer publicar. Use <b>Recusar</b> nas que não quer.</div></div>
    <div class="step"><div class="n">4</div><div>Com pressa? Use <b>Aprovar todas da linha</b> e depois recuse pontualmente o que sobrar.</div></div>
    <div class="step"><div class="n">5</div><div>Confira a aba <b>🔎 Revisar</b> (dúvidas) e <b>🚫 Fora da linha</b> para resgatar algo que queira.</div></div>
    <div class="step"><div class="n">6</div><div>Pronto. O sistema gera a arte com som, agenda no dia e publica. Acompanhe em <b>Agendados</b>.</div></div>
  </div>

  <div class="card" id="mesa">
    <h2><i class="fa-solid fa-pen-nib"></i> 3. Mesa de Redação</h2>
    <h3>As abas</h3>
    <ul>
      <li><b>⭐ Da linha</b> — matérias aprovadas pelo filtro (dentro dos seus temas). É onde você trabalha.</li>
      <li><b>Por tema</b> (Crimes, CSA/CRB, Ônibus, Eventos, Ações sociais) — vê só aquele assunto.</li>
      <li><b>🔎 Revisar</b> — o sistema ficou na dúvida; você decide.</li>
      <li><b>✅ Aprovadas</b> — o que você já marcou para hoje (pode desfazer).</li>
      <li><b>🚫 Fora da linha</b> — o que foi barrado, com o motivo. Pode aprovar mesmo assim.</li>
      <li><b>Todas</b> — tudo das últimas horas.</li>
    </ul>
    <h3>O topo (KPIs)</h3>
    <p>Mostra <b>posts de hoje / teto</b>, o <b>limite da Meta (24h)</b>, quantos <b>restam para não bloquear</b>,
       e quantas estão <b>aprovadas</b> e <b>na linha</b>.</p>
    <h3>As ações de cada matéria</h3>
    <ul>
      <li><span class="pill" style="background:#16a34a">Aprovar p/ hoje</span> — entra na fila do dia (gerada com áudio no próximo ciclo).</li>
      <li><b>Recusar</b> — descarta a matéria.</li>
      <li><b>Desfazer</b> — tira uma matéria já aprovada da fila.</li>
      <li><span class="pill" style="background:#0ea5e9">posta sozinho</span> — é ônibus: vai ao ar sem aprovação (pode barrar uma específica).</li>
    </ul>
  </div>

  <div class="card" id="filtros">
    <h2><i class="fa-solid fa-filter"></i> 4. Os 2 filtros (o "filtro do filtro")</h2>
    <p><b>Filtro 1 — palavras-chave (grátis):</b> lê o título e classifica em um tema, ou marca como fora da linha.</p>
    <p><b>Filtro 2 — IA (só nas dúvidas):</b> quando o título não é claro, a IA confirma o tema e a relevância.
       Aparece a marca <b>· IA</b> na matéria. Você liga/desliga e limita o uso em <b>Configuração</b>.</p>
    <p><b>Filtro 3 — você:</b> a palavra final é sempre do redator-chefe na Mesa de Redação.</p>
    <div class="warn">Mudou os temas na Configuração? Clique em <kbd>Reclassificar</kbd> na Mesa para reavaliar tudo.</div>
  </div>

  <div class="card" id="onibus">
    <h2><i class="fa-solid fa-bus"></i> 5. Ônibus / transporte = automático</h2>
    <p>Toda matéria sobre <b>transporte coletivo e urbano de Maceió</b> (ônibus, tarifa, linhas, terminais,
       SMTT, mobilidade) é publicada <b>sem precisar de aprovação</b> — como combinado. Ela ainda respeita o
       teto de <?= $cap ?>/dia e o limite da Meta. Para impedir uma específica, clique em <b>Barrar esta</b>.</p>
    <p>Pode desligar esse automático em <b>Configuração → Ônibus posta sozinho</b>.</p>
  </div>

  <div class="card" id="limites">
    <h2><i class="fa-solid fa-shield-halved"></i> 6. Limites e como evitar bloqueios</h2>
    <ul>
      <li><b>Teto diário:</b> no máximo <b><?= $cap ?> posts/dia</b> (trava dura, nunca ultrapassa).</li>
      <li><b>Limite da Meta (24h):</b> o sistema lê o <code>content_publishing_limit</code> da sua conta
          (quantos posts já saíram e quantos faltam) e <b>para de agendar</b> quando chega perto, deixando uma
          <b>margem de segurança</b> (configurável). Isso é o "se moldar" para não bloquear.</li>
      <li><b>Pausa manual:</b> o botão <b>Pausar automação</b> no topo congela todas as publicações na hora.</li>
      <li><b>Ritmo seguro:</b> os posts são espaçados ao longo do dia, nunca em rajada.</li>
    </ul>
    <p>Você acompanha esses números no topo da Mesa de Redação e no Painel.</p>
  </div>

  <div class="card" id="audio">
    <h2><i class="fa-solid fa-music"></i> 7. Áudio (a arte vira vídeo)</h2>
    <p>Com o áudio ligado e uma trilha enviada, cada arte vira um <b>MP4 com som</b>. A conversão é feita pelo
       <b>servidor em segundo plano</b> (cron), por isso o áudio sempre funciona — mesmo que algo tenha sido
       gerado pela tela. Ajuste a duração e a trilha em <b>Configuração</b>.</p>
  </div>

  <div class="card" id="config">
    <h2><i class="fa-solid fa-robot"></i> 8. Configuração (principais campos)</h2>
    <ul>
      <li><b>Automação ligada</b> + <b>Conta alvo</b> — qual perfil recebe os posts.</li>
      <li><b>Fluxo</b> — deixe em <b>Curadoria (redator-chefe)</b>.</li>
      <li><b>Linha editorial</b> — quais temas entram (crimes, CSA/CRB, ônibus, eventos, sociais).</li>
      <li><b>Ônibus posta sozinho</b> e <b>Filtro do filtro (IA)</b> — liga/desliga.</li>
      <li><b>Janela de horário</b> — entre que horas pode publicar (padrão 05:00–23:30).</li>
      <li><b>Tipo de post</b>, <b>modelo de arte</b>, <b>cenário do ônibus</b>, <b>áudio</b> e <b>fontes RSS</b>.</li>
      <li><b>Margem do limite da Meta</b> — segurança anti-bloqueio.</li>
    </ul>
  </div>

  <div class="card" id="telas">
    <h2><i class="fa-solid fa-table-cells"></i> 9. Outras telas</h2>
    <ul>
      <li><b>Agendados</b> — a fila do dia. Pré-visualize, recuse, baixe ou poste agora.</li>
      <li><b>Aprovação</b> — fluxo clássico de rascunho (quando usar o modo "aprovação manual").</li>
      <li><b>Erros</b> — o que falhou ao publicar e por quê.</li>
      <li><b>Direct</b> — mensagens do Instagram e respostas automáticas.</li>
      <li><b>Horário dos Ônibus</b> — consulta de linhas de Maceió/Rio Largo e resposta automática no Direct.</li>
      <li><b>Clientes</b> — contas conectadas e estatísticas.</li>
    </ul>
  </div>

  <div class="card" id="onibus-horario">
    <h2><i class="fa-solid fa-bus"></i> 10. Horário dos Ônibus (Maceió/Rio Largo)</h2>
    <p>Na aba <b>Horário dos Ônibus</b> você busca qualquer linha por <b>número</b> (ex.: <code>0004</code>) ou
       por <b>destino/bairro</b> (ex.: <i>rio largo</i>, <i>centro</i>) e vê os <b>horários de operação por dia</b>,
       a <b>rota</b> (origem → destino) e a <b>previsão ao vivo</b> (botão <b>Ao vivo</b>).</p>
    <ul>
      <li><b>De onde vêm os dados:</b> do <b>CittaMobi</b> (mesma fonte do app público). A raspagem completa roda
          no servidor pelo <code>cron_onibus.php</code>; a tela só faz consultas pontuais.</li>
      <li><b>Resposta automática no Direct:</b> quando alguém pergunta por uma linha, o sistema responde sozinho
          os horários — <b>sem IA</b>, só quando há intenção clara (um "bom dia" não dispara). Ligue por perfil em
          <b>Horário dos Ônibus → Configuração &amp; auto-resposta</b>.</li>
      <li><b>Ao vivo é experimental:</b> depende da fonte estar respondendo; se ela mudar, pode falhar sem quebrar o resto.</li>
    </ul>
    <h3>Próximo ônibus no ponto da pessoa (diálogo curto)</h3>
    <p>Quando alguém pergunta por ônibus no Direct, o bot responde <b>curto</b> e manda um <b>link temporário</b>
       (expira em 24h). Esse link abre uma <b>mini-página didática</b>: a pessoa (1) escolhe a linha num
       <b>menu com busca</b>, (2) compartilha a <b>localização</b> e (3) vê em <b>quantos minutos</b> o ônibus passa
       no ponto mais perto dela — o mesmo dado do CittaMobi.</p>
    <p>Se a pessoa já disser onde está no próprio texto (ex.: <i>"0004 estou na Vaz de Castro"</i>), o bot já
       responde o horário ao vivo direto no Direct, sem precisar do link.</p>
    <p>A página do link é <b>estilo CittaMobi</b>: mostra um <b>mapa</b> com você, o ponto e o ônibus (quando ele
       reporta GPS), e os próximos horários <b>atualizando sozinhos a cada 30s</b>. Ela pode ser <b>instalada como app</b>
       (PWA) e a pessoa escolhe ser avisada <b>no Direct</b> ou por <b>notificação no aparelho (push)</b> — o push
       só funciona no navegador de verdade/app instalado (não no navegador interno do Instagram).</p>
    <p><b>Aviso de chegada:</b> na página (ou no Direct) aparece a opção <b>"🔔 me avise quando estiver chegando"</b>.
       Ao tocar, o sistema monitora o ônibus e manda um aviso no Direct quando ele estiver a poucos minutos do ponto
       (o <code>cron_onibus_alertas.php</code> roda a cada minuto). A assinatura expira sozinha em algumas horas.</p>
  </div>

  <div class="card" id="jogo">
    <h2><i class="fa-solid fa-gamepad"></i> 10.1 Jogo da Forca no Direct</h2>
    <p>Quem mandar <b>“forca”</b> (ou “jogo da forca”) no Direct começa uma partida de <b>forca</b>
       — <b>“jogo”</b>, “jogos” ou “jogar” abrem o <b>menu do arcade</b> com todos os jogos:
       o bot sorteia uma palavra, manda a <b>arte da forca</b> e os quadradinhos ⬜⬜⬜ na posição certa.
       A pessoa vai mandando <b>uma letra por mensagem</b> (ou toca nos <b>botões de letra</b>) até completar —
       ou <b>enforcar</b> em 6 erros. É um jogo <b>independente</b>: não tem relação com o conteúdo do perfil.</p>
    <ul>
      <li><b>Comandos do jogador:</b> uma letra · a palavra inteira · <code>dica</code> (custa 1 vida) ·
          <code>placar</code> (suas vitórias) · <code>ranking</code> (pódio da semana) · <code>parar</code> ·
          <code>categorias</code> — e dá pra escolher o tema escrevendo <code>animais</code>, <code>frutas</code>,
          <code>comidas</code>, <code>objetos</code>, <code>profissoes</code>, <code>paises</code>, <code>estados</code>,
          ou a dificuldade: <code>facil</code>, <code>medio</code>, <code>dificil</code>.</li>
      <li><b>Onde liga:</b> na aba <b>Jogo da Forca</b> (botão Ligado/Desligado por perfil) ou em
          <b>Direct → Configurações → Jogo da Forca</b>, onde ficam gatilho, nº de erros, expiração,
          botões de letra, arte, teto de partidas por pessoa/dia e a lista de palavras (<code>PALAVRA|dica</code>).</li>
      <li><b>Palavras pela IA:</b> ligando <b>“Sortear palavra e dica com a OpenAI”</b>, cada partida vem com uma
          palavra nova (sempre <b>uma palavra só</b>, sem expressões tipo “vai e vem”). Tem custo — aparece no
          relatório como <i>jogo</i>. Se a IA falhar, usa a lista fixa e o jogo não para.</li>
      <li><b>Aba Jogo da Forca:</b> ranking de 7 dias, partidas recentes, palavras que mais derrubaram,
          botão para mandar o ranking no Direct, <b>Zerar ranking</b> (apaga todas as partidas do perfil) ou
          zerar o histórico de uma pessoa só, e encerrar uma partida travada.</li>
      <li><b>Palavra sempre de verdade:</b> o sistema confere cada palavra num <b>dicionário de português</b>
          instalado no servidor e só aceita palavras <b>comuns</b> (nada de “umbrela” ou “vai e vem”).</li>
      <li><b>A dica vem junto:</b> na primeira mensagem já aparece a dica da palavra. Durante o jogo,
          <code>dica</code> (ou o botão 🆘) <b>revela uma letra</b> e custa 1 vida.</li>
      <li><b>Palavra do dia:</b> uma vez por dia todo mundo joga a <b>mesma palavra</b> e disputa o pódio do
          dia (quem acerta com menos erros, mais rápido). Aparece na aba Jogo da Forca.</li>
      <li><b>Fica mais difícil:</b> quem vence muito sobe de nível (🟢 fácil → 🟡 médio → 🔴 difícil) e ganha
          conquistas (🔥 3 seguidas, 👑 10 seguidas, 🎖️ acerto sem erro).</li>
      <li><b>Imagem pra postar:</b> no fim da partida o bot manda uma arte "ACERTEI!" com a palavra e o
          convite <i>manda JOGO no direct</i> — pronta pro jogador postar no story e trazer gente nova.</li>
      <li><b>Sozinho no ar:</b> o <code>cron_jogo.php</code> (a cada 5 min) avisa quem deixou a partida parada,
          sorteia a palavra do dia e pode mandar o <b>ranking na segunda de manhã</b> para quem jogou.</li>
      <li><b>Não atrapalha o resto:</b> durante a partida, pergunta longa (ex.: horário de ônibus) continua
          sendo respondida normalmente; as letras vão pro jogo.</li>
    </ul>
  </div>

  <div class="card" id="arcade">
    <h2><i class="fa-solid fa-dice"></i> 10.2 Arcade do Direct (6 jogos)</h2>
    <p>Além da forca, quem mandar <b>“jogo”</b> (ou “jogos”/“jogar”) no Direct recebe um menu com botões e escolhe:</p>
    <ul>
      <li><b>🎯 Forca</b> (<code>forca</code>) — a palavra letra por letra.</li>
      <li><b>🟩 Termo</b> (<code>termo</code>) — 5 letras em 6 tentativas, com 🟩🟨⬛ e <b>palavra do dia</b>.</li>
      <li><b>❓ Quiz</b> (<code>quiz</code>) — 5 perguntas de A a D, respondidas por botão.</li>
      <li><b>🕵️ Enigma</b> (<code>enigma</code>) — “quem sou eu” com 3 pistas.</li>
      <li><b>⭕ Jogo da velha</b> (<code>velha</code>) — tabuleiro em emoji contra o bot (ele não perde).</li>
      <li><b>🔀 Anagrama</b> (<code>anagrama</code>) — desembaralhar a palavra, com dica.</li>
      <li><b>🔢 Adivinha</b> (<code>adivinha</code>) — número de 1 a 100 em 7 chances.</li>
    </ul>
    <p>Comandos que valem sempre: <code>jogo</code> (menu), <code>ranking</code> (pódio geral),
       <code>placar</code> (o seu, somando todos os jogos) e <code>parar</code>.</p>
    <p>Liga e desliga cada jogo em <b>Direct → Configurações → Jogos do arcade</b>. Tudo aparece na aba
       <b>Jogo da Forca</b>: partidas por jogo, quem está jogando agora e o ranking geral —
       a tela <b>se atualiza sozinha a cada 10 segundos</b> (dá para pausar no rodapé da página).</p>
  </div>

  <div class="card" id="pwa">
    <h2><i class="fa-solid fa-mobile-screen"></i> 11. Instalar como app (PWA)</h2>
    <p><b>Jeito mais fácil:</b> clique no botão <b><i class="fa-solid fa-download"></i> Instalar app</b> no topo da tela. No iPhone ele mostra o passo a passo.</p>
    <p><b>No celular (Android/Chrome):</b> abra o site, toque no menu <b>⋮</b> → <b>Adicionar à tela inicial</b>.</p>
    <p><b>No iPhone (Safari):</b> toque em <b>Compartilhar</b> → <b>Adicionar à Tela de Início</b>.</p>
    <p><b>No computador (Chrome/Edge):</b> clique no ícone de <b>instalar</b> na barra de endereço.</p>
    <p>O app abre em tela cheia, com ícone próprio e atalhos para a Mesa de Redação, Agendados e Direct.</p>
  </div>

  <div class="card" id="problemas">
    <h2><i class="fa-solid fa-triangle-exclamation"></i> 12. Problemas comuns</h2>
    <ul>
      <li><b>Não está postando:</b> veja se a automação não está <b>pausada</b> (banner vermelho no topo) e se há
          matérias <b>aprovadas</b> ou de ônibus na fila.</li>
      <li><b>Parou perto do limite:</b> normal — é o anti-bloqueio. Volta sozinho quando a janela de 24h libera.</li>
      <li><b>Post sem áudio:</b> o cron conserta sozinho no ciclo seguinte (transforma em vídeo com som).</li>
      <li><b>Matéria no tema errado:</b> aprove/recuse manualmente; ajuste os temas e clique em <b>Reclassificar</b>.</li>
    </ul>
  </div>

  <div class="card" style="text-align:center">
    <p style="margin:0 0 4px"><b>Agenda Social</b> · versão <?= e($VERSAO) ?> · fuso America/Maceió</p>
    <p style="margin:0;color:var(--cor-texto-sec,#64748b);font-size:13px">
      Desenvolvido por <b><?= e(DEV_NOME) ?></b> · <?= e(DEV_FONE) ?> · <?= e(DEV_EMAIL) ?>
    </p>
  </div>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
