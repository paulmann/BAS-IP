<?php
/**
 * Dependency-free chart placeholder.
 *
 * The PHP side only emits the data as JSON; `admin.js` renders inline SVG.
 * That keeps the payload tiny and the chart crisp at any DPI without a chart
 * library (no CDN, no 200 KB of JS).
 *
 * @var string $type    bars|line|donut|ranks
 * @var array  $data    ['labels'=>[], 'values'=>[]] | ['items'=>[['label'=>..,'value'=>..]]]
 * @var int    $height
 * @var bool   $money
 * @var string|null $center
 * @var string|null $centerSub
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$type = $type ?? 'bars';
$data = $data ?? [];
$payload = $data;
$payload['height'] = $height ?? 120;
$payload['money'] = !empty($money);
if (!empty($center)) { $payload['center'] = $center; }
if (!empty($centerSub)) { $payload['centerSub'] = $centerSub; }
?>
<div data-chart="<?= $this->e($type) ?>" data-chart-data="<?= $this->e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"
     class="w-full" style="min-height: <?= (int) ($height ?? 120) ?>px" role="img"
     aria-label="<?= $this->e($ariaLabel ?? 'Диаграмма') ?>"></div>
