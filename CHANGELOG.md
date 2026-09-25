# Changelog — Agenda Social

Desenvolvido por Alex Junior (alequizao) · alequizao.dev@gmail.com

## 3.10.7 — 2026-09-25

### Novo
- **`relogio_onibus.php`** — API enxuta e pública para o app Garmin **"Próximo Ônibus"**
  (FR55/FR165/FR165 Music) e para o painel alequizao.com/garmin. Sem sessão, sem scraping no load.
  - `?a=prox&f=ID.STOP[,...]` (até 4 favoritos) → `{"f":[[cód, destino, ponto, fonte, [segundos...], info]]}`
    — fonte `1` ao vivo (GPS dos ônibus, CittaMobi), `2` programado (tabela de hoje da parada), `0` sem previsão, `-1` inválido.
  - `?a=perto&lat=&lon=` → até 4 pontos a 1,5 km (coordenadas do cache de geocode) com até 6 linhas cada.
  - `?a=linhas&q=` e `?a=paradas&l=` para o painel escolher os favoritos.
  - Textos cortados no servidor (destino 18, ponto 22 caracteres) — o FR55 tem 128 KB.
- Coluna `onibus_linhas.partidas` (horários programados por parada + frequência por dia) e
  `onibus_linhas.relogio_em` (linhas usadas por relógios têm os horários atualizados primeiro no cron).
  Migração: `migracao_onibus_relogio.sql`.

### Corrigido
- **Raspagem das linhas quebrada desde ~11/09**: a página do CittaMobi ganhou CSS inline com
  `.stop-name {…}` e o regex pegava o CSS como nome da 1ª parada — os nomes ficavam desalinhados dos ids
  e os horários vinham vazios (`container-horarios-mobile` virou `container-horarios`, com coluna nova
  "Frequência"). Parser corrigido e as 486 linhas reprocessadas (480 com horários por parada).
- `cron_onibus.php`: ordenação com `COALESCE` (um `NULL AND FALSE` jogava as linhas recém-atualizadas
  para o começo da fila).

### Pendente (conhecido)
- O catálogo (`/linhas/estado/alagoas/maceio`) não traz mais as linhas no JSON da página — linhas
  novas não entram sozinhas (as 486 já cadastradas seguem funcionando). 6 linhas antigas dão 404 na fonte.
- Só ~41% dos endereços de parada têm coordenada no Nominatim (o resto falha com o endereço completo).
