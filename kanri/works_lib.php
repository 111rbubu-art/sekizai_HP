<?php
/*
 * 施工例の登録 — 共通処理
 * ------------------------------------------------------------------
 * 施工例のデータは works/works.json に持つ。
 * 登録・編集のたびに、そこから works.html と各サービスページの
 * 「施工例」の部分を HTML として書き出す。
 *
 * つまり ——
 *   お客様が見るページは、今までどおり静的な HTML のまま。
 *   PHP が動くのは、従業員が登録操作をしている数秒間だけ。
 *
 * 書き出す場所は、HTML 内の目印ではさんである。
 *   <!-- WORKS:START cleaning -->  …ここを入れ替える…  <!-- WORKS:END -->
 */

require_once __DIR__ . '/lib.php';

define('WORKS_DIR',   SITE_DIR . '/works');
define('WORKS_JSON',  WORKS_DIR . '/works.json');
define('WORKS_PHOTO', WORKS_DIR . '/photos');

define('WORKS_PER_PAGE', 24);      // works.html の1ページあたりの件数
define('WORKS_ON_SERVICE', 4);     // 各サービスページに出す件数

// 分類。ここに無い分類は登録できない
$WORK_CATS = array(
    'boseki'   => 'お墓 新規',
    'cleaning' => 'クリーニング',
    'jishin'   => '地震対策',
    'coating'  => '石材コーティング',
    'reform'   => 'リフォーム',
);

// 分類 => 書き出し先のサービスページ（目印が無いページは黙って飛ばす）
$WORK_PAGES = array(
    'boseki'   => 'boseki.html',
    'cleaning' => 'cleaning.html',
    'jishin'   => 'jishin.html',
    'coating'  => 'coating.html',
    'reform'   => 'reform.html',
);

// 写真につける説明のよく使う候補（入力欄の横に出す）
$WORK_CAPS = array('施工前', '施工後', '全体', '棹石', '外柵', '文字', '施工中');


/* ============================================================
 * データの読み書き
 * ========================================================== */

/** works.json を読む。無ければ空で返す */
function works_load()
{
    if (!is_file(WORKS_JSON)) return array();
    $raw = file_get_contents(WORKS_JSON);
    if ($raw === false || $raw === '') return array();
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) return array();
    return $data['items'];
}

/**
 * works.json を書く。
 * 途中で落ちても壊れないよう、別名で書いてから差し替える。
 *
 * $items は参照で受け取る。並べ替えた結果を呼び出し側にも返したいため
 * （そうしないと、保存したあとのページ書き出しが古い並び順になる）。
 */
function works_save(&$items)
{
    if (!is_dir(WORKS_DIR)) @mkdir(WORKS_DIR, 0755, true);

    // 新しい順に並べ直しておく（表示のたびに並べ替えなくて済むように）
    // 登録日時が空のもの（最初から入っていた分）は、いちばん後ろに残す
    usort($items, function ($a, $b) {
        $ca = isset($a['created']) ? $a['created'] : '';
        $cb = isset($b['created']) ? $b['created'] : '';
        if ($ca === $cb) return 0;
        if ($ca === '') return 1;
        if ($cb === '') return -1;
        return $ca < $cb ? 1 : -1;
    });
    $items = array_values($items);

    $json = json_encode(
        array('version' => 1, 'items' => array_values($items)),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    if ($json === false) return false;

    $tmp = WORKS_JSON . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, WORKS_JSON)) { @unlink($tmp); return false; }
    @chmod(WORKS_JSON, 0644);
    return true;
}

/** 施工例をひとつ取り出す */
function works_find($items, $id)
{
    foreach ($items as $i => $it) {
        if (isset($it['id']) && $it['id'] === $id) return array($i, $it);
    }
    return array(-1, null);
}

/** 重ならない ID を作る（登録日時 + 連番） */
function works_new_id($items)
{
    $base = date('Ymd-His');
    $id = $base;
    $n = 1;
    $used = array();
    foreach ($items as $it) if (isset($it['id'])) $used[$it['id']] = true;
    while (isset($used[$id])) $id = $base . '-' . (++$n);
    return $id;
}


