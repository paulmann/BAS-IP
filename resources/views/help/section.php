<?php
/**
 * One help section with a sticky table of contents.
 *
 * @var array<string, mixed> $section
 * @var array<int, array<string, mixed>> $sections
 * @var string $html
 * @var array<int, array{level: int, text: string, id: string}> $toc
 * @var array|null $prev
 * @var array|null $next
 * @var bool $printable
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header no-print">
  <div class="min-w-0">
    <div class="flex items-center gap-2 text-xs text-muted-foreground">
      <a class="hover:text-foreground" href="<?= $this->e($this->url('/help')) ?>">Справка</a>
      <?= $this->icon('chevron-right', 'h-3 w-3') ?>
      <span><?= $this->e((string) ($section['group'] ?? '')) ?></span>
    </div>
    <h2 class="page-title mt-1"><?= $this->e((string) ($section['title'] ?? '')) ?></h2>
    <?php if (!empty($section['summary'])): ?><p class="page-desc"><?= $this->e((string) $section['summary']) ?></p><?php endif ?>
  </div>
  <div class="page-actions">
    <button class="btn btn-outline btn-sm" type="button" onclick="window.print()"><?= $this->icon('printer', 'h-3.5 w-3.5') ?> Печать</button>
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/help')) ?>"><?= $this->icon('list', 'h-3.5 w-3.5') ?> Все разделы</a>
  </div>
</div>

<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_17rem]">
  <article class="card min-w-0">
    <div class="card-content prose-basip p-5 sm:p-7">
      <?= $html /* уже экранирован и преобразован Markdown-парсером */ ?>
    </div>

    <div class="card-footer flex-wrap justify-between gap-2 border-t py-4 no-print">
      <?php if ($prev !== null): ?>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/help/' . rawurlencode((string) $prev['slug']))) ?>">
          <?= $this->icon('arrow-left', 'h-3.5 w-3.5') ?> <?= $this->e(\BasIp\Support\Str::limit((string) $prev['title'], 28)) ?>
        </a>
      <?php else: ?><span></span><?php endif ?>
      <?php if ($next !== null): ?>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($this->url('/help/' . rawurlencode((string) $next['slug']))) ?>">
          <?= $this->e(\BasIp\Support\Str::limit((string) $next['title'], 28)) ?> <?= $this->icon('arrow-right', 'h-3.5 w-3.5') ?>
        </a>
      <?php endif ?>
    </div>
  </article>

  <aside class="grid content-start gap-5 no-print">
    <?php if ($toc !== []): ?>
      <nav class="card xl:sticky xl:top-[calc(var(--header-height)+1rem)]" aria-label="Содержание раздела">
        <div class="card-header"><div class="card-title text-sm">Содержание</div></div>
        <div class="card-content p-0">
          <ul class="max-h-[60vh] overflow-auto p-2">
            <?php foreach ($toc as $heading): ?>
              <li>
                <a class="block rounded-md px-2 py-1.5 text-xs leading-snug text-muted-foreground transition hover:bg-accent hover:text-foreground"
                   style="padding-left: <?= 0.5 + ((int) $heading['level'] - 1) * 0.7 ?>rem"
                   href="#<?= $this->e((string) $heading['id']) ?>"><?= $this->e((string) $heading['text']) ?></a>
              </li>
            <?php endforeach ?>
          </ul>
        </div>
      </nav>
    <?php endif ?>

    <?php if (!empty($section['related'])): ?>
      <div class="card">
        <div class="card-header"><div class="card-title text-sm">Связанные разделы</div></div>
        <div class="card-content grid gap-1.5">
          <?php foreach ((array) $section['related'] as $relatedSlug) {
              foreach ($sections as $candidate) {
                  if (($candidate['slug'] ?? '') !== $relatedSlug) {
                      continue;
                  } ?>
                  <a class="flex items-center gap-2 rounded-md px-2 py-1.5 text-xs text-muted-foreground transition hover:bg-accent hover:text-foreground"
                     href="<?= $this->e($this->url('/help/' . rawurlencode((string) $relatedSlug))) ?>">
                      <?= $this->icon((string) ($candidate['icon'] ?? 'book-open'), 'h-3.5 w-3.5') ?>
                      <span class="min-w-0 flex-1 truncate"><?= $this->e((string) ($candidate['title'] ?? '')) ?></span>
                  </a>
          <?php   }
          } ?>
        </div>
      </div>
    <?php endif ?>

    <div class="card">
      <div class="card-header"><div class="card-title text-sm">Разделы</div></div>
      <div class="card-content p-0">
        <ul class="max-h-96 overflow-auto p-2">
          <?php $lastGroup = null; foreach ($sections as $candidate): ?>
            <?php if (($candidate['group'] ?? '') !== $lastGroup): ?>
              <?php $lastGroup = (string) ($candidate['group'] ?? ''); ?>
              <li class="px-2 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"><?= $this->e($lastGroup) ?></li>
            <?php endif ?>
            <li>
              <a class="flex items-center gap-2 rounded-md px-2 py-1.5 text-xs transition <?= ($candidate['slug'] ?? '') === ($section['slug'] ?? '') ? 'bg-accent font-medium text-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground' ?>"
                 href="<?= $this->e($this->url('/help/' . rawurlencode((string) ($candidate['slug'] ?? '')))) ?>">
                <?= $this->icon((string) ($candidate['icon'] ?? 'book-open'), 'h-3.5 w-3.5 shrink-0') ?>
                <span class="min-w-0 flex-1 truncate"><?= $this->e((string) ($candidate['title'] ?? '')) ?></span>
              </a>
            </li>
          <?php endforeach ?>
        </ul>
      </div>
    </div>
  </aside>
</div>
