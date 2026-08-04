<?php
/*
 * 施工例の登録 — 管理画面
 * ------------------------------------------------------------------
 * 登録・編集すると、その場で works.html と各サービスページを書き出します。
 * お客様が見るページは静的な HTML のままなので、この画面を使っていない
 * ときに PHP が動くことはありません。
 *
 * 設置とパスワードの手順は同じフォルダーの README.md をご覧ください。
 */

require __DIR__ . '/library.php';
session_start();

define('ADD_ROWS', 4);          // 一度に選べる写真の欄の数

function flash($type, $msg)
{
    $_SESSION['wflash'] = array('type' => $type, 'msg' => $msg);
}

function go($to = 'works.php')
{
    header('Location: ' . $to);
    exit;
}

/** JavaScript へ返事を返して終わる */
function json_out($data)
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 入力された文字を整える（制御文字と前後の空白を落とし、長さを切る） */
function clean_text($s, $max)
{
    $s = isset($s) ? (string)$s : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim($s);
    if (mb_strlen($s, 'UTF-8') > $max) $s = mb_substr($s, 0, $max, 'UTF-8');
    return $s;
}

/**
 * 管理用メモ用。clean_text と違って改行は残す
 * （お名前・施工日・現場のことを行ごとに書けるように）
 */
function clean_memo($s, $max)
{
    $s = isset($s) ? (string)$s : '';
    $s = str_replace(array("\r\n", "\r"), "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    $s = trim($s);
    if (mb_strlen($s, 'UTF-8') > $max) $s = mb_substr($s, 0, $max, 'UTF-8');
    return $s;
}

/** アップロードに失敗した理由を、そのまま読める言葉にする */
function upload_error_text($err)
{
    switch ($err) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return '写真が大きすぎます（1枚あたりの上限 ' . ini_get('upload_max_filesize') . '）。'
                . 'PNG は同じ写真でも JPEG の3〜8倍の容量になります。'
                . 'JPEG で保存し直すか、kanri/.user.ini で上限を上げてください。';
        case UPLOAD_ERR_PARTIAL:
            return '通信が途中で切れました。もう一度お試しください。';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'サーバーに一時保存できませんでした。時間をおいてお試しください。';
        case UPLOAD_ERR_EXTENSION:
            return 'サーバー側で受け取りが止められました。';
    }
    return 'アップロードに失敗しました（コード ' . $err . '）。';
}

/**
 * 写真の欄ひとつぶんの出どころを返す。
 *   ・手元のパソコンから選ばれていれば、その一時ファイル
 *   ・サーバーの写真から選ばれていれば、その場所
 *   ・どちらも無ければ null（その欄は使わなかったということ）
 * サーバーの写真は library_path() を通すので、決めた場所の中のものしか使えない。
 */
function row_source($i, &$errs)
{
    if (isset($_FILES['photo']['error'][$i])) {
        $err = $_FILES['photo']['error'][$i];
        if ($err === UPLOAD_ERR_OK) return $_FILES['photo']['tmp_name'][$i];
        if ($err !== UPLOAD_ERR_NO_FILE) {
            $errs[] = '写真' . ($i + 1) . '：' . upload_error_text($err);
            return null;
        }
    }

    $pick = isset($_POST['pick'][$i]) ? trim($_POST['pick'][$i]) : '';
    if ($pick === '') return null;

    $path = library_path($pick);
    if ($path === null) {
        $errs[] = '写真' . ($i + 1) . '：選ばれた写真が見つかりませんでした。';
        return null;
    }
    return $path;
}

/** 書き出した結果を、そのまま従業員に見せる文にする */
function rebuild_message($items)
{
    $r = works_regenerate($items);
    if (count($r['ng'])) {
        return array('ng', 'ページの書き出しに失敗しました（' . implode('・', $r['ng'])
            . '）。ファイルの権限が 644 になっているかご確認ください。');
    }
    return array('ok', 'ページを書き出しました（' . implode('・', $r['ok']) . '）。');
}