/* ============================================================
 * 表示用の細かい整形
 * ========================================================== */

/** カードの見出し。石種があればそれ、無ければ一言の先頭、それも無ければ分類名 */
function works_heading($it)
{
    global $WORK_CATS;
    $stone = isset($it['stone']) ? trim($it['stone']) : '';
    if ($stone !== '') return $stone;
    $note = isset($it['note']) ? trim($it['note']) : '';
    if ($note !== '') return mb_strimwidth($note, 0, 28, '…', 'UTF-8');
    $cat = isset($it['cat']) ? $it['cat'] : '';
    return isset($WORK_CATS[$cat]) ? $WORK_CATS[$cat] : '施工例';
}

/** 写真の alt。読み上げと、写真が出ないときの代わりの文になる */
function works_alt($it, $photo, $n)
{
    global $WORK_CATS;
    // 最初から入っている写真は、手で書いた説明を持っているのでそれを使う
    if (isset($photo['alt']) && trim($photo['alt']) !== '') return trim($photo['alt']);
    $cat = isset($it['cat']) ? $it['cat'] : '';
    $parts = array(isset($WORK_CATS[$cat]) ? $WORK_CATS[$cat] : '施工例');
    $stone = isset($it['stone']) ? trim($it['stone']) : '';
    if ($stone !== '') $parts[] = $stone;
    $cap = isset($photo['cap']) ? trim($photo['cap']) : '';
    if ($cap !== '') $parts[] = $cap;
    elseif ($n > 0) $parts[] = ($n + 1) . '枚目';
    return implode('　', $parts) . 'の施工例';
}

/** 写真の配列を必ず取り出す（写真ゼロの施工例も許す） */
function works_photos($it)
{
    if (!isset($it['photos']) || !is_array($it['photos'])) return array();
    $out = array();
    foreach ($it['photos'] as $p) {
        if (is_string($p)) $p = array('src' => $p, 'cap' => '');
        if (!isset($p['src']) || $p['src'] === '') continue;
        if (!isset($p['cap'])) $p['cap'] = '';
        if (!isset($p['alt'])) $p['alt'] = '';
        $out[] = $p;
    }
    return $out;
}


/* ============================================================
 * HTML の書き出し
 * ========================================================== */

/**
 * 写真の一覧を JSON にして持たせる。拡大表示のときに読む。
 * </script> が中に出ると HTML が壊れるので、そこだけ逃がしておく。
 */
