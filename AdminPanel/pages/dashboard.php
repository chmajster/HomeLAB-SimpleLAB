<?php
$vmCount = (int)$db->query('SELECT COUNT(*) FROM virtual_machines')->fetchColumn();
$pattern = active_pattern($db);
$next = preview_next_hostname($db);
$recent = $db->query('SELECT * FROM virtual_machines ORDER BY last_seen DESC LIMIT 10')->fetchAll();
?>
<h1>Dashboard</h1>
<div class="cards">
    <div class="card"><span>Registered VMs</span><strong><?= $vmCount ?></strong></div>
    <div class="card"><span>Active pattern</span><strong><?= e($pattern['pattern']) ?></strong></div>
    <div class="card"><span>Next hostname</span><strong><?= e($next) ?></strong></div>
    <div class="card"><span>Puppet Master</span><strong><?= e(setting($db, 'puppet_server', '')) ?></strong></div>
    <div class="card"><span>Environment</span><strong><?= e(setting($db, 'puppet_environment', 'production')) ?></strong></div>
</div>
<h2>Ostatnie VM</h2>
<?php $rows = $recent; require __DIR__ . '/_vm_table.php'; ?>
