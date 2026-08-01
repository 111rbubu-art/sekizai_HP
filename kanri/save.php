<?php
/*
 * 写真の入れ替え — 受信して保存する
 * ------------------------------------------------------------------
 * ・保存先は、サイトのHTMLから見つかった写真枠だけ（一覧に無い場所へは書けない）
 * ・上書きの前に、いまの写真を _backup/ に取っておく
 * ・回転を直し、幅を詰めてから保存する
 */

require __DIR__ . '/lib.php';

session_start();

function back($type, $msg)
{
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

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $e = isset($_FILES['photo']) ? $_FILES['photo']['error'] : -1;
    $msg = array(
        UPLOAD_ERR_INI_SIZE  => '写真が大きすぎます（サーバー上限 ' . ini_get('upload_max_filesize') . '）',
        UPLOAD_ERR_FORM_SIZE => '写真が大きすぎます',
        UPLOAD_ERR_PARTIAL   => '通信が途中で切れました。もう一度お試しください',
        UPLOAD_ERR_NO_FILE   => '写真が選ばれていません',
    );
    back('ng', isset($msg[$e]) ? $msg[$e] : 'アップロードに失敗しました（コード ' . $e . '）');
}

$tmp = $_FILES['photo']['tmp_name'];

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
if ($w > MAX_WIDTH) {
    $nh = (int)round($h * MAX_WIDTH / $w);
    $dst = imagecreatetruecolor(MAX_WIDTH, $nh);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, MAX_WIDTH, $nh, $w, $h);
    imagedestroy($im);
    $im = $dst;
    $w = MAX_WIDTH;
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
$saved = false;
if ($ext === 'png') {
    $saved = imagepng($im, $target, 6);
} elseif ($ext === 'webp' && function_exists('imagewebp')) {
    $saved = imagewebp($im, $target, JPEG_QUALITY);
} else {
    // 透過部分は白で埋めてからJPEGにする
    $flat = imagecreatetruecolor($w, $h);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
    $saved = imagejpeg($flat, $target, JPEG_QUALITY);
    imagedestroy($flat);
}
imagedestroy($im);

if (!$saved) back('ng', '保存できませんでした。もう一度お試しください。');
@chmod($target, 0644);

back('ok', basename($slot) . ' を差し替えました（' . $w . '×' . $h . '）。'
    . 'サイトを再読み込みしてご確認ください。');
