<?php
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM virtual_machines WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $vm = $stmt->fetch();
    if ($vm):
?>
<h1>VM: <?= e($vm['hostname']) ?></h1>
<p><a href="/?page=vms">← Wróć do listy</a></p>
<div class="details">
<?php foreach ([
    'Hostname'=>$vm['hostname'], 'Machine ID'=>$vm['machine_id'], 'IP'=>$vm['ip_address'], 'MAC'=>$vm['mac_address'],
    'OS'=>trim($vm['os'].' '.$vm['os_version']), 'Architecture'=>$vm['architecture'], 'Puppet Master'=>$vm['puppet_server'],
    'Environment'=>$vm['puppet_environment'], 'Registered'=>$vm['created_at'], 'Last Seen'=>$vm['last_seen']
] as $label=>$value): ?>
<div><span><?= e($label) ?></span><strong class="mono"><?= e((string)$value) ?></strong></div>
<?php endforeach; ?>
</div>
<?php else: ?><div class="alert error">Nie znaleziono VM.</div><?php endif; return; }
$rows = $db->query('SELECT * FROM virtual_machines ORDER BY id DESC')->fetchAll(); ?>
<h1>VMs</h1>
<div class="table-wrap"><table>
<thead><tr><th>Hostname</th><th>Machine ID</th><th>IP</th><th>MAC</th><th>OS</th><th>Arch</th><th>Puppet</th><th>Last Seen</th><th></th></tr></thead>
<tbody>
<?php foreach ($rows as $vm): ?>
<tr>
<td><a href="/?page=vms&id=<?= (int)$vm['id'] ?>"><?= e($vm['hostname']) ?></a></td><td class="mono"><?= e($vm['machine_id']) ?></td><td><?= e($vm['ip_address']) ?></td><td><?= e($vm['mac_address']) ?></td>
<td><?= e(trim($vm['os'] . ' ' . $vm['os_version'])) ?></td><td><?= e($vm['architecture']) ?></td><td><?= e($vm['puppet_server'] . ' / ' . $vm['puppet_environment']) ?></td><td><?= e($vm['last_seen']) ?></td>
<td><form method="post" onsubmit="return confirm('Usunąć wpis VM?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_vm"><input type="hidden" name="id" value="<?= (int)$vm['id'] ?>"><button class="danger" type="submit">Usuń</button></form></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9">Brak VM.</td></tr><?php endif; ?>
</tbody></table></div>
