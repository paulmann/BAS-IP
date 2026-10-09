<?php
/**
 * Help centre: search, grouped contents, quick guides, keyboard shortcuts.
 *
 * @var array<int, array<string, mixed>> $sections
 * @var string $q
 * @var array<int, array<string, mixed>> $results
 * @var array<int, array<string, mixed>> $quick
 * @var array<int, array<string, mixed>> $shortcuts
 * @var string $version
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
$user = $this->user();
$groups = [];
foreach ($sections as $section) {
    $groups[(string) ($section['group'] ?? 'Прочее')][] = $section;
}
?>
<?php $this->layout('layouts/app') ?>

<div class="page-header">
  <div class="min-w-0">
    <h2 class="page-title">Справка</h2>
    <p class="page-desc">Полное руководство по системе: установка, жители, оплаты, гостевой доступ, шлагбаум, безопасность, API и устранение неполадок.</p>
  </div>
  <div class="page-actions">
    <span class="badge badge-outline">v<?= $this->e($version) ?></span>
    <a class="btn btn-outline btn-sm" href="<?= $this->e($this->url('/help?print=1')) ?>" target="_blank" rel="noopener"><?= $this->icon('printer', 'h-3.5 w-3.5') ?> Версия для печати</a>
  </div>
</div>

<div class="card mb-5">
  <div class="card-content p-5">
    <form method="get" action="<?= $this->e($this->url('/help')) ?>" class="relative">
      <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-muted-foreground"><?= $this->icon('search', 'h-5 w-5') ?></span>
      <input class="input input-lg pl-12 text-base" type="search" name="q" value="<?= $this->e($q) ?>" autofocus
             placeholder="Найти в справке: «перерасчёт», «гостевой доступ», «cron», «импорт из Excel»…"
             aria-label="Поиск по справке" data-global-search>
      <kbd class="kbd absolute inset-y-0 right-4 my-3.5 hidden sm:flex">/</kbd>
    </form>
    <div class="mt-3 flex flex-wrap gap-1.5">
      <?php foreach (['перерасчёт', 'гостевой доступ', 'импорт', 'журнал открываний', '2FA', 'резервная копия', 'API', 'cron', 'тихие часы', 'должники'] as $term): ?>
        <a class="chip" href="<?= $this->e($this->url('/help?q=' . rawurlencode($term))) ?>"><?= $this->e($term) ?></a>
      <?php endforeach ?>
    </div>
  </div>
</div>

<?php if ($q !== ''): ?>
  <section class="mb-6">
    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
      Результаты по запросу «<?= $this->e($q) ?>» · <?= count($results) ?>
    </h3>
    <?php if ($results === []): ?>
      <div class="card"><?= $this->include('components/empty', ['title' => 'Ничего не найдено', 'description' => 'Попробуйте другие слова или посмотрите разделы ниже.', 'icon' => 'search-x']) ?></div>
    <?php else: ?>
      <div class="grid gap-3">
        <?php foreach ($results as $result): ?>
          <a class="card card-hover block p-4" href="<?= $this->e($this->url('/help/' . rawurlencode((string) $result['slug']))) ?>">
            <div class="flex items-start gap-3">
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><?= $this->icon($result['icon'] ?? 'book-open', 'h-4 w-4') ?></span>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-baseline gap-2">
                  <span class="font-medium"><?= $this->e($result['title']) ?></span>
                  <span class="badge badge-muted text-[10px]"><?= $this->e($result['group']) ?></span>
                </div>
                <?php if (!empty($result['summary'])): ?><p class="mt-0.5 text-sm text-muted-foreground"><?= $this->e($result['summary']) ?></p><?php endif ?>
                <?php if (!empty($result['excerpt'])): ?><p class="mt-1.5 text-xs leading-relaxed text-muted-foreground"><?= $result['excerpt'] /* уже экранирован и подсвечен */ ?></p><?php endif ?>
              </div>
            </div>
          </a>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </section>
<?php endif ?>

