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

<div class="note">
  <b>保存する大きさ</b><br>
  写真は保存するときに自動で縮めて軽くします。目標の容量に収まるまで、
  少しずつ圧縮を強めます。<br>
  ふだんは「ふつう」で構いません。下の欄でまとめて変えられます。
  <label class="sizepick" style="margin-top:12px">
    すべての枠を
    <select data-size-all>
      <?php foreach ($SAVE_PRESETS as $k => $ps): ?>
        <option value="<?= h($k) ?>"<?= $k === SAVE_PRESET_DEFAULT ? ' selected' : '' ?>><?= h($ps['label']) ?></option>
      <?php endforeach; ?>
    </select>
    にする
  </label>
</div>

<?php foreach ($groups as $page => $items): ?>
  <h2><?= h($page) ?></h2>
  <div class="grid">
    <?php foreach ($items as $rel => $s):
      $info = slot_info($rel);
      $ph = !$info || $info['placeholder'];
      $bust = $info ? $info['mtime'] : 0;
    ?>
      <div class="card" data-slot="<?= h($rel) ?>">
        <div class="thumb">
          <img src="../<?= h($rel) ?>?v=<?= $bust ?>" alt="" data-thumb>
          <span class="tag <?= $ph ? '' : 'ok' ?>" data-tag><?= $ph ? '仮画像' : '差し替え済み' ?></span>
        </div>
        <div class="body">
          <div class="name"><?= h($rel) ?></div>
          <div class="meta" data-meta>
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
          <form method="post" action="save.php" enctype="multipart/form-data" data-save>
            <input type="hidden" name="slot" value="<?= h($rel) ?>">
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
            <button type="button" class="ghost" data-pick="from">サーバーの写真から選ぶ</button>
            <input type="hidden" name="from" data-pick-value>
            <div data-pick-view></div>
            <label class="sizepick">
              保存する大きさ
              <select name="size">
                <?php foreach ($SAVE_PRESETS as $k => $ps): ?>
                  <option value="<?= h($k) ?>"<?= $k === SAVE_PRESET_DEFAULT ? ' selected' : '' ?>><?= h($ps['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit">この写真に差し替える</button>
            <p class="said" data-said hidden></p>
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
<script src="picker.js" defer></script>
<script>
// 差し替え — 画面を読み込み直さずに送る。
// 一覧の途中で押しても、見ている位置がそのままになるように。
// JavaScript が動かない環境では、ふつうに送信されて読み込み直される。
document.querySelectorAll('form[data-save]').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var card = form.closest('.card');
    var btn  = form.querySelector('button[type=submit]');
    var said = form.querySelector('[data-said]');
    if (btn.disabled) return;

    var body = new FormData(form);
    body.append('ajax', '1');

    btn.disabled = true;
    var was = btn.textContent;
    btn.textContent = '送っています…';
    show(said, '', '写真を送っています。しばらくお待ちください。');

    fetch('save.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.msg === undefined) throw new Error('返事がありません');
        show(said, d.ok ? 'ok' : 'ng', d.msg);
        if (!d.ok) return;

        // 見た目をその場で新しくする
        card.querySelector('[data-thumb]').src = '../' + card.dataset.slot + '?v=' + d.mtime;
        var tag = card.querySelector('[data-tag]');
        tag.textContent = d.ph ? '仮画像' : '差し替え済み';
        tag.classList.toggle('ok', !d.ph);
        card.querySelector('[data-meta]').innerHTML =
          d.w + ' × ' + d.h + ' ／ ' + d.human + '<br>更新 ' + stamp(d.mtime);

        // 次の差し替えにそなえて、選んだものは外しておく
        form.querySelector('input[type=file]').value = '';
        var from = form.querySelector('[data-pick-value]');
        if (from) from.value = '';
        var view = form.querySelector('[data-pick-view]');
        if (view) view.innerHTML = '';
      })
      .catch(function () {
        show(said, 'ng', '送れませんでした。通信の状態をご確認のうえ、もう一度お試しください。');
      })
      .then(function () { btn.disabled = false; btn.textContent = was; });
  });
});

function show(el, type, msg) {
  el.className = 'said ' + type;
  el.textContent = msg;
  el.hidden = false;
}

function stamp(sec) {
  var d = new Date(sec * 1000), z = function (n) { return ('0' + n).slice(-2); };
  return d.getFullYear() + '/' + z(d.getMonth() + 1) + '/' + z(d.getDate())
    + ' ' + z(d.getHours()) + ':' + z(d.getMinutes());
}

// 上の欄で、すべての枠の「保存する大きさ」をまとめて変える
var all = document.querySelector('[data-size-all]');
if (all) {
  all.addEventListener('change', function () {
    document.querySelectorAll('form[data-save] select[name=size]').forEach(function (sel) {
      sel.value = all.value;
    });
  });
}
</script>
</body>
</html>
