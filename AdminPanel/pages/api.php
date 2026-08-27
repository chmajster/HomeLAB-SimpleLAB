<?php $base = setting($db, 'base_url', '') ?: ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'SERVER_IP')); ?>
<h1>API</h1>
<p><strong>Health:</strong> <code><?= e($base) ?>/api/v1/health</code></p>
<p><strong>Onboarding:</strong> <code>POST <?= e($base) ?>/api/v1/onboarding</code></p>
<p>API token jest przechowywany wyłącznie jako hash. Po rotacji poprzedni token natychmiast przestaje działać.</p>
<?php if ($newApiToken): ?><div class="alert success"><strong>Nowy token (widoczny tylko teraz):</strong><br><code><?= e($newApiToken) ?></code></div><?php endif; ?>
<form method="post" onsubmit="return confirm('Zmienić token API? Poprzedni przestanie działać.');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="rotate_api_token"><button type="submit">Wygeneruj nowy token</button></form>
<pre>curl -X POST <?= e($base) ?>/api/v1/onboarding \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"machine_id":"123456789abcdef"}'</pre>
