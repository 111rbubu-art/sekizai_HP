/*
 * サーバー上の写真から選ぶ画面
 * ------------------------------------------------------------------
 * 「サーバーの写真から選ぶ」ボタンが押されたら、写真を並べて選ばせる。
 * 選んだら、そのボタンのそばの隠し欄にパスを入れ、見本を出す。
 *
 * ボタンにはこう書いておく:
 *   <button type="button" data-pick="欄の名前">サーバーの写真から選ぶ</button>
 *   <input type="hidden" name="欄の名前" data-pick-value>
 *   <div data-pick-view></div>
 * この3つを、同じ親要素の中に置く。
 */
(function () {
  'use strict';

  var buttons = document.querySelectorAll('[data-pick]');
  if (!buttons.length) return;

  var box = null, grid = null, search = null, tabs = null, empty = null, showPh = null;
  var items = [], group = 'all', target = null, loaded = false;

  function build() {
    box = document.createElement('div');
    box.className = 'pick';
    box.hidden = true;
    box.innerHTML =
      '<div class="pick__back" data-close></div>' +
      '<div class="pick__panel" role="dialog" aria-modal="true" aria-label="サーバーの写真から選ぶ">' +
        '<div class="pick__head">' +
          '<b>サーバーの写真から選ぶ</b>' +
          '<button class="pick__x" type="button" data-close aria-label="閉じる">&#215;</button>' +
        '</div>' +
        '<div class="pick__bar">' +
          '<div class="pick__tabs" data-tabs></div>' +
          '<input class="pick__search" type="search" placeholder="ファイル名でしぼる" data-search>' +
          '<label class="pick__ph"><input type="checkbox" data-ph> 仮画像も出す</label>' +
        '</div>' +
        '<div class="pick__grid" data-grid></div>' +
        '<p class="pick__empty" data-empty hidden>写真が見つかりませんでした。</p>' +
      '</div>';
    document.body.appendChild(box);

    grid   = box.querySelector('[data-grid]');
    search = box.querySelector('[data-search]');
    tabs   = box.querySelector('[data-tabs]');
    empty  = box.querySelector('[data-empty]');
    showPh = box.querySelector('[data-ph]');

    box.addEventListener('click', function (e) {
      if (e.target.closest('[data-close]')) { close(); return; }
      var tab = e.target.closest('[data-group]');
      if (tab) { group = tab.dataset.group; draw(); return; }
      var cell = e.target.closest('[data-src]');
      if (cell) choose(cell.dataset.src, cell.dataset.name);
    });
    search.addEventListener('input', draw);
    showPh.addEventListener('change', draw);
    document.addEventListener('keydown', function (e) {
      if (!box.hidden && e.key === 'Escape') close();
    });
  }

  function load() {
    if (loaded) { draw(); return; }
    grid.innerHTML = '<p class="pick__load">読み込んでいます…</p>';
    fetch('library.php?json=1', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        items = (d && d.items) || [];
        loaded = true;
        drawTabs();
        draw();
      })
      .catch(function () {
        grid.innerHTML = '<p class="pick__load">写真の一覧を読み込めませんでした。'
          + '画面を読み込み直してお試しください。</p>';
      });
  }

  function drawTabs() {
    var names = [];
    items.forEach(function (x) { if (names.indexOf(x.group) < 0) names.push(x.group); });
    var html = '<button type="button" data-group="all">すべて</button>';
    names.forEach(function (n) {
      html += '<button type="button" data-group="' + n + '">' + n + '</button>';
    });
    tabs.innerHTML = html;
  }

  function shown() {
    var q = search.value.trim().toLowerCase();
    return items.filter(function (x) {
      if (group !== 'all' && x.group !== group) return false;
      if (!showPh.checked && x.ph) return false;
      if (q && x.src.toLowerCase().indexOf(q) < 0) return false;
      return true;
    });
  }

  function draw() {
    tabs.querySelectorAll('[data-group]').forEach(function (b) {
      b.classList.toggle('on', b.dataset.group === group);
    });

    var list = shown();
    empty.hidden = list.length > 0;
    grid.innerHTML = list.map(function (x) {
      return '<button class="pcell" type="button" data-src="' + x.src + '" data-name="' + x.name + '">' +
        '<span class="pcell__box"><img src="../' + x.src + '?v=' + x.mtime + '" alt="" loading="lazy"></span>' +
        (x.ph ? '<span class="pcell__tag">仮画像</span>' : '') +
        '<span class="pcell__name">' + x.name + '</span>' +
        '<span class="pcell__meta">' + x.w + '×' + x.h + '</span>' +
      '</button>';
    }).join('');
  }

  function choose(src, name) {
    if (!target) { close(); return; }

    var host  = target.parentElement;
    var value = host.querySelector('[data-pick-value]');
    var view  = host.querySelector('[data-pick-view]');
    var file  = host.querySelector('input[type=file]');

    if (value) value.value = src;
    if (file) file.value = '';                 // 選び直しなので、手元のファイルは外す

    if (view) {
      view.innerHTML =
        '<span class="picked">' +
          '<img src="../' + src + '" alt="">' +
          '<span class="picked__name">' + name + '</span>' +
          '<button class="picked__x" type="button" aria-label="選び直す">&#215;</button>' +
        '</span>';
      view.querySelector('.picked__x').addEventListener('click', function () {
        if (value) value.value = '';
        view.innerHTML = '';
      });
    }
    close();
  }

  function close() {
    box.hidden = true;
    document.body.classList.remove('pick-open');
    if (target) { target.focus(); target = null; }
  }

  buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      if (!box) build();
      target = b;
      box.hidden = false;
      document.body.classList.add('pick-open');
      load();
      search.focus();
    });
  });
})();