/* ============================================================
 * 受け取って保存する
 * ========================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 受信上限を超えると PHP は $_POST も $_FILES も捨てるので、先に分かりやすく返す
    if (!count($_POST) && !count($_FILES) && !empty($_SERVER['CONTENT_LENGTH'])) {
        flash('ng', '写真の合計が大きすぎます（一度に送れる上限 ' . ini_get('post_max_size')
            . '）。枚数を減らすか、1枚ずつお試しください。'
            . 'PNG は容量が大きいので、JPEG で保存し直すのも有効です。');
        go();
    }

    $do    = isset($_POST['do']) ? $_POST['do'] : '';
    $items = works_load();

    /* --- 新しく登録する --- */
    if ($do === 'add') {
        $cat = isset($_POST['cat']) ? $_POST['cat'] : '';
        if (!isset($WORK_CATS[$cat])) { flash('ng', '分類が選ばれていません。'); go(); }

        $id = works_new_id($items);
        $photos = array();
        $errs = array();

        for ($i = 0; $i < ADD_ROWS; $i++) {
            $tmp = row_source($i, $errs);
            if ($tmp === null) continue;

            $r = works_store_photo($tmp, $id, count($photos) + 1);
            if (strpos($r, 'works/photos/') !== 0) {
                $errs[] = '写真' . ($i + 1) . '：' . $r;
                continue;
            }
            $photos[] = array(
                'src' => $r,
                'cap' => clean_text(isset($_POST['cap'][$i]) ? $_POST['cap'][$i] : '', 20),
            );
        }

        if (!count($photos)) {
            flash('ng', '写真が1枚も保存できませんでした。' . (count($errs) ? implode(' / ', $errs) : '写真をお選びください。'));
            go();
        }

        $items[] = array(
            'id'      => $id,
            'cat'     => $cat,
            'stone'   => clean_text(isset($_POST['stone']) ? $_POST['stone'] : '', 40),
            'note'    => clean_text(isset($_POST['note']) ? $_POST['note'] : '', 200),
            // 管理用メモ。ホームページには一切出さない（works_lib.php の
            // 書き出しは cat / stone / note / photos しか見ていない）
            'memo'    => clean_memo(isset($_POST['memo']) ? $_POST['memo'] : '', 400),
            'pub'     => !empty($_POST['pub']),
            'photos'  => $photos,
            'created' => date('Y-m-d H:i:s'),
        );

        if (!works_save($items)) { flash('ng', '登録できませんでした。works フォルダーの権限をご確認ください。'); go(); }
        list($t, $m) = rebuild_message($items);
        $state = empty($_POST['pub'])
            ? '非公開で登録しました（ホームページにはまだ出ていません）。'
            : '施工例を登録し、公開しました。';
        flash($t === 'ok' ? 'ok' : 'ng',
            $state . '写真 ' . count($photos) . '枚。' . $m . (count($errs) ? ' ※ ' . implode(' / ', $errs) : ''));
        go();
    }

    /* --- 内容を書き換える --- */
    if ($do === 'update') {
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        list($idx, $it) = works_find($items, $id);
        if ($idx < 0) { flash('ng', 'その施工例は見つかりませんでした。'); go(); }

        $cat = isset($_POST['cat']) ? $_POST['cat'] : '';
        if (isset($WORK_CATS[$cat])) $it['cat'] = $cat;
        $it['stone'] = clean_text(isset($_POST['stone']) ? $_POST['stone'] : '', 40);
        $it['note']  = clean_text(isset($_POST['note']) ? $_POST['note'] : '', 200);
        $it['memo']  = clean_memo(isset($_POST['memo']) ? $_POST['memo'] : '', 400);
        $it['pub']   = !empty($_POST['pub']);

        // いまある写真の説明を書き換える
        $ps = works_photos($it);
        foreach ($ps as $n => $p) {
            if (isset($_POST['pcap'][$n])) $ps[$n]['cap'] = clean_text($_POST['pcap'][$n], 20);
        }

        // 追加された写真
        $errs = array();
        for ($i = 0; $i < ADD_ROWS; $i++) {
            $tmp = row_source($i, $errs);
            if ($tmp === null) continue;

            $r = works_store_photo($tmp, $id, count($ps) + 1);
            if (strpos($r, 'works/photos/') !== 0) { $errs[] = $r; continue; }
            $ps[] = array('src' => $r, 'cap' => clean_text(isset($_POST['cap'][$i]) ? $_POST['cap'][$i] : '', 20));
        }

        $it['photos'] = $ps;
        $items[$idx] = $it;

        if (!works_save($items)) { flash('ng', '保存できませんでした。'); go(); }
        list($t, $m) = rebuild_message($items);
        flash($t === 'ok' ? 'ok' : 'ng', '書き換えました。' . $m . (count($errs) ? ' ※ ' . implode(' / ', $errs) : ''));
        // 写真を足したあとに順番を直したくなることが多いので、この画面に留まる
        go('works.php?edit=' . rawurlencode($id));
    }

    /* --- 写真を1枚消す --- */
    if ($do === 'photo_del') {
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        $n  = isset($_POST['n']) ? (int)$_POST['n'] : -1;
        list($idx, $it) = works_find($items, $id);
        if ($idx < 0) { flash('ng', 'その施工例は見つかりませんでした。'); go(); }

        $ps = works_photos($it);
        if (!isset($ps[$n])) { flash('ng', 'その写真は見つかりませんでした。'); go(); }
        if (count($ps) <= 1) { flash('ng', '写真が1枚もない施工例にはできません。施工例ごと削除してください。'); go('works.php?edit=' . rawurlencode($id)); }

        works_delete_photo($ps[$n]['src']);
        array_splice($ps, $n, 1);
        $it['photos'] = $ps;
        $items[$idx] = $it;

        works_save($items);
        rebuild_message($items);
        flash('ok', '写真を1枚削除しました。');
        go('works.php?edit=' . rawurlencode($id));
    }

    /* --- 写真の順番を入れ替える --- */
    if ($do === 'photo_move') {
        $id  = isset($_POST['id']) ? $_POST['id'] : '';
        $n   = isset($_POST['n']) ? (int)$_POST['n'] : -1;
        $dir = (isset($_POST['dir']) && $_POST['dir'] === 'down') ? 1 : -1;
        list($idx, $it) = works_find($items, $id);
        if ($idx < 0) { flash('ng', 'その施工例は見つかりませんでした。'); go(); }

        $ps = works_photos($it);
        $m = $n + $dir;
        if (isset($ps[$n]) && isset($ps[$m])) {
            $t = $ps[$n]; $ps[$n] = $ps[$m]; $ps[$m] = $t;
            $it['photos'] = $ps;
            $items[$idx] = $it;
            works_save($items);
            rebuild_message($items);
            flash('ok', '写真の順番を入れ替えました。1枚目が一覧に出る写真になります。');
        }
        go('works.php?edit=' . rawurlencode($id));
    }

    /* --- 公開と非公開を切り替える ---
     *
     * JavaScript から呼ばれたときは、画面を読み込み直さずに返事だけ返す。
     * 一覧の途中で押しても、見ている位置が動かないようにするため。
     * JavaScript が無い場合は、今までどおり読み込み直す。 */
    if ($do === 'toggle') {
        $ajax = !empty($_POST['ajax']);
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        list($idx, $it) = works_find($items, $id);

        if ($idx < 0) {
            if ($ajax) json_out(array('ok' => false, 'msg' => 'その施工例は見つかりませんでした。'));
            flash('ng', 'その施工例は見つかりませんでした。'); go();
        }

        $it['pub'] = !works_is_public($it);
        $items[$idx] = $it;

        if (!works_save($items)) {
            if ($ajax) json_out(array('ok' => false, 'msg' => '切り替えできませんでした。'));
            flash('ng', '切り替えできませんでした。'); go();
        }

        list($t, $m) = rebuild_message($items);
        $msg = ($it['pub'] ? 'ホームページに掲載しました。' : 'ホームページから外しました。データは残っています。');
        if ($t !== 'ok') $msg = $m;          // うまくいかなかったときだけ、詳しく出す

        if ($ajax) {
            $pub = 0;
            foreach ($items as $x) if (works_is_public($x)) $pub++;
            json_out(array(
                'ok'    => $t === 'ok',
                'pub'   => (bool)$it['pub'],
                'msg'   => $msg,
                'total' => count($items),
                'open'  => $pub,
            ));
        }

        flash($t === 'ok' ? 'ok' : 'ng', $msg . ($t === 'ok' ? $m : ''));
        go();
    }

    /* --- 施工例ごと消す --- */
    if ($do === 'delete') {
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        list($idx, $it) = works_find($items, $id);
        if ($idx < 0) { flash('ng', 'その施工例は見つかりませんでした。'); go(); }

        foreach (works_photos($it) as $p) works_delete_photo($p['src']);
        array_splice($items, $idx, 1);

        if (!works_save($items)) { flash('ng', '削除できませんでした。'); go(); }
        list($t, $m) = rebuild_message($items);
        flash($t === 'ok' ? 'ok' : 'ng', '施工例を削除しました。' . $m);
        go();
    }

    /* --- ページだけ作り直す --- */
    if ($do === 'rebuild') {
        list($t, $m) = rebuild_message($items);
        flash($t, $m);
        go();
    }

    flash('ng', '不明な操作です。');
    go();
}


