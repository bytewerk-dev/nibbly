<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>The website <b><?php echo $hb::esc($hb->siteName); ?></b> runs on <b>nibbly</b>, a lean content management system. If you know WordPress, you'll find your way around quickly: there's a dashboard, entries with drafts and publishing, a media library and a trash.</p>
  <p>The biggest difference: you edit pages <b>right on the website</b>. You click on a text and start typing, no block editor needed. There are no plugins, theme updates or database.</p>
  <p>You work in two places:</p>
</div>
<div class="hb-cards">
  <div class="hb-card"><h4>On the website</h4><p>For texts, images and links on the pages. Switch on the <b>Visual Editor</b> in the bar at the top.</p></div>
  <div class="hb-card"><h4>In the dashboard</h4><p>For everything managed in lists: pages<?php
    $lists = array_filter([
        $hb->module('news') ? 'news' : '',
        $hb->module('events') ? 'events' : '',
        $hb->module('people') ? 'people' : '',
        $hb->module('messages') ? 'messages' : '',
    ]);
    echo $lists ? ', ' . $hb::esc(implode(', ', $lists)) : '';
  ?> and the settings.</p></div>
</div>

<h3>Your WordPress knowledge, translated</h3>
<div class="hb-table-scroll">
  <table class="hb-compare">
    <thead><tr><th>In WordPress</th><th>In nibbly</th><th>Note</th></tr></thead>
    <tbody>
      <tr><td>/wp-admin</td><td>/admin</td><td>Sign-in and dashboard.</td></tr>
      <tr><td>Gutenberg, editing pages</td><td>Visual Editor</td><td>Edit right on the page, the way visitors see it.</td></tr>
      <?php if ($hb->module('news')): ?><tr><td>Posts</td><td>News</td><td>With drafts, publishing and a cover image.</td></tr><?php endif; ?>
      <tr><td>Media Library</td><td>Media Library and image manager</td><td>Upload images, sort them into folders, add alt text.</td></tr>
      <?php if ($hb->module('menus') && $hb->isAdmin): ?><tr><td>Appearance › Menus</td><td>Menu/Footer</td><td>Drag to sort, a switch for visible or hidden.</td></tr><?php endif; ?>
      <tr><td>Revisions</td><td>Backups</td><td>Bring back an earlier version of a page with one click.</td></tr>
      <?php if ($hb->isAdmin): ?><tr><td>Plugins</td><td>Modules</td><td>Switch extra features on and off in the settings.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<p class="hb-legend"><i></i> Frames in the screenshots show where to click. Click a screenshot to enlarge it.</p>
<?php if (!$hb->isAdmin): ?>
<?php echo $hb->note('Your edition', 'This handbook shows only the areas your account can see. Settings, users and full-site backups are managed by the admins.'); ?>
<?php endif; ?>