function works_photo_json($it)
{
    $data = array();
    foreach (works_photos($it) as $n => $p) {
        $data[] = array(
            'src' => $p['src'],
            'cap' => $p['cap'],
            'alt' => works_alt($it, $p, $n),
        );
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return str_replace('</', '<\/', $json);
}

/** works.html に並べるカード1枚 */
function works_card_html($it)
{
    global $WORK_CATS;

    $cat   = isset($it['cat']) ? $it['cat'] : '';
    $label = isset($WORK_CATS[$cat]) ? $WORK_CATS[$cat] : '施工例';
    $head  = works_heading($it);
    $note  = isset($it['note']) ? trim($it['note']) : '';
    $ps    = works_photos($it);
    $first = count($ps) ? $ps[0] : null;

    $o  = '        <figure class="wcard" data-cat="' . h($cat) . '">' . "\n";
    $o .= '          <div class="wcard__media">' . "\n";
    $o .= '            <div class="work__placeholder" aria-hidden="true"></div>' . "\n";
    if ($first) {
        $o .= '            <img class="wcard__img" src="' . h($first['src']) . '" alt="' . h(works_alt($it, $first, 0))
            . '" loading="lazy" onerror="this.style.display=\'none\'" />' . "\n";
    }
    if (count($ps) > 1) {
        $o .= '            <span class="wcard__count">' . count($ps) . '枚</span>' . "\n";
    }
    $o .= '          </div>' . "\n";
    $o .= '          <figcaption><span>' . h($head) . '</span><span class="c">' . h($label) . '</span></figcaption>' . "\n";
    if ($note !== '' && $note !== $head) {
        $o .= '          <p class="wcard__note">' . h($note) . '</p>' . "\n";
    }
    if (count($ps)) {
        $o .= '          <button class="wcard__open" type="button" aria-label="' . h($head) . 'の写真を大きく見る"></button>' . "\n";
        $o .= '          <script type="application/json" class="wcard__data">' . works_photo_json($it) . '</script>' . "\n";
    }
    $o .= '        </figure>' . "\n";
    return $o;
}

/** 各サービスページに並べる1枚 */
function works_item_html($it, $num)
{
    $head = works_heading($it);
    $ps   = works_photos($it);
    $first = count($ps) ? $ps[0] : null;

    $o  = '      <div class="work">' . "\n";
    $o .= '        <div class="work__placeholder" aria-hidden="true"></div>' . "\n";
    if ($first) {
        $o .= '        <img class="work__img" src="' . h($first['src']) . '" alt="' . h(works_alt($it, $first, 0))
            . '" loading="lazy" onerror="this.style.display=\'none\'" />' . "\n";
    }
    if (count($ps) > 1) {
        $o .= '        <span class="wcard__count">' . count($ps) . '枚</span>' . "\n";
    }
    $o .= '        <div class="work__caption"><span class="mincho">' . h($head) . '</span><span class="num">'
        . sprintf('%02d', $num) . '</span></div>' . "\n";
    if (count($ps)) {
        $o .= '        <button class="wcard__open" type="button" aria-label="' . h($head) . 'の写真を大きく見る"></button>' . "\n";
        $o .= '        <script type="application/json" class="wcard__data">' . works_photo_json($it) . '</script>' . "\n";
    }
    $o .= '      </div>' . "\n";
    return $o;
}

/** 絞り込みボタン。1件も無い分類はボタンを出さない */
function works_filter_html($items)
{
    global $WORK_CATS;

    $has = array();
    foreach ($items as $it) {
        if (isset($it['cat'])) $has[$it['cat']] = true;
    }

    $o = '        <button type="button" class="wfilter__btn" data-works-filter="all">すべて</button>' . "\n";
    foreach ($WORK_CATS as $key => $label) {
        if (!isset($has[$key])) continue;
        $o .= '        <button type="button" class="wfilter__btn" data-works-filter="' . h($key) . '">'
            . h($label) . '</button>' . "\n";
    }
    return $o;
}

/**
 * 目印ではさまれた部分を入れ替える。
 * 目印が無いファイルは、何もせずに false を返す（エラーにはしない）。
 */
function works_replace_block($path, $mark, $body)
{
    if (!is_file($path)) return false;
    $html = file_get_contents($path);
    if ($html === false) return false;

    $start = '<!-- WORKS:START ' . $mark . ' -->';
    $end   = '<!-- WORKS:END ' . $mark . ' -->';

    $a = strpos($html, $start);
    if ($a === false) return false;
    $b = strpos($html, $end, $a);
    if ($b === false) return false;

    $before = substr($html, 0, $a + strlen($start));
    $after  = substr($html, $b);
    $new = $before . "\n" . $body . '    ' . $after;

    if ($new === $html) return true;                 // 変わっていないなら書かない
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $new, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    @chmod($path, 0644);
    return true;
}

/**
 * works.html と各サービスページを作り直す。
 * 戻り値: array('ok' => array(ファイル名...), 'ng' => array(ファイル名...))
 */
function works_regenerate($items)
{
    global $WORK_PAGES;

    $ok = array();
    $ng = array();

    // --- 施工例の一覧ページ ---
    $cards = '';
    foreach ($items as $it) $cards .= works_card_html($it);
    if ($cards === '') {
        $cards = '        <p class="wempty">施工例はまだ登録されていません。</p>' . "\n";
    }

    $r1 = works_replace_block(SITE_DIR . '/works.html', 'list', $cards);
    $r2 = works_replace_block(SITE_DIR . '/works.html', 'filter', works_filter_html($items));
    if ($r1 && $r2) $ok[] = 'works.html'; else $ng[] = 'works.html';

    // --- 各サービスページ（新しい順に4件ずつ） ---
    foreach ($WORK_PAGES as $cat => $file) {
        $path = SITE_DIR . '/' . $file;
        if (!is_file($path)) continue;

        $body = '';
        $n = 0;
        foreach ($items as $it) {
            if (!isset($it['cat']) || $it['cat'] !== $cat) continue;
            $body .= works_item_html($it, ++$n);
            if ($n >= WORKS_ON_SERVICE) break;
        }
        if ($body === '') {
            $body = '      <p class="wempty">施工例はまだ登録されていません。</p>' . "\n";
        }

        $r = works_replace_block($path, $cat, $body);
        if ($r === false) {
            // 目印が無いページは対象外。書けなかったときだけ拾う
            $html = @file_get_contents($path);
            if ($html !== false && strpos($html, '<!-- WORKS:START ' . $cat . ' -->') !== false) $ng[] = $file;
        } else {
            $ok[] = $file;
        }
    }

    return array('ok' => $ok, 'ng' => $ng);
}


/* ============================================================
 * 写真の保存
 * ========================================================== */

/**
 * アップロードされた写真を works/photos/ に保存する。
 * 保存できたら「サイトの根からの相対パス」を返す。だめなら文字列でエラーを返す。
 */
function works_store_photo($tmpPath, $id, $seq)
{
    if (!is_dir(WORKS_PHOTO)) {
        if (!@mkdir(WORKS_PHOTO, 0755, true)) return '写真の保存先を作れませんでした。';
    }
    if (!is_writable(WORKS_PHOTO)) {
        return '写真の保存先に書き込めませんでした（works/photos の権限を 755 か 775 にしてください）。';
    }

    // 中身が本当に画像かどうかを確かめる（拡張子は信用しない）
    $type = @exif_imagetype($tmpPath);
    $allow = array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF);
    if (defined('IMAGETYPE_WEBP')) $allow[] = IMAGETYPE_WEBP;
    if ($type === false || !in_array($type, $allow, true)) {
        return 'この形式には対応していません。JPEG・PNG の写真をお選びください。'
            . 'iPhone の HEIC 形式の場合は、設定 → カメラ → フォーマット を「互換性優先」にしてください。';
    }

    $im = open_image($tmpPath);
    if (!$im) return '写真を読み込めませんでした。別の写真でお試しください。';
    if ($type === IMAGETYPE_JPEG) $im = apply_orientation($im, $tmpPath);

    // 幅を詰める
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w > MAX_WIDTH) {
        $nh = (int)round($h * MAX_WIDTH / $w);
        $dst = imagecreatetruecolor(MAX_WIDTH, $nh);
        imagecopyresampled($dst, $im, 0, 0, 0, 0, MAX_WIDTH, $nh, $w, $h);
        imagedestroy($im);
        $im = $dst;
        $w = MAX_WIDTH;
        $h = $nh;
    }

    // 透過部分は白で埋めてから JPEG にする
    $flat = imagecreatetruecolor($w, $h);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
    imagedestroy($im);

    $name = $id . '-' . $seq . '-' . substr(md5(uniqid('', true)), 0, 6) . '.jpg';
    $saved = imagejpeg($flat, WORKS_PHOTO . '/' . $name, JPEG_QUALITY);
    imagedestroy($flat);

    if (!$saved) return '写真を保存できませんでした。もう一度お試しください。';
    @chmod(WORKS_PHOTO . '/' . $name, 0644);
    return 'works/photos/' . $name;
}

/**
 * 写真を消す。
 * works/photos/ の中のものだけ。images/ の写真（最初から入っていたもの）は
 * kanri の写真入れ替え画面で使うので、ここでは消さない。
 */
function works_delete_photo($src)
{
    if (strpos($src, 'works/photos/') !== 0) return;
    if (strpos($src, '..') !== false) return;
    $path = SITE_DIR . '/' . $src;
    if (is_file($path)) @unlink($path);
}