<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
  <div class="grid content-start gap-6">

    <section>
      <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
        <?= $this->icon('rocket', 'h-4 w-4') ?> Быстрые инструкции
      </h3>
      <div class="grid gap-3 sm:grid-cols-2">
        <?php foreach ($quick as $guide): ?>
          <a class="card card-hover block p-4" href="<?= $this->e($this->url('/help/' . rawurlencode((string) $guide['slug']))) ?>">
            <div class="flex items-start gap-3">
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted"><?= $this->icon($guide['icon'] ?? 'book-open', 'h-4 w-4 text-muted-foreground') ?></span>
              <div class="min-w-0">
                <div class="font-medium"><?= $this->e($guide['title']) ?></div>
                <p class="mt-0.5 text-xs text-muted-foreground"><?= $this->e($guide['text']) ?></p>
              </div>
            </div>
          </a>
        <?php endforeach ?>
      </div>
    </section>

    <?php foreach ($groups as $group => $groupSections): ?>
      <section>
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground"><?= $this->e($group) ?></h3>
        <div class="grid gap-2.5 sm:grid-cols-2">
          <?php foreach ($groupSections as $section): ?>
            <a class="card card-hover flex items-start gap-3 p-3.5" href="<?= $this->e($this->url('/help/' . rawurlencode((string) ($section['slug'] ?? '')))) ?>">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><?= $this->icon((string) ($section['icon'] ?? 'book-open'), 'h-4 w-4') ?></span>
              <span class="min-w-0 flex-1">
                <span class="block truncate font-medium"><?= $this->e((string) ($section['title'] ?? '')) ?></span>
                <span class="mt-0.5 block text-xs text-muted-foreground"><?= $this->e((string) ($section['summary'] ?? '')) ?></span>
              </span>
              <?= $this->icon('chevron-right', 'h-4 w-4 shrink-0 text-muted-foreground') ?>
            </a>
          <?php endforeach ?>
        </div>
      </section>
    <?php endforeach ?>
  </div>

  <aside class="grid content-start gap-5">
    <div class="card">
      <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('keyboard', 'h-4 w-4') ?> Горячие клавиши</div></div>
      <div class="card-content grid gap-2">
        <?php foreach ($shortcuts as $shortcut): ?>
          <div class="flex items-center justify-between gap-3 text-sm">
            <span class="text-muted-foreground"><?= $this->e($shortcut['action']) ?></span>
            <span class="flex shrink-0 gap-1">
              <?php foreach ((array) $shortcut['keys'] as $key): ?>
                <kbd class="kbd"><?= $this->e($key) ?></kbd>
              <?php endforeach ?>
            </span>
          </div>
        <?php endforeach ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('external-link', 'h-4 w-4') ?> Документация проекта</div></div>
      <div class="card-content grid gap-2">
        <?php foreach ([
          ['README.md', 'Обзор, установка, требования', 'https://github.com/paulmann/BAS-IP#readme'],
          ['wiki/', 'Подробная вики проекта', 'https://github.com/paulmann/BAS-IP/wiki'],
          ['docs/CONFIGURATION.md', 'Все параметры конфигурации', 'https://github.com/paulmann/BAS-IP/blob/main/docs/CONFIGURATION.md'],
          ['docs/API.md', 'REST API и вебхуки', 'https://github.com/paulmann/BAS-IP/blob/main/docs/API.md'],
          ['docs/DEPLOYMENT.md', 'nginx, Apache, Docker, systemd', 'https://github.com/paulmann/BAS-IP/blob/main/docs/DEPLOYMENT.md'],
          ['CHANGELOG.md', 'История версий', 'https://github.com/paulmann/BAS-IP/blob/main/CHANGELOG.md'],
        ] as [$label, $hint, $href]): ?>
          <a class="flex items-start gap-2 rounded-lg border p-2.5 transition hover:border-primary/50 hover:bg-accent/40" href="<?= $this->e($href) ?>" target="_blank" rel="noopener noreferrer">
            <?= $this->icon('file-text', 'h-4 w-4 mt-0.5 shrink-0 text-muted-foreground') ?>
            <span class="min-w-0">
              <span class="block truncate font-mono text-xs font-medium"><?= $this->e($label) ?></span>
              <span class="block truncate text-xs text-muted-foreground"><?= $this->e($hint) ?></span>
            </span>
          </a>
        <?php endforeach ?>
      </div>
    </div>

    <?php if ($user !== null && $user->isStaff()): ?>
      <div class="card">
        <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('terminal', 'h-4 w-4') ?> Консоль</div></div>
        <div class="card-content grid gap-2 text-xs">
          <p class="text-muted-foreground">Всё, что есть в интерфейсе, доступно и из командной строки:</p>
          <pre class="code-block"><code>php bin/bas-ip list
php bin/bas-ip doctor
php bin/bas-ip gate:open --apartment=12
php bin/bas-ip billing:preview --total=15000
php bin/bas-ip residents:import file.csv --dry-run
php bin/bas-ip schedule:run --queue</code></pre>
          <a class="btn btn-ghost btn-sm justify-start -ml-2" href="<?= $this->e($this->url('/help/cli')) ?>"><?= $this->icon('book-open', 'h-3.5 w-3.5') ?> Все команды</a>
        </div>
      </div>
    <?php endif ?>

    <div class="card">
      <div class="card-header"><div class="card-title flex items-center gap-2"><?= $this->icon('life-buoy', 'h-4 w-4') ?> Не нашли ответ?</div></div>
      <div class="card-content grid gap-2 text-sm">
        <p class="text-muted-foreground">Проверьте диагностику — она объясняет большинство проблем с подключением к панели и базе.</p>
        <?php if ($user !== null && $user->isStaff()): ?>
          <a class="btn btn-outline btn-sm btn-block" href="<?= $this->e($this->url('/admin/system/doctor')) ?>"><?= $this->icon('stethoscope', 'h-3.5 w-3.5') ?> Запустить диагностику</a>
        <?php endif ?>
        <a class="btn btn-outline btn-sm btn-block" href="<?= $this->e($this->url('/health')) ?>" target="_blank" rel="noopener"><?= $this->icon('heart-pulse', 'h-3.5 w-3.5') ?> Состояние системы</a>
        <a class="btn btn-ghost btn-sm btn-block" href="https://github.com/paulmann/BAS-IP/issues" target="_blank" rel="noopener noreferrer"><?= $this->icon('bug', 'h-3.5 w-3.5') ?> Сообщить о проблеме</a>
      </div>
    </div>
  </aside>
</div>
