<?php
/*
 * 写真の入れ替え — 受信して保存する
 * ------------------------------------------------------------------
 * ・保存先は、サイトのHTMLから見つかった写真枠だけ（一覧に無い場所へは書けない）
 * ・上書きの前に、いまの写真を _backup/ に取っておく
 * ・回転を直し、幅を詰めてから保存する
 */

require __DIR__ . '/library.php';

session_start();

$AJAX = !empty($_POST['ajax']);

function back($type, $msg, $extra = array())
{
    global $AJAX;
    if ($AJAX) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge(array('ok' => $type === 'ok', 'msg' => $msg), $extra),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $_SESSION['flash'] = array('type' => $type, 'msg' => $msg);
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back('ng', '不正な操作です。');

// 受信上限を超えると PHP は $_POST も $_FILES も捨てるので、先に分かりやすく返す
if (!count($_POST) && !count($_FILES) && !empty($_SERVER['CONTENT_LENGTH'])) {
    back('ng', '写真が大きすぎます（サーバーの受信上限 ' . ini_get('post_max_size')
        . '）。撮影サイズを小さくするか、1枚ずつお試しください。');
}

$slot = isset($_POST['slot']) ? $_POST['slot'] : '';
$slots = collect_slots();
if (!isset($slots[$slot])) back('ng', 'その置き場所は見つかりませんでした。');

// 保存する大きさ（選ばれていなければ「ふつう」）
$sizeKey = isset($_POST['size']) ? $_POST['size'] : '';
if (!isset($SAVE_PRESETS[$sizeKey])) $sizeKey = SAVE_PRESET_DEFAULT;
$preset = $SAVE_PRESETS[$sizeKey];

$wasBytes = is_file(SITE_DIR . '/' . $slot) ? filesize(SITE_DIR . '/' . $slot) : 0;

/*
 * 写真の出どころは2通り。
 *   ・手元のパソコンから選んだ（$_FILES）
 *   ・サーバーにすでにある写真から選んだ（$_POST['from']）
 * どちらの場合も、このあとの扱いは同じ。
 */
$from = isset($_POST['from']) ? trim($_POST['from']) : '';
$hasUpload = isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK;

if (!$hasUpload && $from !== '') {
    $tmp = library_path($from);
    if ($tmp === null) back('ng', 'その写真は見つかりませんでした。選び直してください。');
    if ($tmp === SITE_DIR . '/' . $slot || realpath(SITE_DIR . '/' . $slot) === $tmp) {
        back('ng', 'いまと同じ写真です。別の写真をお選びください。');
    }
} elseif (!$hasUpload) {
    $e = isset($_FILES['photo']) ? $_FILES['photo']['error'] : -1;
    $big = '写真が大きすぎます（1枚あたりの上限 ' . ini_get('upload_max_filesize') . '）。'
         . 'PNG は同じ写真でも JPEG の3〜8倍の容量になります。'
         . 'JPEG で保存し直すか、kanri/.user.ini で上限を上げてください。';
    $msg = array(
        UPLOAD_ERR_INI_SIZE  => $big,
        UPLOAD_ERR_FORM_SIZE => $big,
        UPLOAD_ERR_PARTIAL   => '通信が途中で切れました。もう一度お試しください。',
        UPLOAD_ERR_NO_FILE   => '写真が選ばれていません。手元のファイルを選ぶか、'
                              . '「サーバーの写真から選ぶ」をお使いください。',
    );
    back('ng', isset($msg[$e]) ? $msg[$e] : 'アップロードに失敗しました（コード ' . $e . '）。');
} else {
    $tmp = $_FILES['photo']['tmp_name'];
}

// 中身が本当に画像かどうかを確かめる（拡張子は信用しない）
$type = @exif_imagetype($tmp);
$ok = array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF);
if (defined('IMAGETYPE_WEBP')) $ok[] = IMAGETYPE_WEBP;
if ($type === false || !in_array($type, $ok, true)) {
    back('ng', 'この形式には対応していません。JPEG・PNG・WebP の写真をお選びください。'
        . 'iPhone の HEIC 形式の場合は、設定 → カメラ → フォーマット を「互換性優先」にしてください。');
}

$im = open_image($tmp);
if (!$im) back('ng', '写真を読み込めませんでした。別の写真でお試しください。');
if ($type === IMAGETYPE_JPEG) $im = apply_orientation($im, $tmp);

// 幅を詰める
$w = imagesx($im);
$h = imagesy($im);
$maxW = $preset['w'];
if ($w > $maxW) {
    $nh = (int)round($h * $maxW / $w);
    $dst = imagecreatetruecolor($maxW, $nh);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $maxW, $nh, $w, $h);
    imagedestroy($im);
    $im = $dst;
    $w = $maxW;
    $h = $nh;
}

$target = SITE_DIR . '/' . $slot;
$dir = dirname($target);
if (!is_dir($dir)) @mkdir($dir, 0755, true);
if (!is_writable(is_file($target) ? $target : $dir)) {
    imagedestroy($im);
    back('ng', 'サーバーに書き込めませんでした。images フォルダーの権限をご確認ください（755 または 775）。');
}

// いまの写真を控えておく
if (is_file($target)) {
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
    $keep = BACKUP_DIR . '/' . str_replace('/', '_', $slot) . '.' . date('Ymd-His') . '.bak';
    @copy($target, $keep);
}

// 枠の拡張子に合わせて保存する
$ext = strtolower(pathinfo($slot, PATHINFO_EXTENSION));
$res = null;
if ($ext === 'png') {
    $res = imagepng($im, $target, 6) ? array('bytes' => filesize($target), 'q' => 0) : null;
} elseif ($ext === 'webp' && function_exists('imagewebp')) {
    $res = imagewebp($im, $target, JPEG_QUALITY) ? array('bytes' => filesize($target), 'q' => JPEG_QUALITY) : null;
} else {
    // 目標の容量に収まるまで、画質を段々に下げながら試す
    $res = save_jpeg_within($im, $target, $preset['max']);
}
imagedestroy($im);

if ($res === null) back('ng', '保存できませんでした。もう一度お試しください。');
@chmod($target, 0644);
clearstatcache(true, $target);

$msg = basename($slot) . ' を'
    . ($from !== '' && !$hasUpload ? 'サーバーの ' . basename($from) . ' に' : '')
    . '差し替えました。'
    . $w . '×' . $h . '／' . human_bytes($res['bytes'])
    . ($wasBytes ? '（前は ' . human_bytes($wasBytes) . '）' : '');

// 目標に届かなかったときは、そのことも伝える
if ($ext === 'jpg' || $ext === 'jpeg') {
    if ($preset['max'] > 0 && $res['bytes'] > $preset['max']) {
        $msg .= ' ※ 画質をこれ以上落とすと荒れるため、'
              . human_bytes($preset['max']) . 'には収まりませんでした。'
              . 'もっと小さくしたい場合は、ひとつ下の大きさをお選びください。';
    }
}

$info = slot_info($slot);
back('ok', $msg, array(
    'w'     => $w,
    'h'     => $h,
    'bytes' => $res['bytes'],
    'human' => human_bytes($res['bytes']),
    'mtime' => $info ? $info['mtime'] : time(),
    'ph'    => $info ? $info['placeholder'] : false,
));
