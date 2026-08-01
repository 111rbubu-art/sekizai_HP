#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""サービスの線画アイコンを、墓石の実寸比から組み立てる。

    python3 assets/icons/build-icons.py

比率を変えたいときは下の TAKASA / HABA を書き換えて実行し直してください。
座標は自動で計算され、viewBox も描画範囲に合わせて詰められます。
"""
import io
import os

# 和型墓石の寸法比（上から 竿石・上台・中台・芝台）
TAKASA = (63, 30, 30, 15)   # 高さ
HABA   = (24, 42, 60, 85)   # 幅

STROKE = 1.3
MARGIN = 2.0                # viewBox の余白


class Icon:
    """描いた要素の範囲を覚えておき、最後に viewBox を合わせる。"""

    def __init__(self):
        self.body = []
        self.x0 = self.y0 = 1e9
        self.x1 = self.y1 = -1e9

    def _grow(self, x0, y0, x1, y1):
        self.x0, self.y0 = min(self.x0, x0), min(self.y0, y0)
        self.x1, self.y1 = max(self.x1, x1), max(self.y1, y1)

    def rect(self, x, y, w, h, fill=None):
        d = 'M%s %sh%sv%sh-%sz' % (r(x), r(y), r(w), r(h), r(w))
        if fill:
            self.body.append('  <path d="%s" fill="#1a1a1a" fill-opacity="%s" stroke="none"/>' % (d, fill))
        self.body.append('  <path d="%s"/>' % d)
        self._grow(x, y, x + w, y + h)

    def line(self, x0, y0, x1, y1, opacity=None, cap=None):
        a = ' opacity="%s"' % opacity if opacity else ''
        a += ' stroke-linecap="%s"' % cap if cap else ''
        self.body.append('  <path d="M%s %sL%s %s"%s/>' % (r(x0), r(y0), r(x1), r(y1), a))
        self._grow(min(x0, x1), min(y0, y1), max(x0, x1), max(y0, y1))

    def raw(self, d, bbox, extra=''):
        self.body.append('  <path d="%s"%s/>' % (d, extra))
        self._grow(*bbox)

    def text(self, x, y, s, size=6.2, spacing=1.1, anchor='start', opacity='.85'):
        self.body.append(
            '  <text x="%s" y="%s" font-family="Georgia, \'Times New Roman\', serif" '
            'font-size="%s" letter-spacing="%s" text-anchor="%s" '
            'fill="#1a1a1a" fill-opacity="%s" stroke="none">%s</text>'
            % (r(x), r(y), r(size), r(spacing), anchor, opacity, s))
        w = len(s) * (size * .62 + spacing)
        x0 = x if anchor == 'start' else (x - w / 2 if anchor == 'middle' else x - w)
        self._grow(x0, y - size, x0 + w, y + size * .25)

    def sparkle(self, cx, cy, rad, opacity=None):
        """四方に伸びる、光のしるし。"""
        d = ('M%s %sQ%s %s %s %sQ%s %s %s %sQ%s %s %s %sQ%s %s %s %sz'
             % (r(cx), r(cy - rad), r(cx), r(cy), r(cx + rad), r(cy),
                r(cx), r(cy), r(cx), r(cy + rad), r(cx), r(cy),
                r(cx - rad), r(cy), r(cx), r(cy), r(cx), r(cy - rad)))
        a = ' opacity="%s"' % opacity if opacity else ''
        self.body.append('  <path d="%s" stroke-linejoin="round"%s/>' % (d, a))
        self._grow(cx - rad, cy - rad, cx + rad, cy + rad)

    def circle(self, cx, cy, rad):
        self.body.append('  <circle cx="%s" cy="%s" r="%s"/>' % (r(cx), r(cy), r(rad)))
        self._grow(cx - rad, cy - rad, cx + rad, cy + rad)

    def tilted(self, x, y, w, h, deg, opacity=None):
        """下辺の角を軸にして傾けた石。地震で竿石がずれた様子に使う。"""
        px, py = (x if deg > 0 else x + w), y + h
        a = ' opacity="%s"' % opacity if opacity else ''
        self.body.append(
            '  <g transform="rotate(%s %s %s)"%s><path d="M%s %sh%sv%sh-%sz"/></g>'
            % (r(deg), r(px), r(py), a, r(x), r(y), r(w), r(h), r(w)))
        self._grow(x - h * abs(deg) / 45.0, y, x + w + h * abs(deg) / 45.0, y + h)

    def stone(self, cx, top, height):
        """比率どおりに各段を積む。戻り値は (各段, 下端, 最大幅)。"""
        unit = height / float(sum(TAKASA))
        y = top
        tiers = []
        for hr, wr in zip(TAKASA, HABA):
            h, w = hr * unit, wr * unit
            tiers.append((cx - w / 2, y, w, h))
            y += h
        return tiers, y, HABA[-1] * unit

    def save(self, name, box=None):
        if box:                       # 決め打ちの画枠（複数のアイコンで大きさを揃えたいとき）
            vb = '%s %s %s %s' % tuple(r(v) for v in box)
        else:
            p = MARGIN + STROKE / 2
            vb = '%s %s %s %s' % (r(self.x0 - p), r(self.y0 - p),
                                  r(self.x1 - self.x0 + p * 2), r(self.y1 - self.y0 + p * 2))
        svg = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s" fill="none"\n'
               '     stroke="#1a1a1a" stroke-width="%s" stroke-linejoin="miter">\n'
               '%s\n</svg>\n') % (vb, STROKE, '\n'.join(self.body))
        io.open(os.path.join(os.path.dirname(os.path.abspath(__file__)), name),
                'w', encoding='utf-8').write(svg)
        print('%-24s viewBox="%s"' % (name, vb))


def r(v):
    return ('%.2f' % v).rstrip('0').rstrip('.')


# ---------------------------------------------------------------- お墓 新規
# 墓石に「New」の文字を添える
ic = Icon()
tiers, bottom, base_w = ic.stone(cx=30, top=12, height=44)
for t in tiers:
    ic.rect(*t)
ic.line(30 - base_w / 2 - 5, bottom, 30 + base_w / 2 + 5, bottom, opacity='.4')
ic.text(30 + base_w / 2 + 1.5, 26, 'NEW', size=7, spacing=1.3)
ic.save('service-new.svg')

# ---------------------------------------------------------------- クリーニング
# 洗い上がって光っている様子。きらめきを三つ
ic = Icon()
tiers, bottom, base_w = ic.stone(cx=27, top=13, height=40)
for t in tiers:
    ic.rect(*t)
ic.line(27 - base_w / 2 - 4, bottom, 27 + base_w / 2 + 4, bottom, opacity='.4')
ic.sparkle(46, 20, 6.4)
ic.sparkle(53.5, 30, 4.0, opacity='.75')
ic.sparkle(43.5, 32.5, 2.8, opacity='.55')
ic.save('service-cleaning.svg')

# ---------------------------------------------------------------- 地震対策
# 竿石だけが傾いた様子。あいだに免震パットを挟む
ic = Icon()
unit = 40 / float(sum(TAKASA))
pad_h = 2.4                       # 実際はごく薄いが、図として見えるよう厚めに描く
top = 12
y = top
tiers = []
pad = None
for i, (hr, wr) in enumerate(zip(TAKASA, HABA)):
    h, w = hr * unit, wr * unit
    tiers.append((32 - w / 2, y, w, h))
    y += h
    if i == 0:                    # 竿石の直下に免震パット
        pw = HABA[1] * unit * .96
        pad = (32 - pw / 2, y, pw, pad_h)
        y += pad_h
for i, t in enumerate(tiers):
    if i == 0:
        ic.tilted(*t, deg=6)      # 竿石だけ傾ける
    else:
        ic.rect(*t)
ic.rect(*pad, fill='.82')
base_w = HABA[-1] * unit
ic.line(32 - base_w / 2 - 5, y, 32 + base_w / 2 + 5, y, opacity='.4')
ic.save('service-quake.svg')

# ---------------------------------------------------------------- リフォーム
# 古い石塔から新しい石塔へ。あいだに矢印
ic = Icon()
GY = 46                            # 共通の地面

def small_stone(cx, height, old=False):
    unit = height / float(sum(TAKASA))
    y = GY - height
    for i, (hr, wr) in enumerate(zip(TAKASA, HABA)):
        h, w = hr * unit, wr * unit
        ic.body.append('  <path d="M%s %sh%sv%sh-%sz"%s/>'
                       % (r(cx - w / 2), r(y), r(w), r(h), r(w),
                          ' opacity=".45"' if old else ''))
        ic._grow(cx - w / 2, y, cx + w / 2, y + h)
        y += h
    return HABA[-1] * unit

w_old = small_stone(14, 30, old=True)
w_new = small_stone(50, 34)
ic.line(14 - w_old / 2 - 3, GY, 50 + w_new / 2 + 3, GY, opacity='.4')
# 変更を表す矢印
ic.raw('M28 30h7', (28, 27, 35, 33), ' stroke-linecap="round"')
ic.raw('m32.4 26.6 3.8 3.4-3.8 3.4', (32.4, 26.6, 36.2, 33.4),
       ' stroke-linecap="round" stroke-linejoin="round"')
ic.save('service-reform.svg')

# ---------------------------------------------------------------- 追加彫刻
# 墓誌は墓石とは別の部材なので、上の比率は使わない
ic = Icon()
ic.rect(12, 8, 26, 38)
for x, h in ((19, 16), (25, 22), (31, 11)):
    ic.raw('M%s 14v%s' % (x, h), (x, 14, x, 14 + h),
           ' stroke-dasharray="1.6 3.4" opacity=".7" stroke-linecap="round"')
ic.rect(8, 46, 34, 5)
ic.line(4, 51, 60, 51, opacity='.4')
ic.raw('M46.5 38.5 58 27l3.2 3.2L49.7 41.7z', (46.5, 27, 61.2, 41.7))
ic.raw('m46.5 38.5-3.4 6.6 6.6-3.4', (43.1, 38.5, 50.1, 45.1), ' stroke-linejoin="round"')
ic.save('engraving.svg')

# ================================================================
#  お墓のかたち（boseki.html の 01 / Types）
#
#  3つとも同じ画枠（TYPE_BOX）に描くので、並べたときの大小と
#  地面の高さがそのまま画面に出る。
# ================================================================
TYPE_BOX = (0, 0, 88, 96)   # 3つ共通の画枠
TYPE_CX  = 44.0             # 中心
TYPE_GY  = 90.0             # 地面（下のほうに置く）

H_WA  = 83.0                # 和型の高さ
H_TOU = 83.0                # 五輪塔の高さ
H_YOU = 64.0                # 洋型の高さ（前のまま）

# ---------------------------------------------------------------- 和型
# 縦に長い竿石。上の TAKASA / HABA の比率をそのまま使う
ic = Icon()
tiers, bottom, base_w = ic.stone(cx=TYPE_CX, top=TYPE_GY - H_WA, height=H_WA)
for t in tiers:
    ic.rect(*t)
ic.line(TYPE_CX - base_w / 2 - 5, bottom, TYPE_CX + base_w / 2 + 5, bottom, opacity='.4')
ic.save('type-wagata.svg', box=TYPE_BOX)

# ---------------------------------------------------------------- 洋型
# 実寸（mm）で指定する。上から 竿石・上台・芝台
# 天面は傾けない。傾いた石は地震対策のアイコンで「ずれ」を表しているため
YOU_TAKASA = (490, 200, 150)   # 高さ
YOU_HABA   = (600, 730, 850)   # 幅

ic = Icon()
unit = H_YOU / float(sum(YOU_TAKASA))     # 縦横とも同じ縮尺（実寸の比を崩さない）
y = TYPE_GY - H_YOU
for hr, wr in zip(YOU_TAKASA, YOU_HABA):
    h, w = hr * unit, wr * unit
    ic.rect(TYPE_CX - w / 2, y, w, h)
    y += h
gw = YOU_HABA[-1] * unit
ic.line(TYPE_CX - gw / 2 - 5, TYPE_GY, TYPE_CX + gw / 2 + 5, TYPE_GY, opacity='.4')
ic.save('type-yougata.svg', box=TYPE_BOX)

# ---------------------------------------------------------------- 塔型（五輪塔）
# 下から 下段・地輪（方形）・水輪（球）・火輪（屋根）・風輪（半月）・空輪（宝珠）
# 下段の形は和型の中台と同じ比率。全体を H_TOU に収まるよう縮める
TOU = (
    (HABA[2] / 3.0, TAKASA[2] / 3.0),   # 下段（和型の中台と同じ比率）
    (26.0, 19.0),                       # 地輪（方形）
    (22.0, 22.0),                       # 水輪（球）
    (30.0, 14.0),                       # 火輪（屋根）
    (17.0,  9.0),                       # 風輪（半月）
    (14.0, 15.0),                       # 空輪（宝珠）
)
TW_KA = 14.0                            # 火輪の上辺

ic = Icon()
k = H_TOU / sum(h for _, h in TOU)      # 全体が H_TOU になる縮尺
(w_base, h_base), (w_chi, h_chi), (d_sui, _), (w_ka, h_ka), (w_fu, h_fu), (w_ku, h_ku) = \
    [(w * k, h * k) for w, h in TOU]
d_sui *= 1.0
tw_ka = TW_KA * k

y = TYPE_GY
y -= h_base
ic.rect(TYPE_CX - w_base / 2, y, w_base, h_base)

y -= h_chi
ic.rect(TYPE_CX - w_chi / 2, y, w_chi, h_chi)

y -= d_sui
ic.circle(TYPE_CX, y + d_sui / 2, d_sui / 2)

y -= h_ka
ic.raw('M%s %sL%s %sL%s %sL%s %sz'
       % (r(TYPE_CX - w_ka / 2), r(y + h_ka), r(TYPE_CX - tw_ka / 2), r(y),
          r(TYPE_CX + tw_ka / 2), r(y), r(TYPE_CX + w_ka / 2), r(y + h_ka)),
       (TYPE_CX - w_ka / 2, y, TYPE_CX + w_ka / 2, y + h_ka))

y -= h_fu
ic.raw('M%s %sA%s %s 0 0 1 %s %sz'
       % (r(TYPE_CX - w_fu / 2), r(y + h_fu), r(w_fu / 2), r(h_fu),
          r(TYPE_CX + w_fu / 2), r(y + h_fu)),
       (TYPE_CX - w_fu / 2, y, TYPE_CX + w_fu / 2, y + h_fu))

y -= h_ku
ic.raw('M%s %sQ%s %s %s %sL%s %sQ%s %s %s %sz'
       % (r(TYPE_CX), r(y),
          r(TYPE_CX + w_ku / 2), r(y + h_ku * 0.5), r(TYPE_CX + w_ku * 0.36), r(y + h_ku),
          r(TYPE_CX - w_ku * 0.36), r(y + h_ku),
          r(TYPE_CX - w_ku / 2), r(y + h_ku * 0.5), r(TYPE_CX), r(y)),
       (TYPE_CX - w_ku / 2, y, TYPE_CX + w_ku / 2, y + h_ku))

ic.line(TYPE_CX - w_base / 2 - 5, TYPE_GY, TYPE_CX + w_base / 2 + 5, TYPE_GY, opacity='.4')
ic.save('type-tougata.svg', box=TYPE_BOX)

print('\n高さ比 %s ／ 幅比 %s' % (':'.join(map(str, TAKASA)), ':'.join(map(str, HABA))))
