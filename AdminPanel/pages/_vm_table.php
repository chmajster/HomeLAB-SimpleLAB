<div class="table-wrap"><table>
<thead><tr><th>Hostname</th><th>IP</th><th>MAC</th><th>OS</th><th>Puppet Master</th><th>Environment</th><th>Registered</th><th>Last Seen</th></tr></thead>
<tbody>
<?php foreach ($rows as $vm): ?>
<tr>
<td><?= e($vm['hostname']) ?></td><td><?= e($vm['ip_address']) ?></td><td><?= e($vm['mac_address']) ?></td>
<td><?= e(trim($vm['os'] . ' ' . $vm['os_version'])) ?></td><td><?= e($vm['puppet_server']) ?></td><td><?= e($vm['puppet_environment']) ?></td>
<td><?= e($vm['created_at']) ?></td><td><?= e($vm['last_seen']) ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8">Brak zarejestrowanych VM.</td></tr><?php endif; ?>
</tbody></table></div>
