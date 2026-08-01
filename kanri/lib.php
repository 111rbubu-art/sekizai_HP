<?php
/*
 * 写真の入れ替え — 共通処理
 * ------------------------------------------------------------------
 * サイトのHTMLを読んで「写真枠」を洗い出す。
 * 一覧にない場所へは書き込めないので、これが安全柵にもなっている。
 */

mb_internal_encoding('UTF-8');

define('SITE_DIR', dirname(__DIR__));            // 公開ディレクトリ（www/）
define('IMG_DIR',  SITE_DIR . '/images');
define('BACKUP_DIR', __DIR__ . '/_backup');

define('MAX_WIDTH', 1600);                        // 保存時の最大の幅
define('JPEG_QUALITY', 82);

// ページの並び順と表示名（ここに無いページは末尾に回る）
$PAGE_LABELS = array(
    'index.html'     => 'トップ',
    'boseki.html'    => 'お墓 新規',
    'cleaning.html'  => 'クリーニング',
    'jishin.html'    => '地震対策',
    'reform.html'    => 'リフォーム',
    'engraving.html' => '追加彫刻',
    'company.html'   => '会社概要',
    'pizza.html'     => 'ピザ窯',
    'speaker.html'   => '石のスピーカー',
);

/**
 * サイト内の写真枠をすべて集める。
 * 戻り値: array(相対パス => array('alt'=>..., 'pages'=>array(ページ名...)))
 */
function collect_slots()
{
    global $PAGE_LABELS;
    $slots = array();

    foreach (glob(SITE_DIR . '/*.html') as $file) {
        $name = basename($file);
        $html = file_get_contents($file);
        if ($html === false) continue;

        $label = isset($PAGE_LABELS[$name]) ? $PAGE_LABELS[$name] : $name;

        $found = array();   // 相対パス => 説明
        if (preg_match_all('/<img\b[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (!preg_match('/\bsrc="(images\/[^"]+)"/i', $tag, $m)) continue;
                preg_match('/\balt="([^"]*)"/i', $tag, $a);
                $found[$m[1]] = isset($a[1]) ? $a[1] : '';
            }
        }
        // SNSで共有されたときのサムネイルは <img> ではないので個別に拾う
        if (preg_match('/property="og:image"\s+content="(images\/[^"]+)"/i', $html, $m)
            || preg_match('/content="(images\/[^"]+)"\s+property="og:image"/i', $html, $m)) {
            $found[$m[1]] = 'SNSやLINEでURLを共有したときに出るサムネイル';
        }

        foreach ($found as $rel => $alt) {
            if (!isset($slots[$rel])) {
                $slots[$rel] = array('alt' => $alt, 'pages' => array());
            } elseif ($slots[$rel]['alt'] === '' && $alt !== '') {
                $slots[$rel]['alt'] = $alt;
            }
            if (!in_array($label, $slots[$rel]['pages'], true)) {
                $slots[$rel]['pages'][] = $label;
            }
        }
    }

    // 複数のページで使っている写真は、PAGE_LABELS の並びで先に出るページを「本籍」にする
    // （そうしないと、ファイル名のアルファベット順で拾った順になってしまう）
    $order = array_flip(array_values($PAGE_LABELS));
    foreach ($slots as $rel => $s) {
        usort($slots[$rel]['pages'], function ($a, $b) use ($order) {
            $ia = isset($order[$a]) ? $order[$a] : 999;
            $ib = isset($order[$b]) ? $order[$b] : 999;
            return $ia === $ib ? strcmp($a, $b) : $ia - $ib;
        });
    }

    uksort($slots, 'slot_sort');
    return $slots;
}

function slot_sort($a, $b)
{
    // works/ のような下位フォルダーは後ろへ
    $da = substr_count($a, '/');
    $db = substr_count($b, '/');
    if ($da !== $db) return $da - $db;
    return strcmp($a, $b);
}

/** いまの写真の情報（大きさ・容量・更新日・仮画像かどうか） */
function slot_info($rel)
{
    $path = SITE_DIR . '/' . $rel;
    if (!is_file($path)) return null;
    $size = @getimagesize($path);
    return array(
        'w' => $size ? $size[0] : 0,
        'h' => $size ? $size[1] : 0,
        'bytes' => filesize($path),
        'mtime' => filemtime($path),
        'placeholder' => is_placeholder($path),
    );
}

/**
 * 中身がほぼ均一なら、まだ差し替えていない仮画像とみなす。
 * 等間隔の格子で見る（ランダムに拾うと、開くたびに判定が変わってしまうため）。
 */
function is_placeholder($path)
{
    $im = @open_image($path);
    if (!$im) return false;
    $w = imagesx($im); $h = imagesy($im);

    $n = 16;                       // 16 × 16 = 256 か所
    $vals = array();
    for ($y = 0; $y < $n; $y++) {
        for ($x = 0; $x < $n; $x++) {
            $px = (int)(($x + 0.5) * $w / $n);
            $py = (int)(($y + 0.5) * $h / $n);
            $c = imagecolorat($im, $px, $py);
            $vals[] = (($c >> 16 & 0xFF) + ($c >> 8 & 0xFF) + ($c & 0xFF)) / 3;
        }
    }
    imagedestroy($im);

    $mean = array_sum($vals) / count($vals);
    $var = 0;
    foreach ($vals as $v) $var += ($v - $mean) * ($v - $mean);
    return sqrt($var / count($vals)) < 12;
}

function open_image($path)
{
    $t = @exif_imagetype($path);
    if ($t === IMAGETYPE_JPEG) return @imagecreatefromjpeg($path);
    if ($t === IMAGETYPE_PNG)  return @imagecreatefrompng($path);
    if ($t === IMAGETYPE_GIF)  return @imagecreatefromgif($path);
    if (defined('IMAGETYPE_WEBP') && $t === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($path);
    }
    return null;
}

/** iPhone などの回転情報を反映する */
function apply_orientation($im, $path)
{
    if (!function_exists('exif_read_data')) return $im;
    $e = @exif_read_data($path);
    if (!$e || empty($e['Orientation'])) return $im;
    switch ($e['Orientation']) {
        case 3: return imagerotate($im, 180, 0);
        case 6: return imagerotate($im, -90, 0);
        case 8: return imagerotate($im, 90, 0);
    }
    return $im;
}

function human_bytes($n)
{
    if ($n >= 1048576) return round($n / 1048576, 1) . ' MB';
    if ($n >= 1024) return round($n / 1024) . ' KB';
    return $n . ' B';
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Basic認証がかかっているか（かかっていなければ画面で警告する） */
function is_protected()
{
    return !empty($_SERVER['PHP_AUTH_USER']) || !empty($_SERVER['REMOTE_USER'])
        || !empty($_SERVER['REDIRECT_REMOTE_USER']);
}
