<?php
/**
 * Pagination controls.
 *
 * @var BasIp\Support\Paginator $paginator
 * @var array<string, mixed>    $query    current query string (filters preserved)
 * @var string|null             $target   optional base path
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$paginator = $paginator ?? null;
if (!$paginator instanceof \BasIp\Support\Paginator || !$paginator->hasPages()) {
    return;
}
$query = $query ?? [];
$target = $target ?? null;
$current = $paginator->currentPage();
$link = function (int $page) use ($query, $target, $paginator): string {
    $params = array_merge($query, [$paginator->pageName() => $page]);
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '' && $v !== []);
    $base = $target ?? '';
    return $this->url($base) . ($params === [] ? '' : '?' . http_build_query($params));
};
?>
<nav class="flex flex-col gap-3 border-t pt-3 sm:flex-row sm:items-center sm:justify-between" aria-label="Постраничная навигация">
  <div class="text-xs text-muted-foreground">
    Показано <b class="tabular text-foreground"><?= $paginator->firstItem() ?>–<?= $paginator->lastItem() ?></b>
    из <b class="tabular text-foreground"><?= number_format($paginator->total(), 0, ',', ' ') ?></b>
  </div>

  <div class="pagination">
    <div class="pagination-items">
      <a class="pagination-link" href="<?= $this->e($link(max(1, $current - 1))) ?>"
         <?= $paginator->hasPrevious() ? '' : 'aria-disabled="true" tabindex="-1"' ?> aria-label="Предыдущая страница">
        <?= $this->icon('chevron-left', 'h-4 w-4') ?>
      </a>

      <?php foreach ($paginator->pages() as $page): ?>
        <?php if ($page === null): ?>
          <span class="pagination-ellipsis" aria-hidden="true">…</span>
        <?php else: ?>
          <a class="pagination-link" href="<?= $this->e($link((int) $page)) ?>"
             <?= $page === $current ? 'aria-current="page"' : '' ?>><?= (int) $page ?></a>
        <?php endif ?>
      <?php endforeach ?>

      <a class="pagination-link" href="<?= $this->e($link(min($paginator->lastPage(), $current + 1))) ?>"
         <?= $paginator->hasMore() ? '' : 'aria-disabled="true" tabindex="-1"' ?> aria-label="Следующая страница">
        <?= $this->icon('chevron-right', 'h-4 w-4') ?>
      </a>
    </div>
  </div>
</nav>
