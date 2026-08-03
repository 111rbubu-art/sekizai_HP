/*
 * 施工例まわりの動き
 * ------------------------------------------------------------------
 *  1. 写真の拡大表示（施工前・施工後など、複数枚を送って見る）
 *  2. 一覧ページの絞り込みとページ送り
 *
 * どちらも、対象の要素が無いページでは何もしない。
 * このファイルは works.html と各サービスページから読み込んでいる。
 */

(function () {
  'use strict';

  /* ==========================================================
   * 1. 写真の拡大表示
   * ======================================================== */
  (function () {
    var opens = document.querySelectorAll('.wcard__open');
    if (!opens.length) return;

    var box = null, img = null, cap = null, num = null, prevBtn = null, nextBtn = null;
    var shots = [], at = 0, opener = null;

    function build() {
      box = document.createElement('div');
      box.className = 'lb';
      box.hidden = true;
      box.innerHTML =
        '<div class="lb__back" data-close></div>' +
        '<div class="lb__inner" role="dialog" aria-modal="true" aria-label="施工例の写真">' +
          '<button class="lb__close" type="button" data-close aria-label="閉じる">&#215;</button>' +
          '<div class="lb__stage"><img class="lb__img" src="" alt=""></div>' +
          '<div class="lb__bar">' +
            '<button class="lb__nav" type="button" data-prev aria-label="前の写真">&#8249;</button>' +
            '<p class="lb__cap"><span class="lb__text"></span><span class="lb__num"></span></p>' +
            '<button class="lb__nav" type="button" data-next aria-label="次の写真">&#8250;</button>' +
          '</div>' +
        '</div>';
      document.body.appendChild(box);

      img     = box.querySelector('.lb__img');
      cap     = box.querySelector('.lb__text');
      num     = box.querySelector('.lb__num');
      prevBtn = box.querySelector('[data-prev]');
      nextBtn = box.querySelector('[data-next]');

      box.addEventListener('click', function (e) {
        if (e.target.closest('[data-prev]')) { step(-1); return; }
        if (e.target.closest('[data-next]')) { step(1); return; }
        // 写真と下の説明以外は、どこを押しても閉じる。
        // （黒い余白は .lb__inner が覆っているので、背景だけを見ていると閉じない）
        if (!e.target.closest('.lb__img') && !e.target.closest('.lb__bar')) close();
      });

      document.addEventListener('keydown', function (e) {
        if (box.hidden) return;
        if (e.key === 'Escape') { close(); }
        else if (e.key === 'ArrowLeft') { step(-1); }
        else if (e.key === 'ArrowRight') { step(1); }
        else if (e.key === 'Tab') {
          // 開いている間は、中のボタンから外へ出ないようにする
          var able = box.querySelectorAll('button:not([disabled])');
          if (!able.length) return;
          var first = able[0], last = able[able.length - 1];
          if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
          else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
        }
      });
    }

    function show() {
      var s = shots[at];
      if (!s) return;
      img.src = s.src;
      img.alt = s.alt || '';
      cap.textContent = s.cap || '';
      num.textContent = shots.length > 1 ? (at + 1) + ' / ' + shots.length : '';
      prevBtn.disabled = at <= 0;
      nextBtn.disabled = at >= shots.length - 1;
      prevBtn.hidden = nextBtn.hidden = shots.length < 2;
    }

    function step(d) {
      var n = at + d;
      if (n < 0 || n >= shots.length) return;
      at = n;
      show();
    }

    function open(data, start, from) {
      if (!box) build();
      shots = data;
      at = start || 0;
      opener = from;
      show();
      box.hidden = false;
      document.body.classList.add('lb-open');
      (shots.length > 1 ? nextBtn : box.querySelector('.lb__close')).focus();
    }

    function close() {
      box.hidden = true;
      document.body.classList.remove('lb-open');
      if (opener) { opener.focus(); opener = null; }
    }

    Array.prototype.forEach.call(opens, function (btn) {
      btn.addEventListener('click', function () {
        var host = btn.closest('.wcard, .work');
        var tag = host && host.querySelector('.wcard__data');
        if (!tag) return;
        var data;
        try { data = JSON.parse(tag.textContent); } catch (err) { return; }
        if (!data || !data.length) return;
        open(data, 0, btn);
      });
    });
  })();


  /* ==========================================================
   * 2. 一覧ページの絞り込みとページ送り
   * ======================================================== */
  (function () {
    var grid = document.querySelector('[data-works-grid]');
    if (!grid) return;

    var cards = Array.prototype.slice.call(grid.querySelectorAll('.wcard'));
    var tabs  = Array.prototype.slice.call(document.querySelectorAll('[data-works-filter]'));
    var pager = document.querySelector('[data-works-pager]');
    var count = document.querySelector('[data-works-count]');
    if (!pager || !count) return;

    var PER = parseInt(grid.getAttribute('data-works-per'), 10) || 24;
    var cat = 'all', page = 1;

    function list() {
      return cat === 'all' ? cards : cards.filter(function (el) { return el.dataset.cat === cat; });
    }

    function pageButton(n, label, on) {
      var b = document.createElement(n === null ? 'span' : 'button');
      if (n !== null) {
        b.type = 'button';
        b.addEventListener('click', function () { page = n; render(true); });
      } else {
        b.className = 'gap';
      }
      b.textContent = label;
      if (on) b.className = 'is-on';
      return b;
    }

    function drawPager(pages) {
      pager.textContent = '';
      if (pages <= 1) { pager.hidden = true; return; }
      pager.hidden = false;
      var prev = pageButton(Math.max(1, page - 1), '‹');
      prev.disabled = page === 1;
      pager.appendChild(prev);

      var nums = [];
      if (pages <= 7) {
        for (var i = 1; i <= pages; i++) nums.push(i);
      } else {
        nums = [1];
        if (page > 3) nums.push(null);
        for (var j = Math.max(2, page - 1); j <= Math.min(pages - 1, page + 1); j++) nums.push(j);
        if (page < pages - 2) nums.push(null);
        nums.push(pages);
      }
      nums.forEach(function (n) {
        pager.appendChild(n === null ? pageButton(null, '…') : pageButton(n, String(n), n === page));
      });

      var next = pageButton(Math.min(pages, page + 1), '›');
      next.disabled = page === pages;
      pager.appendChild(next);
    }

    function render(scroll) {
      var items = list();
      var pages = Math.max(1, Math.ceil(items.length / PER));
      if (page > pages) page = pages;
      var from = (page - 1) * PER;
      var to = Math.min(from + PER, items.length);

      cards.forEach(function (el) { el.hidden = true; });
      items.slice(from, to).forEach(function (el) { el.hidden = false; });

      count.textContent = items.length
        ? '全 ' + items.length + ' 件　' + (from + 1) + ' – ' + to + ' 件を表示'
        : '該当する施工例はありません。';

      tabs.forEach(function (t) {
        var on = t.dataset.worksFilter === cat;
        t.classList.toggle('is-on', on);
        t.setAttribute('aria-current', on ? 'true' : 'false');
      });

      drawPager(pages);
      if (scroll) {
        var top = document.getElementById('list');
        if (top) top.scrollIntoView({ behavior: 'smooth' });
      }
    }

    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        cat = t.dataset.worksFilter;
        page = 1;
        if (history.replaceState) {
          history.replaceState(null, '', cat === 'all' ? location.pathname : '#' + cat);
        }
        render(false);
      });
    });

    // boseki.html などから #boseki 付きで来たときは、その種類で開く
    var h = (location.hash || '').replace('#', '');
    if (h && cards.some(function (el) { return el.dataset.cat === h; })) cat = h;
    render(false);
  })();

})();
