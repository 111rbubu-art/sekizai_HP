<?php
/*
 * サーバー上にある写真の一覧（写真ライブラリ）
 * ------------------------------------------------------------------
 * 「写真の入れ替え」と「施工例の登録」の両方から使う。
 * 一度アップした写真を、もう一度アップし直さずに選べるようにするため。
 *
 * 集める場所は次の2つだけ。ここに無いものは選べない。
 * この一覧がそのまま安全柵になっている（好きなパスを送られても弾ける）。
 *
 *   images/        サイトに置いてある写真
 *   works/photos/  施工例として登録した写真
 *
 * ブラウザからは library.php?json=1 で一覧を受け取る。
 */

require_once __DIR__ . '/works_lib.php';

// 探しに行く場所と、画面に出す名前
$LIB_DIRS = array(
    'works/photos' => '施工例の写真',
    'images'       => 'サイトの写真',
);

define('LIB_EXT', 'jpg,jpeg,png,webp,gif');

/*
 * 「仮画像かどうか」の判定は、写真を1枚ずつ開いて中身を見るので重い。
 * 写真が数百枚になると開くたびに待たされるため、結果を覚えておく。
 * 覚え書きは path|更新日時|容量 で引くので、写真を差し替えれば自動で調べ直す。
 */
define('LIB_CACHE', __DIR__ . '/_libcache.php');

function lib_cache_load()
{
    if (!is_file(LIB_CACHE)) return array();
    $raw = @file_get_contents(LIB_CACHE);
    if ($raw === false) return array();
    $at = strpos($raw, '{');
    if ($at === false) return array();
    $d = json_decode(substr($raw, $at), true);
    return is_array($d) ? $d : array();
}

function lib_cache_save($map)
{
    // 覚え書きが増えすぎないよう、使わなくなった分は毎回落としている
    $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return;
    $tmp = LIB_CACHE . '.tmp';
    if (@file_put_contents($tmp, "<?php exit; ?>\n" . $json, LOCK_EX) === false) return;   // 外から読ませない
    if (!@rename($tmp, LIB_CACHE)) @unlink($tmp);
}

/**
 * 写真を集める。
 * 戻り値: array(array('src','group','w','h','bytes','mtime','name','ph'), ...)
 *   src   … サイトの根からの相対パス
 *   ph    … まだ差し替えていない仮画像かどうか
 */
function library_items()
{
    global $LIB_DIRS;

    $exts = explode(',', LIB_EXT);
    $out = array();

    $was = lib_cache_load();
    $now = array();

    foreach ($LIB_DIRS as $dir => $label) {
        $base = SITE_DIR . '/' . $dir;
        if (!is_dir($base)) continue;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $exts, true)) continue;

            $rel = $dir . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            $size = @getimagesize($file->getPathname());

            $key = $rel . '|' . $file->getMTime() . '|' . $file->getSize();
            if (isset($was[$key])) {
                $ph = (bool)$was[$key];
            } else {
                $ph = is_placeholder($file->getPathname());
            }
            $now[$key] = $ph ? 1 : 0;

            $out[] = array(
                'src'   => $rel,
                'group' => $label,
                'name'  => basename($rel),
                'w'     => $size ? $size[0] : 0,
                'h'     => $size ? $size[1] : 0,
                'bytes' => $file->getSize(),
                'mtime' => $file->getMTime(),
                'ph'    => $ph,
            );
        }
    }

    if ($now !== $was) lib_cache_save($now);

    // 新しい順。使いたいのはたいてい、さっき上げたばかりの写真
    usort($out, function ($a, $b) { return $b['mtime'] - $a['mtime']; });
    return $out;
}

/**
 * 送られてきたパスが、本当に一覧にあるものか確かめる。
 * 良ければサーバー上の実際の場所を、だめなら null を返す。
 * ブラウザから来た文字は信用しない。
 */
function library_path($rel)
{
    global $LIB_DIRS;

    $rel = (string)$rel;
    if ($rel === '' || strpos($rel, "\0") !== false) return null;
    if (strpos($rel, '..') !== false) return null;

    // 決めた場所の下にあるか
    $ok = false;
    foreach (array_keys($LIB_DIRS) as $dir) {
        if (strpos($rel, $dir . '/') === 0) { $ok = true; break; }
    }
    if (!$ok) return null;

    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (!in_array($ext, explode(',', LIB_EXT), true)) return null;

    $path = SITE_DIR . '/' . $rel;
    if (!is_file($path)) return null;

    // 記号リンクなどで外へ出ていないか、実際の場所で確かめる
    $real = realpath($path);
    $root = realpath(SITE_DIR);
    if ($real === false || $root === false) return null;
    if (strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) return null;

    return $real;
}


/* ------------------------------------------------------------
 * ブラウザからの問い合わせに答える
 * ---------------------------------------------------------- */

if (basename($_SERVER['SCRIPT_FILENAME']) === 'library.php' && isset($_GET['json'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(array('items' => library_items()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
