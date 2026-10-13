<?php
/** @var array $boat  @var list $offerte  @var ?array $attiva  @var ?array $situazione */
use App\Game\MissioniSpeciali;

$descr = static function (array $m): string {
    return match ((string) $m['tipo']) {
        'mine'   => sprintf('Posare %d mine TMB %s. Si parte con %d siluri in meno: tre mine nello spazio di uno. Il campo resta per settimane, e quello che ci salta sopra e\' accreditato a te.',
                     (int) $m['obiettivo'], $m['luogo'], (int) ceil((int) $m['obiettivo'] / 3)),
        'agenti' => sprintf('Sbarcare %d agenti dell\'Abwehr %s: di notte, in superficie, a macchine ferme, a tre miglia dalla costa.',
                     (int) $m['obiettivo'], $m['luogo']),
        default  => sprintf('Stare %s e mandare sei rapporti meteo dal quadrato, uno ogni otto ore. Ogni trasmissione e\' un rischio radiogoniometrico.',
                     $m['luogo']),
    };
};
?>
<div class="pannello" id="missione" style="margin-top:1.2rem">
  <h2>Missioni speciali del BdU</h2>
  <?php if (isset($situazione) && $situazione !== null):
      $m = $situazione['missione']; ?>
    <p class="sommario"><b><?= e(MissioniSpeciali::TIPI[$m['tipo']]) ?></b> — <?= e($descr($m)) ?></p>
    <p class="sommario">Obiettivo in <b><?= e((string) $m['quadrat']) ?></b>: <?= e(number_format($situazione['distanza'], 0, ',', '.')) ?> miglia
      per <?= e(sprintf('%03.0f', $situazione['rilevamento'])) ?> dalla posizione stimata.</p>
    <?php if ($situazione['manca'] !== []): ?>
      <p class="aiuto">Manca: <?= e(implode('; ', $situazione['manca'])) ?>.</p>
    <?php endif; ?>
    <?php if ($m['tipo'] !== 'meteo'): ?>
      <form method="post" action="<?= e(url('/missione/esegui')) ?>">
        <?= csrf_field() ?>
        <button type="submit"<?= $situazione['pronta'] ? '' : ' disabled' ?>><?= $m['tipo'] === 'mine' ? 'Posare le mine' : 'Sbarcare gli agenti' ?></button>
      </form>
    <?php else: ?>
      <p class="aiuto">Il rapporto si manda dalla <a href="<?= e(url('/radio')) ?>">radio</a>, col segnale breve meteorologico.</p>
    <?php endif; ?>
  <?php elseif (isset($attiva) && $attiva !== null): ?>
    <p class="sommario">Accettata, per la prossima uscita: <b><?= e(MissioniSpeciali::TIPI[$attiva['tipo']]) ?></b> — <?= e($descr($attiva)) ?>
      Premio: <?= e((string) $attiva['premio']) ?> prestigio e <?= e((string) $attiva['punti']) ?> punti. Non compiuta al rientro, costa sessanta di prestigio.</p>
    <?php if ((string) $boat['state'] === 'base'): ?>
      <form method="post" action="<?= e(url('/missione/rinuncia')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="bottone--fantasma">Rimetterla al BdU</button>
      </form>
    <?php endif; ?>
  <?php elseif (!empty($offerte)): ?>
    <p class="sommario">Non tutte le uscite sono caccia al tonnellaggio. Il BdU ha questi lavori per chi è pronto a partire;
      se ne accetti uno, parti con quello.</p>
    <?php foreach ($offerte as $o): ?>
      <form method="post" action="<?= e(url('/missione/accetta')) ?>" class="campo" style="border-top:1px solid var(--acciaio-2);padding-top:.6rem">
        <?= csrf_field() ?>
        <input type="hidden" name="missione" value="<?= e((string) $o['id']) ?>">
        <p style="margin:.2rem 0"><b><?= e(MissioniSpeciali::TIPI[$o['tipo']]) ?></b> — <?= e($descr($o)) ?></p>
        <p class="aiuto">Quadrato <?= e((string) $o['quadrat']) ?>. Premio: <?= e((string) $o['premio']) ?> prestigio, <?= e((string) $o['punti']) ?> punti.</p>
        <button type="submit" class="bottone--fantasma">Accettare</button>
      </form>
    <?php endforeach; ?>
  <?php else: ?>
    <p class="sommario">Per ora il BdU non ha lavori speciali da affidare.</p>
  <?php endif; ?>
</div>
