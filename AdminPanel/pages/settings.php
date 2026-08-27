<h1>Settings</h1>
<form method="post" class="form-grid">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_settings">
<label>Application Name<input name="application_name" value="<?= e(setting($db, 'application_name', 'HomeLAB SimpleLAB')) ?>" required></label>
<label>Base URL<input name="base_url" value="<?= e(setting($db, 'base_url', '')) ?>" placeholder="http://10.0.0.10"></label>
<label>Active Hostname Pattern<input value="<?= e(active_pattern($db)['pattern']) ?>" disabled></label>
<label>Default Puppet Master<input value="<?= e(setting($db, 'puppet_server', '')) ?>" disabled></label>
<label>Default Puppet Environment<input value="<?= e(setting($db, 'puppet_environment', 'production')) ?>" disabled></label>
<div><button type="submit">Zapisz</button></div>
</form>
