<?php
/**
 * Empty state.
 *
 * @var string      $title
 * @var string|null $description
 * @var string      $icon
 * @var string|null $actionLabel
 * @var string|null $actionUrl
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$title = $title ?? 'Ничего не найдено';
$icon = $icon ?? 'inbox';
?>
<div class="empty">
  <div class="empty-icon"><?= $this->icon($icon, 'h-6 w-6 text-2xl') ?></div>
  <div>
    <div class="empty-title"><?= $this->e($title) ?></div>
    <?php if (!empty($description)): ?>
      <p class="empty-desc mt-1"><?= $this->e($description) ?></p>
    <?php endif ?>
  </div>
  <?php if (!empty($actionLabel) && !empty($actionUrl)): ?>
    <a class="btn btn-default mt-1" href="<?= $this->e($this->url((string) $actionUrl)) ?>">
      <?= $this->icon('plus', 'h-4 w-4') ?> <?= $this->e($actionLabel) ?>
    </a>
  <?php elseif (!empty($actionHtml)): ?>
    <div class="mt-1"><?= $actionHtml ?></div>
  <?php endif ?>
</div>
