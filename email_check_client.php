<?php
// email_check_client.php — UI that calls the remote probe over HTTPS
//echo "hello";die();
$REMOTE_ENDPOINT = getenv('EMAIL_PROBE_ENDPOINT') ?: '';
$TOKEN = getenv('EMAIL_PROBE_TOKEN') ?: '';

$email = trim($_GET['email'] ?? '');
$result = null;
$error = null;

if ($email !== '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } else {
        $url = $REMOTE_ENDPOINT . '?' . http_build_query(['email'=>$email,'token'=>$TOKEN]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $error = 'Probe request failed: '.curl_error($ch);
        } else {
            $result = json_decode($resp, true);
            if (!is_array($result)) $error = 'Invalid JSON from probe.';
        }
        curl_close($ch);
    }
}
?>
<!doctype html><meta charset="utf-8"><title>SMTP Email Validator</title>
<style>body{font-family:system-ui;margin:2rem}input{padding:.5rem;border:1px solid #ccc;border-radius:6px;width:22rem}button{padding:.5rem .9rem;border-radius:6px} .badge{padding:.2rem .5rem;border-radius:999px} .ok{background:#e6f7ec;color:#117a2a} .no{background:#fdeaea;color:#a61b1b} .maybe{background:#fff8e1;color:#8a6d00}</style>
<h1>SMTP Email Validator</h1>
<form method="get">
  <label>Email: <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required></label>
  <button>Check</button>
</form>
<?php if ($error): ?>
  <p style="color:#a61b1b"><?= htmlspecialchars($error) ?></p>
<?php elseif ($result): 
  $status = $result['status'] ?? 'unknown';
  $cls = $status==='deliverable'?'ok':($status==='undeliverable'?'no':'maybe');
  $label = ['deliverable'=>'Likely deliverable','undeliverable'=>'Rejected','invalid-format'=>'Invalid format','unknown'=>'Inconclusive'][$status] ?? ucfirst($status);
?>
  <p><strong>Result:</strong> <span class="badge <?= $cls ?>"><?= htmlspecialchars($label) ?></span></p>
  <ul>
    <li><strong>Email:</strong> <?= htmlspecialchars($result['email'] ?? $email) ?></li>
    <?php if (!empty($result['tested_host'])): ?><li><strong>Tested host:</strong> <?= htmlspecialchars($result['tested_host']) ?></li><?php endif; ?>
    <?php if (!empty($result['reason'])): ?><li><strong>Reason:</strong> <?= htmlspecialchars($result['reason']) ?></li><?php endif; ?>
  </ul>
<?php endif; ?>
<p style="color:#555">Note: Port 25 must be open on the <em>probe server</em>. This page only calls it over HTTPS.</p>
