<?php $patterns = $db->query('SELECT * FROM hostname_patterns ORDER BY id')->fetchAll(); ?>
<h1>Hostname Patterns</h1>
<div class="table-wrap"><table>
<thead><tr><th>Name</th><th>Pattern</th><th>Start</th><th>Current</th><th>Next</th><th>Active</th><th></th></tr></thead>
<tbody>
<?php foreach ($patterns as $p): ?>
<tr><td colspan="3"><form method="post" class="inline-edit"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_pattern"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input name="name" value="<?= e($p['name']) ?>" required maxlength="100"><input class="mono" name="pattern" value="<?= e($p['pattern']) ?>" required maxlength="63"><input type="number" name="start_number" min="0" value="<?= (int)$p['start_number'] ?>" required><button type="submit">Zapisz</button></form></td><td><?= (int)$p['current_number'] ?></td>
<td><?= e(format_hostname((string)$p['pattern'], (int)$p['current_number'] + 1)) ?></td><td><?= (int)$p['active'] === 1 ? 'YES' : 'NO' ?></td>
<td class="actions">
<?php if ((int)$p['active'] !== 1): ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="activate_pattern"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button type="submit">Aktywuj</button></form>
<form method="post" onsubmit="return confirm('Usunąć wzorzec?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_pattern"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button type="submit" class="danger">Usuń</button></form>
<?php endif; ?>
</td></tr>
<?php endforeach; ?>
</tbody></table></div>
<h2>Dodaj wzorzec</h2>
<form method="post" class="form-grid">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_pattern">
<label>Nazwa<input name="name" required maxlength="100" placeholder="Development"></label>
<label>Wzorzec<input name="pattern" required maxlength="63" placeholder="DEVXXXX"></label>
<label>Numer początkowy<input type="number" name="start_number" min="0" value="1" required></label>
<div><button type="submit">Dodaj</button></div>
</form>
