#!/bin/bash
# ============================================================
# 庄司石材店 ホームページ — さくらへの転送
#
#   ./deploy.sh --dry      転送内容の確認だけ（最初は必ずこれ）
#   ./deploy.sh            転送する
#   ./deploy.sh --first    初回だけ。写真と管理画面も一緒に送る
#   ./deploy.sh --preview  ~/www/preview/ へ送る。本番には触れない
#
# 事前に ~/.ssh/config へ:
#   Host sakura
#     HostName アカウント名.sakura.ne.jp
#     User アカウント名
#     IdentityFile ~/.ssh/id_ed25519
# ============================================================
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)/"
DEST="sakura:~/www/"
# 独自ドメインの設定によっては ~/www/ドメイン名/ が公開先になる。
# その場合は上の DEST を書き換えること。

DRY=""
FIRST=""
for a in "$@"; do
  case "$a" in
    --dry|-n)  DRY="--dry-run" ;;
    --first)   FIRST="1" ;;
    --preview) DEST="sakura:~/www/preview/" ;;
    *) echo "使い方: $0 [--dry] [--first] [--preview]" >&2; exit 1 ;;
  esac
done

# ------------------------------------------------------------
# 絶対に消してはいけないもの
#
#   gaichu/     運用中の外注先ポータル。git には無いので --delete で消える
#   preview/    公開前の確認用。本番へ送るときは巻き込まない
#   uketsuke/   お客様からの受付（今後追加する予定）
#   kanri/      写真の入れ替え画面。_backup/ に控えが入っている
#   images/     差し替えた写真はサーバ側が正。git のほうが古い
#   works/      従業員が登録した施工例。これもサーバ側が正
#
# --- 以下は「消さないため」ではなく「今はまだ触らないため」の除外 ---
#
#   wp/         以前の WordPress の残骸。もう使っていない。
#   index.php   〃。www 直下にあり、index.html より優先される可能性がある
#   .htaccess   〃。WordPress の書き換えルールが入っているとみられる
#
#   ★ 本番へ切り替える前に、この3つは PC へ控えを取ったうえで
#      サーバから削除すること。データベースの削除も忘れずに。
#   ★ 削除して、こちらの .htaccess（HTTPSリダイレクト等）を作ったら、
#      下の --exclude '.htaccess' を必ず外すこと。外さないと転送されない。
# ------------------------------------------------------------
KEEP=(
  --exclude 'gaichu/'
  --exclude 'wp/'
  --exclude 'index.php'
  --exclude '.htaccess'
  --exclude 'preview/'
  --exclude 'uketsuke/'
  --exclude 'kanri/'
  --exclude 'images/'
  --exclude 'works/'
)

# 開発用のファイルは送らない
SKIP=(
  --exclude '.git/'
  --exclude '.gitignore'
  --exclude 'deploy.sh'
  --exclude 'README.md'
  --exclude '.DS_Store'
  --exclude 'Thumbs.db'
  --exclude 'assets/icons/build-icons.py'
)

if [ -n "$FIRST" ]; then
  # 初回はサーバ側に写真も管理画面も無いので、それも一緒に送る。
  # ただし、さくらに元から入っているもの（wp/ など）は初回でも守る
  KEEP=(
    --exclude 'gaichu/'
    --exclude 'wp/'
    --exclude 'index.php'
    --exclude '.htaccess'
    --exclude 'preview/'
    --exclude 'uketsuke/'
    --exclude 'kanri/_backup/'
  )
  echo "※ 初回モード：写真と管理画面も一緒に送ります"
fi

echo "転送元 : $SRC"
echo "転送先 : $DEST"
[ -n "$DRY" ] && echo "※ ドライラン（実際には転送しません）"
echo

rsync -avz --delete $DRY "${KEEP[@]}" "${SKIP[@]}" "$SRC" "$DEST"

echo
if [ -n "$DRY" ]; then
  cat <<'MSG'
--- 確認してください ---
  1. deleting の行に gaichu/ wp/ index.php .htaccess が出ていないか
     出ていたら中止。運用中のポータルや WordPress が消えます
  2. deleting の行に kanri/ images/ works/ が出ていないか
     出ていたら、差し替えた写真や登録した施工例が消えます
MSG
else
  cat <<'MSG'
デプロイ完了

--- 転送のあとに、必ず一度だけ ---
  kanri/works.php を開いて「ページを作り直す」を押してください。
  works.html と各サービスページを丸ごと入れ替えたため、
  施工例の部分が手元の（古い）内容に戻っています。
MSG
fi
