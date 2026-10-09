<?php
/**
 * Badge.
 *
 * @var string $text
 * @var string $tone   default|secondary|outline|destructive|success|warning|info|muted|soft-*
 * @var bool   $dot
 * @var string|null $tooltip
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$tone = $tone ?? 'secondary';
$class = 'badge badge-' . $tone . (!empty($dot) ? ' badge-dot' : '') . (isset($class) ? ' ' . $class : '');
?>
<span class="<?= $this->e($class) ?>"<?= empty($tooltip) ? '' : ' data-tooltip="' . $this->e($tooltip) . '"' ?>><?= $this->e($text ?? '') ?></span>
