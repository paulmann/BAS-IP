<?php
/**
 * Command palette (Ctrl+K / ⌘K).
 *
 * Rendered once per page by the layout. The item list is assembled at runtime
 * from `[data-command]` elements plus every sidebar link, so adding a new page
 * to the nav automatically makes it searchable.
 */
declare(strict_types=1);
/** @var BasIp\Core\View $this */
?>
<div id="basip-palette-hint" hidden></div>
<noscript>
  <style>.app-sidebar{transform:none !important;position:sticky}</style>
</noscript>
