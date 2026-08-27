<h1>Puppet</h1>
<form method="post" class="form-grid">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_puppet">
<label>Puppet Master<input name="puppet_server" value="<?= e(setting($db, 'puppet_server', 'puppet.lab.local')) ?>" required></label>
<label>Puppet Master IP<input name="puppet_server_ip" value="<?= e(setting($db, 'puppet_server_ip', '')) ?>" placeholder="10.0.0.20"></label>
<label>Port<input type="number" min="1" max="65535" name="puppet_port" value="<?= e(setting($db, 'puppet_port', '8140')) ?>" required></label>
<label>Environment<input name="puppet_environment" value="<?= e(setting($db, 'puppet_environment', 'production')) ?>" required></label>
<div><button type="submit">Zapisz</button></div>
</form>