/* ============================================================
 * 画面を組み立てる
 * ========================================================== */

$items = works_load();
$flash = isset($_SESSION['wflash']) ? $_SESSION['wflash'] : null;
unset($_SESSION['wflash']);

$editId = isset($_GET['edit']) ? $_GET['edit'] : '';
list($editIdx, $edit) = $editId !== '' ? works_find($items, $editId) : array(-1, null);

$counts = array();
$pubCount = 0;
foreach ($items as $it) {
    $c = isset($it['cat']) ? $it['cat'] : '';
    $counts[$c] = (isset($counts[$c]) ? $counts[$c] : 0) + 1;
    if (works_is_public($it)) $pubCount++;
}

/** 写真の欄（新規・追加で共通） */
function photo_rows($caps)
{
    $o = '';
    for ($i = 0; $i < ADD_ROWS; $i++) {
        $o .= '<div class="field">';
        $o .= '<label>写真' . ($i + 1) . ($i === 0 ? '（1枚目が一覧に出ます）' : '') . '</label>';
        $o .= '<input type="file" name="photo[]" accept="image/jpeg,image/png,image/webp">';
        $o .= '<button type="button" class="ghost mini" data-pick="pick' . $i . '" style="margin-top:7px;align-self:flex-start">'
            . 'サーバーの写真から選ぶ</button>';
        $o .= '<input type="hidden" name="pick[' . $i . ']" data-pick-value>';
        $o .= '<div data-pick-view></div>';
        $o .= '<input type="text" name="cap[' . $i . ']" maxlength="20" placeholder="写真の説明（例：施工前）" style="margin-top:7px">';
        $o .= '<div class="caps">';
        foreach ($caps as $c) {
            $o .= '<button type="button" class="mini" data-fill="' . h($c) . '">' . h($c) . '</button>';
        }
        $o .= '</div></div>';
    }
    return $o;
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>施工例の登録 — 庄司石材店</title>
<link rel="stylesheet" href="admin.css">
</head>
<body>

<header class="top">
  <div class="wrap">
    <h1>施工例の登録</h1>
    <p class="sub">庄司石材店ホームページ</p>
    <p class="count">
      登録済み <b><?= count($items) ?></b> 件　／　
      ホームページに掲載中 <b data-count-open><?= $pubCount ?></b>　非公開 <b data-count-off><?= count($items) - $pubCount ?></b>
    </p>
    <nav class="tabs">
      <a href="index.php">写真の入れ替え</a>
      <a class="on" href="works.php">施工例の登録</a>
      <a class="go" href="../index.html" target="_blank" rel="noopener">サイトを見る ↗</a>
      <a class="go" href="../works.html" target="_blank" rel="noopener">施工例のページ ↗</a>
    </nav>
  </div>
</header>

<div class="wrap">

<?php if (!is_protected()): ?>
  <div class="warn">
    <b>この画面にパスワードがかかっていません。</b>
    さくらのコントロールパネルの「アクセス制限」で <code>kanri</code> フォルダーに
    ユーザー名とパスワードを設定してください。設定するまで、URLを知っている人なら誰でも登録・削除できます。
  </div>
<?php endif; ?>

<?php if ($flash): ?>
  <div class="flash <?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>


<?php if ($edit): /* ============ 書き換えの画面 ============ */ ?>

  <h2>施工例を書き換える</h2>
  <div class="panel">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="do" value="update">
      <input type="hidden" name="id" value="<?= h($edit['id']) ?>">

      <div class="row2">
        <div class="field">
          <label>分類</label>
          <select name="cat">
            <?php foreach ($WORK_CATS as $k => $label): ?>
              <option value="<?= h($k) ?>"<?= (isset($edit['cat']) && $edit['cat'] === $k) ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>石種</label>
          <input type="text" name="stone" maxlength="40" value="<?= h(isset($edit['stone']) ? $edit['stone'] : '') ?>" placeholder="例：庵治石">
          <p class="hint"><b>施工例の一覧</b>で、写真のすぐ下に出ます。空のままでも構いません。</p>
        </div>
      </div>

      <div class="field">
        <label>一言</label>
        <textarea name="note" rows="3" maxlength="200" placeholder="例：黒ずみと苔を落とし、文字の色を入れ直しました。"><?= h(isset($edit['note']) ? $edit['note'] : '') ?></textarea>
        <p class="hint"><b>施工例の一覧</b>で石種の下に出ます。写真を大きく表示したときにも出ます。</p>
      </div>


      <div class="field field--pub">
        <label class="sw">
          <input type="checkbox" name="pub" value="1"<?= works_is_public($edit) ? ' checked' : '' ?>>
          <span class="sw__box" aria-hidden="true"></span>
          <span class="sw__txt">ホームページに掲載する</span>
        </label>
        <p class="hint">外すと、この施工例はホームページから消えます。データと写真はこの画面に残ります。</p>
      </div>
      <div class="field field--memo">
        <label>管理用メモ　<span class="off">サイトには出ません</span></label>
        <textarea name="memo" rows="4" maxlength="400" placeholder="例：山田様&#10;2026年7月20日施工&#10;○○霊園 3区12番"><?= h(isset($edit['memo']) ? $edit['memo'] : '') ?></textarea>
        <p class="hint">お施主様のお名前、施工日、霊園名など。<b>ホームページには表示されません。</b>この画面でだけ見えます。</p>
      </div>

      <div class="field">
        <label>いまの写真</label>
        <div class="shots">
          <?php foreach (works_photos($edit) as $n => $p): ?>
            <div class="shot">
              <div class="box"><img src="../<?= h($p['src']) ?>" alt=""></div>
              <input type="text" name="pcap[<?= $n ?>]" maxlength="20" value="<?= h($p['cap']) ?>" placeholder="説明" style="margin-top:5px;font-size:12px;padding:5px 7px">
            </div>
          <?php endforeach; ?>
        </div>
        <p class="hint">説明を直したら、下の「この内容で保存する」を押してください。</p>
      </div>

      <div class="field">
        <label>写真を足す</label>
        <?= photo_rows($WORK_CAPS) ?>
      </div>

      <button type="submit">この内容で保存する</button>
    </form>

    <!-- 並べ替えと削除は、書き換えとは別の操作にしてある
         （入力途中の内容が消えないように） -->
    <div class="field" style="margin-top:26px;padding-top:20px;border-top:1px solid var(--rule)">
      <label>写真の順番・削除</label>
      <div class="shots">
        <?php $ps = works_photos($edit); foreach ($ps as $n => $p): ?>
          <div class="shot">
            <div class="box"><img src="../<?= h($p['src']) ?>" alt=""></div>
            <div class="cap"><?= $p['cap'] !== '' ? h($p['cap']) : '（説明なし）' ?></div>
            <div class="tools">
              <form method="post" style="margin:0">
                <input type="hidden" name="do" value="photo_move">
                <input type="hidden" name="id" value="<?= h($edit['id']) ?>">
                <input type="hidden" name="n" value="<?= $n ?>">
                <input type="hidden" name="dir" value="up">
                <button type="submit" class="mini ghost"<?= $n === 0 ? ' disabled' : '' ?>>←</button>
              </form>
              <form method="post" style="margin:0">
                <input type="hidden" name="do" value="photo_move">
                <input type="hidden" name="id" value="<?= h($edit['id']) ?>">
                <input type="hidden" name="n" value="<?= $n ?>">
                <input type="hidden" name="dir" value="down">
                <button type="submit" class="mini ghost"<?= $n === count($ps) - 1 ? ' disabled' : '' ?>>→</button>
              </form>
              <form method="post" style="margin:0" onsubmit="return confirm('この写真を削除します。よろしいですか。')">
                <input type="hidden" name="do" value="photo_del">
                <input type="hidden" name="id" value="<?= h($edit['id']) ?>">
                <input type="hidden" name="n" value="<?= $n ?>">
                <button type="submit" class="mini danger">削除</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <p style="margin:22px 0 0"><a href="works.php">← 一覧に戻る</a></p>
  </div>

<?php else: /* ============ 新しく登録する画面 ============ */ ?>

  <h2>新しく登録する</h2>
  <div class="panel">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="do" value="add">

      <div class="row2">
        <div class="field">
          <label>分類</label>
          <select name="cat" required>
            <?php foreach ($WORK_CATS as $k => $label): ?>
              <option value="<?= h($k) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>石種</label>
          <input type="text" name="stone" maxlength="40" placeholder="例：庵治石">
          <p class="hint"><b>施工例の一覧</b>で、写真のすぐ下に出ます。空のままでも構いません。</p>
        </div>
      </div>

      <div class="field">
        <label>一言</label>
        <textarea name="note" rows="3" maxlength="200" placeholder="例：黒ずみと苔を落とし、文字の色を入れ直しました。"></textarea>
        <p class="hint"><b>施工例の一覧</b>で石種の下に出ます。写真を大きく表示したときにも出ます。</p>
      </div>


      <div class="field field--pub">
        <label class="sw">
          <input type="checkbox" name="pub" value="1" checked>
          <span class="sw__box" aria-hidden="true"></span>
          <span class="sw__txt">ホームページに掲載する</span>
        </label>
        <p class="hint">チェックを外して登録すると、あとから一覧の「掲載する」で公開できます。
          先に内容を確かめてから出したいときにお使いください。</p>
      </div>
      <div class="field field--memo">
        <label>管理用メモ　<span class="off">サイトには出ません</span></label>
        <textarea name="memo" rows="4" maxlength="400" placeholder="例：山田様&#10;2026年7月20日施工&#10;○○霊園 3区12番"></textarea>
        <p class="hint">お施主様のお名前、施工日、霊園名など。<b>ホームページには表示されません。</b>この画面でだけ見えます。</p>
      </div>

      <?= photo_rows($WORK_CAPS) ?>

      <button type="submit">登録する</button>
      <p class="hint" style="margin-top:10px">
        「ホームページに掲載する」を入れたまま登録すると、その場で施工例の一覧ページと
        各サービスページに反映されます。外してあれば、登録だけしてホームページには出ません。
      </p>
    </form>
  </div>

<?php endif; ?>


<h2>登録済みの施工例（<?= count($items) ?>件）</h2>

<?php if (!count($items)): ?>
  <p class="empty">まだ1件も登録されていません。上の欄から登録してください。</p>
<?php else: ?>
  <div class="wlist">
    <?php foreach ($items as $it): $ps = works_photos($it); ?>
      <div class="witem<?= works_is_public($it) ? '' : ' is-off' ?>" data-item="<?= h($it['id']) ?>">
        <div>
          <span class="cat"><?= h(isset($WORK_CATS[$it['cat']]) ? $WORK_CATS[$it['cat']] : $it['cat']) ?></span>
          <span class="state<?= works_is_public($it) ? ' on' : '' ?>" data-state>
            <?= works_is_public($it) ? '掲載中' : '非公開' ?>
          </span>
          <div class="head"><?= h(works_heading($it)) ?></div>
          <?php if (!empty($it['note'])): ?><p class="note"><?= h($it['note']) ?></p><?php endif; ?>
          <?php if (!empty($it['memo'])): ?>
            <div class="memo"><span class="off">管理用メモ</span><?= nl2br(h($it['memo'])) ?></div>
          <?php endif; ?>
          <div class="shots">
            <?php foreach ($ps as $p): ?>
              <div class="shot" style="width:104px">
                <div class="box"><img src="../<?= h($p['src']) ?>" alt="" loading="lazy"></div>
                <?php if ($p['cap'] !== ''): ?><div class="cap"><?= h($p['cap']) ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <p class="meta" style="margin-top:10px">
            写真 <?= count($ps) ?>枚　／　登録 <?= h(isset($it['created']) ? $it['created'] : '') ?>
          </p>
        </div>
        <div class="acts">
          <form method="post" style="margin:0" data-toggle>
            <input type="hidden" name="do" value="toggle">
            <input type="hidden" name="id" value="<?= h($it['id']) ?>">
            <button type="submit" class="<?= works_is_public($it) ? 'ghost' : '' ?>" data-toggle-btn>
              <?= works_is_public($it) ? '掲載をやめる' : '掲載する' ?>
            </button>
          </form>
          <a class="btnlink" href="works.php?edit=<?= rawurlencode($it['id']) ?>">書き換える</a>
          <form method="post" style="margin:0" onsubmit="return confirm('この施工例を削除します。写真も一緒に消えます。よろしいですか。')">
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= h($it['id']) ?>">
            <button type="submit" class="danger">削除</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>


<div class="note">
  <b>ページを作り直す</b><br>
  ふだんは登録・書き換えのたびに自動で書き出されるので、押す必要はありません。<br>
  ホームページのデザインを更新した直後だけ、一度押してください。デザインの更新でページを
  丸ごと入れ替えると、施工例の部分が古い状態に戻ることがあります。
  <form method="post" style="margin-top:14px">
    <input type="hidden" name="do" value="rebuild">
    <button type="submit" class="ghost">ページを作り直す</button>
  </form>
</div>

<footer>
  <p>
    写真は自動で回転を直し、幅 <?= MAX_WIDTH ?>px まで縮めてから保存します。<br>
    枠の形に合わせて表示時に切り取られるので、被写体は中央寄りに、少し引いて撮ってください。<br>
    お施主様のお名前が写り込んでいないか、登録の前にご確認ください。
  </p>
  <p><a href="../works.html">施工例のページを見る →</a></p>
</footer>

</div>

<script src="picker.js" defer></script>
<script>
// 掲載する／やめる — 画面を読み込み直さずに切り替える。
// 一覧の途中で押しても、見ている位置がそのままになるように。
// JavaScript が動かない環境では、ふつうに送信されて読み込み直される。
document.querySelectorAll('form[data-toggle]').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var item = form.closest('.witem');
    var btn  = form.querySelector('[data-toggle-btn]');
    var tag  = item.querySelector('[data-state]');
    if (btn.disabled) return;
    btn.disabled = true;

    var body = new FormData(form);
    body.append('ajax', '1');

    fetch('works.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.msg === undefined) throw new Error('返事がありません');
        item.classList.toggle('is-off', !d.pub);
        tag.textContent = d.pub ? '掲載中' : '非公開';
        tag.classList.toggle('on', d.pub);
        btn.textContent = d.pub ? '掲載をやめる' : '掲載する';
        btn.classList.toggle('ghost', d.pub);

        var open = document.querySelector('[data-count-open]');
        var off  = document.querySelector('[data-count-off]');
        if (open) open.textContent = d.open;
        if (off)  off.textContent = d.total - d.open;

        say(item, d.ok ? 'ok' : 'ng', d.msg);
      })
      .catch(function () {
        say(item, 'ng', '切り替えできませんでした。通信の状態をご確認のうえ、画面を読み込み直してください。');
      })
      .then(function () { btn.disabled = false; });
  });
});

// 押した施工例のすぐそばに、短く結果を出す
function say(item, type, msg) {
  var old = item.querySelector('.said');
  if (old) old.remove();
  var el = document.createElement('p');
  el.className = 'said ' + type;
  el.textContent = msg;
  item.appendChild(el);
  clearTimeout(say.timer);
  say.timer = setTimeout(function () { el.remove(); }, 6000);
}

// 「施工前」などのボタンを押したら、すぐ上の入力欄に入れる
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-fill]');
  if (!b) return;
  var box = b.closest('.field');
  var input = box && box.querySelector('input[type=text]');
  if (input) { input.value = b.dataset.fill; input.focus(); }
});
</script>

</body>
</html>
