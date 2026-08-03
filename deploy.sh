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
#   wp/         さくらに入っている WordPress
#   index.php   〃（www 直下にある）
#   .htaccess   〃（www 直下にある。中身を確認するまで触らない）
#   preview/    公開前の確認用。本番へ送るときは巻き込まない
#   uketsuke/   お客様からの受付（今後追加する予定）
#   kanri/      写真の入れ替え画面。_backup/ に控えが入っている
#   images/     差し替えた写真はサーバ側が正。git のほうが古い
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
  2. deleting の行に kanri/ や images/ が出ていないか
     出ていたら、差し替えた写真が消えます
MSG
else
  echo "デプロイ完了"
fi
