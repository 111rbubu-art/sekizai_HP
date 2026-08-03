<?php
/*
 * 写真の入れ替え — 管理画面
 * ------------------------------------------------------------------
 * さくらの www/kanri/ に置き、Basic認証をかけて使います。
 * 詳しい設置手順は同じフォルダーの README.md をご覧ください。
 */
require __DIR__ . '/lib.php';
session_start();

$flash = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
unset($_SESSION['flash']);

$slots = collect_slots();

// 最初に出てくるページごとにまとめる（ページの並びは PAGE_LABELS の順）
$order = array_values($PAGE_LABELS);
$groups = array();
foreach ($order as $label) $groups[$label] = array();
foreach ($slots as $rel => $s) {
    $home = $s['pages'][0];
    if (!isset($groups[$home])) $groups[$home] = array();
    $s['also'] = array_slice($s['pages'], 1);   // 他のページでも使っている場合
    $groups[$home][$rel] = $s;
}
$groups = array_filter($groups, 'count');

$total = count($slots);
$done = 0;
foreach ($slots as $rel => $s) {
    $i = slot_info($rel);
    if ($i && !$i['placeholder']) $done++;
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>写真の入れ替え — 庄司石材店</title>
<link rel="stylesheet" href="admin.css">
</head>
<body>

<header class="top">
  <div class="wrap">
    <h1>写真の入れ替え</h1>
    <p class="sub">庄司石材店ホームページ</p>
    <p class="count">写真枠 <b><?= $total ?></b> か所　／　差し替え済み <b><?= $done ?></b>　残り <b><?= $total - $done ?></b></p>
    <nav class="tabs">
      <a class="on" href="index.php">写真の入れ替え</a>
      <a href="works.php">施工例の登録</a>
      <a class="go" href="../index.html" target="_blank" rel="noopener">サイトを見る ↗</a>
      <a class="go" href="../works.html" target="_blank" rel="noopener">施工例のページ ↗</a>
    </nav>
  </div>
</header>

<div class="wrap">

<?php if (!is_protected()): ?>
  <div class="warn">
    <b>この画面にパスワードがかかっていません。</b>
    さくらのコントロールパネルの「アクセス制限」で、<code>kanri</code> フォルダーに
    ユーザー名とパスワードを設定してください。設定するまで、URLを知っている人なら誰でも写真を差し替えられます。
  </div>
<?php endif; ?>

<?php if ($flash): ?>
  <div class="flash <?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>

<?php foreach ($groups as $page => $items): ?>
  <h2><?= h($page) ?></h2>
  <div class="grid">
    <?php foreach ($items as $rel => $s):
      $info = slot_info($rel);
      $ph = !$info || $info['placeholder'];
      $bust = $info ? $info['mtime'] : 0;
    ?>
      <div class="card">
        <div class="thumb">
          <img src="../<?= h($rel) ?>?v=<?= $bust ?>" alt="">
          <span class="tag <?= $ph ? '' : 'ok' ?>"><?= $ph ? '仮画像' : '差し替え済み' ?></span>
        </div>
        <div class="body">
          <div class="name"><?= h($rel) ?></div>
          <div class="meta">
            <?php if ($info): ?>
              <?= $info['w'] ?> × <?= $info['h'] ?> ／ <?= human_bytes($info['bytes']) ?><br>
              更新 <?= date('Y/m/d H:i', $info['mtime']) ?>
            <?php else: ?>
              ファイルがありません
            <?php endif; ?>
          </div>
          <?php if ($s['alt'] !== ''): ?>
            <div class="alt"><?= h($s['alt']) ?></div>
          <?php endif; ?>
          <?php if (!empty($s['also'])): ?>
            <div class="meta"><?= h(implode('・', $s['also'])) ?> でも使っています</div>
          <?php endif; ?>
          <form method="post" action="save.php" enctype="multipart/form-data">
            <input type="hidden" name="slot" value="<?= h($rel) ?>">
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
            <button type="submit">この写真に差し替える</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<footer>
  <p>
    差し替えると、いまの写真は <code>kanri/_backup/</code> に日時つきで残ります。間違えたときはそこから戻せます。<br>
    写真は自動で回転を直し、幅 <?= MAX_WIDTH ?>px まで縮めてから保存します。元の縦横比はそのままです。<br>
    枠の形に合わせて表示時に切り取られるので、被写体は中央寄りに、少し引いて撮ってください。
  </p>
  <p><a href="../index.html">サイトを見る →</a></p>
</footer>

</div>
</body>
</html>
