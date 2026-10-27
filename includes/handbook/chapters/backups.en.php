<?php defined('NIBBLY_HANDBOOK') || exit; ?>
<div class="hb-prose">
  <p>You can hardly break anything. nibbly keeps backups on two levels:</p>
</div>
<div class="hb-cards">
  <div class="hb-card"><h4>Backups of a page</h4><p>Every time you save, nibbly keeps the previous version. The last 30 versions of each page are kept.</p></div>
  <?php if ($hb->isAdmin): ?><div class="hb-card"><h4>Website backup</h4><p>A ZIP file of the whole website: content, images and program files. Manually or automatically on a schedule.</p></div><?php endif; ?>
</div>

<h3>Bringing back an earlier version of a page</h3>
<ol class="hb-steps">
  <li><span>Open the page on the website. If it has backups, a select with date and time appears in the middle of the bar.</span></li>
  <li><span>Pick the version you want and click <?php echo $hb->bar('Apply'); ?>.</span></li>
  <li><span>After confirming, the page reloads with the old version. The version it replaces is backed up first, so you can always go back.</span></li>
</ol>
<div class="hb-prose">
  <p>In the dashboard you find the same backups in a page's Content Editor, with <?php echo $hb->ui('View'); ?> to compare first.<?php if ($hb->module('menus') && $hb->isAdmin): ?> Menus and footer have their own backups, see <?php echo $hb->ref('menus'); ?>.<?php endif; ?></p>
</div>

<?php if ($hb->isAdmin): ?>
<h3>Website backup</h3>
<?php echo $hb->shot('backups.webp', 'Backup area with Create Backup, restore and stored backups', '<b>Backup.</b> Create and restore at the top, below the stored backups and the schedule.'); ?>
<dl class="hb-fields">
  <div><dt>Create Backup</dt><dd>Downloads a ZIP file of the whole website and keeps a copy on the server. nibbly never deletes manual backups by itself.</dd></div>
  <div><dt>Restore</dt><dd>From a ZIP file or a stored backup. <b>Content only</b> replaces pages, images and templates and leaves the program and user accounts alone. <b>Full restore</b> replaces everything; the current state is backed up first.</dd></div>
  <div><dt>Automatic backups</dt><dd>On a schedule, with retention (such as 7 daily, 4 weekly, 12 monthly) and a storage limit. The server has to trigger a call regularly for this; the instructions are under “Cron setup”.</dd></div>
  <div><dt>External targets</dt><dd>Copies also go to Dropbox, Google Drive, OneDrive, an SFTP or FTP server or S3 storage. That way a backup is safe even if the server fails.</dd></div>
</dl>
<?php echo $hb->note('Careful', 'At the very bottom of the Backup area is “Full reset”. It deletes the whole website and can’t be undone. Download a current backup first.'); ?>
<?php endif; ?>
