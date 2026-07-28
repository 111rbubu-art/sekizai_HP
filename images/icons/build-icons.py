#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""サービスの線画アイコンを、墓石の実寸比から組み立てる。

    python3 images/icons/build-icons.py

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

    def save(self, name):
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
ic = Icon()
tiers, bottom, base_w = ic.stone(cx=32, top=8, height=46)
for t in tiers:
    ic.rect(*t)
ic.line(32 - base_w / 2 - 5, bottom, 32 + base_w / 2 + 5, bottom, opacity='.4')
ic.save('service-new.svg')

# ---------------------------------------------------------------- クリーニング
ic = Icon()
tiers, bottom, base_w = ic.stone(cx=26, top=11, height=40)
for t in tiers:
    ic.rect(*t)
ic.line(26 - base_w / 2 - 4, bottom, 26 + base_w / 2 + 4, bottom, opacity='.4')
# しずく
ic.raw('M45 14c0 0-4.4 5.6-4.4 8.3a4.4 4.4 0 0 0 8.8 0C49.4 19.6 45 14 45 14z',
       (40.6, 14, 49.4, 26.7), ' stroke-linejoin="round"')
# 洗い流れ
ic.raw('M40.5 30c3 2 6.4 2 9.4 0', (40.5, 30, 49.9, 31.6), ' opacity=".55"')
ic.raw('M41.8 34.4c2.4 1.7 5 1.7 7.4 0', (41.8, 34.4, 49.2, 35.7), ' opacity=".4"')
ic.save('service-cleaning.svg')

# ---------------------------------------------------------------- 地震対策
ic = Icon()
unit = 40 / float(sum(TAKASA))
pad_h = 2.4                       # 実際はごく薄いが、図として見えるよう厚めに描く
top = 10
tiers = []
y = top
for i, (hr, wr) in enumerate(zip(TAKASA, HABA)):
    h, w = hr * unit, wr * unit
    tiers.append((32 - w / 2, y, w, h))
    y += h
    if i == 0:                    # 竿石の直下に免震パット
        pw = HABA[1] * unit * .96
        ic_pad = (32 - pw / 2, y, pw, pad_h)
        y += pad_h
for t in tiers:
    ic.rect(*t)
ic.rect(*ic_pad, fill='.82')
base_w = HABA[-1] * unit
ic.line(32 - base_w / 2 - 5, y, 32 + base_w / 2 + 5, y, opacity='.4')
# 横揺れを受け流す
ay = ic_pad[1] + pad_h / 2
for sx, d in ((32 - base_w / 2 - 3, -1), (32 + base_w / 2 + 3, 1)):
    ic.raw('M%s %sh%s' % (r(sx), r(ay), r(6 * d)), (min(sx, sx + 6 * d), ay - 3, max(sx, sx + 6 * d), ay + 3),
           ' opacity=".7" stroke-linecap="round"')
    tip = sx + 6 * d
    ic.raw('M%s %sl%s 2.6l%s 2.6' % (r(tip - 2.6 * d), r(ay - 2.6), r(2.6 * d), r(-2.6 * d)),
           (tip - 3, ay - 3, tip + 3, ay + 3),
           ' opacity=".7" stroke-linecap="round" stroke-linejoin="round"')
ic.save('service-quake.svg')

# ---------------------------------------------------------------- リフォーム
ic = Icon()
tiers, bottom, base_w = ic.stone(cx=24, top=13, height=36)
for t in tiers:
    ic.rect(*t)
# 差し替える花立の中筒
cyl_w, cyl_h = 8, 17
cx2 = 47
ic.rect(cx2 - cyl_w / 2, bottom - cyl_h, cyl_w, cyl_h)
ic.line(cx2 - cyl_w / 2, bottom - cyl_h + 3, cx2 + cyl_w / 2, bottom - cyl_h + 3, opacity='.55')
ic.line(24 - base_w / 2 - 4, bottom, cx2 + cyl_w / 2 + 4, bottom, opacity='.4')
# 引き抜く矢印
ic.raw('M%s %sV%s' % (r(cx2), r(bottom - cyl_h - 4), r(bottom - cyl_h - 12)),
       (cx2 - 3, bottom - cyl_h - 12, cx2 + 3, bottom - cyl_h - 4), ' stroke-linecap="round"')
ic.raw('m%s %s %s -3 %s 3' % (r(cx2 - 3), r(bottom - cyl_h - 9), 3, 3),
       (cx2 - 3, bottom - cyl_h - 12, cx2 + 3, bottom - cyl_h - 9),
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

print('\n高さ比 %s ／ 幅比 %s' % (':'.join(map(str, TAKASA)), ':'.join(map(str, HABA))))
