<?php
/**
 * KPI tile.
 *
 * @var string      $label
 * @var string      $value
 * @var string|null $hint
 * @var string      $icon
 * @var string      $accent   ''|success|destructive|warning|info
 * @var string|null $href
 * @var array|null  $trend    ['direction' => 'up'|'down', 'value' => string, 'label' => string]
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$label = $label ?? '';
$value = $value ?? '';
$icon = $icon ?? 'activity';
$accent = $accent ?? '';
$tag = empty($href) ? 'div' : 'a';
$classes = 'stat' . ($accent !== '' ? ' accent-' . $accent . ' is-accent' : '') . (empty($href) ? '' : ' hover:border-primary/40');
?>
<<?= $tag ?> class="<?= $this->e($classes) ?>"<?= empty($href) ? '' : ' href="' . $this->e($this->url((string) $href)) . '"' ?>>
  <span class="stat-icon" aria-hidden="true"><?= $this->icon($icon, 'h-24 w-24 text-8xl') ?></span>
  <span class="stat-label"><?= $this->icon($icon, 'h-3.5 w-3.5') ?> <?= $this->e($label) ?></span>
  <span class="stat-value"><?= $this->e($value) ?></span>
  <?php if (!empty($trend)): ?>
    <span class="stat-trend <?= ($trend['direction'] ?? '') === 'down' ? 'down' : 'up' ?>">
      <?= $this->icon(($trend['direction'] ?? 'up') === 'down' ? 'trending-down' : 'trending-up', 'h-3.5 w-3.5') ?>
      <?= $this->e($trend['value'] ?? '') ?>
      <?php if (!empty($trend['label'])): ?><span class="font-normal text-muted-foreground"><?= $this->e($trend['label']) ?></span><?php endif ?>
    </span>
  <?php endif ?>
  <?php if (!empty($hint)): ?>
    <span class="stat-hint"><?= $this->e($hint) ?></span>
  <?php endif ?>
</<?= $tag ?>>
