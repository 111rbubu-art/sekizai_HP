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
<style>
:root{--bg:#f7f6f3;--soft:#efece5;--ink:#1a1a1a;--stone:#666;--rule:rgba(26,26,26,.14)}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);line-height:1.8;letter-spacing:.04em;
  font-family:system-ui,-apple-system,"Hiragino Sans","Yu Gothic",Meiryo,sans-serif;font-size:15px}
.wrap{max-width:1100px;margin:0 auto;padding:0 clamp(16px,4vw,40px)}
header.top{background:#fff;border-bottom:1px solid var(--rule);padding:22px 0}
h1{margin:0;font-size:20px;letter-spacing:.14em;font-weight:400;
  font-family:"Hiragino Mincho ProN","Yu Mincho",Georgia,serif}
.sub{margin:6px 0 0;font-size:12.5px;color:var(--stone);letter-spacing:.06em}
.count{margin-top:14px;font-size:12.5px;color:var(--stone)}
.count b{color:var(--ink);font-weight:600}

.flash{margin:20px 0 0;padding:16px 20px;border:1px solid var(--ink);background:#fff;font-size:14px}
.flash.ng{border-color:#9c4128;background:#f7ece8;color:#7d3320}
.warn{margin:20px 0 0;padding:18px 20px;border:1px solid #9c4128;background:#f7ece8;color:#7d3320;font-size:14px}
.warn b{display:block;margin-bottom:6px}

h2{margin:44px 0 4px;font-size:16px;letter-spacing:.12em;font-weight:400;
  font-family:"Hiragino Mincho ProN","Yu Mincho",Georgia,serif;
  padding-bottom:10px;border-bottom:1px solid var(--rule)}

.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px;margin-top:20px}
.card{background:#fff;border:1px solid var(--rule);display:flex;flex-direction:column}
.thumb{position:relative;aspect-ratio:4/3;background:var(--soft);overflow:hidden}
.thumb img{width:100%;height:100%;object-fit:contain;display:block}
.tag{position:absolute;top:8px;left:8px;font-size:10px;letter-spacing:.16em;padding:3px 8px;
  background:rgba(26,26,26,.72);color:#f7f6f3}
.tag.ok{background:rgba(40,90,60,.82)}
.body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:10px;flex:1}
.name{font-size:12.5px;letter-spacing:.02em;word-break:break-all}
.meta{font-size:11.5px;color:var(--stone);line-height:1.7}
.alt{font-size:12px;color:var(--stone);border-left:2px solid var(--rule);padding-left:9px}
form{margin-top:auto;display:flex;flex-direction:column;gap:8px}
input[type=file]{font:inherit;font-size:12.5px;width:100%}
button{font:inherit;font-size:13px;letter-spacing:.14em;padding:11px 16px;border:1px solid var(--ink);
  background:var(--ink);color:var(--bg);cursor:pointer;transition:background .25s,color .25s}
button:hover{background:transparent;color:var(--ink)}
button[disabled]{opacity:.35;cursor:default}
footer{margin:60px 0 40px;padding-top:24px;border-top:1px solid var(--rule);font-size:12px;color:var(--stone)}
footer a{color:inherit}
@media(max-width:600px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<header class="top">
  <div class="wrap">
    <h1>写真の入れ替え</h1>
    <p class="sub">庄司石材店ホームページ</p>
    <p class="count">写真枠 <b><?= $total ?></b> か所　／　差し替え済み <b><?= $done ?></b>　残り <b><?= $total - $done ?></b></p>
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
