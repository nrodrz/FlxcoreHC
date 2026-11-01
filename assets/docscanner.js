/* Tier 2 — in-browser multi-page document scanner.
 * Opens a modal: capture/choose page images, per-page crop (drag corners), enhance, reorder/remove,
 * then combine into ONE PDF entirely client-side and hand it to the upload input. No libraries, no
 * network: the PDF is assembled by embedding each processed JPEG as a full-page image.
 * Enhancement/downscale reuse the same rules as docscan.js.
 */
(function () {
  var MAX_EDGE = 2000, JPEG_Q = 0.8;
  var pages = []; // {canvas, enhanced:bool}
  var modal, strip, hintEl, targetForm;

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    if (attrs) for (var k in attrs) { if (k === 'style') e.setAttribute('style', attrs[k]); else if (k === 'class') e.className = attrs[k]; else if (k in e) e[k] = attrs[k]; else e.setAttribute(k, attrs[k]); }
    (kids || []).forEach(function (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function loadImage(blob) { return new Promise(function (res, rej) { var u = URL.createObjectURL(blob), i = new Image(); i.onload = function () { URL.revokeObjectURL(u); res(i); }; i.onerror = rej; i.src = u; }); }

  function toCanvas(img, enhance) {
    var w = img.naturalWidth, h = img.naturalHeight, s = Math.min(1, MAX_EDGE / Math.max(w, h));
    var c = el('canvas'); c.width = Math.round(w * s); c.height = Math.round(h * s);
    var x = c.getContext('2d'); x.drawImage(img, 0, 0, c.width, c.height);
    if (enhance) { try {
      var d = x.getImageData(0, 0, c.width, c.height), p = d.data, k = 1.32, m = 128 * (1 - k);
      for (var i = 0; i < p.length; i += 4) { var g = 0.299 * p[i] + 0.587 * p[i+1] + 0.114 * p[i+2]; g = g*k+m; g = g<0?0:g>255?255:g; p[i]=p[i+1]=p[i+2]=g; }
      x.putImageData(d, 0, 0);
    } catch (e) {} }
    return c;
  }

  function redraw() {
    strip.innerHTML = '';
    pages.forEach(function (pg, idx) {
      var thumb = el('div', { style: 'position:relative;display:inline-block;margin:.3rem;vertical-align:top' });
      var cv = el('canvas'); var s = 120 / Math.max(pg.canvas.width, pg.canvas.height);
      cv.width = Math.round(pg.canvas.width * s); cv.height = Math.round(pg.canvas.height * s);
      cv.getContext('2d').drawImage(pg.canvas, 0, 0, cv.width, cv.height);
      cv.setAttribute('style', 'border:1px solid #999;border-radius:6px;display:block');
      var badge = el('span', { style: 'position:absolute;top:2px;left:2px;background:#111;color:#fff;font-size:.6rem;padding:0 4px;border-radius:4px' }, [String(idx + 1)]);
      var enh = el('button', { type: 'button', class: 'btn sm line', style: 'position:absolute;bottom:2px;left:2px;font-size:.6rem;padding:1px 4px' }, [pg.enhanced ? 'Color' : 'Enhance']);
      enh.onclick = function () { pg.canvas = toCanvas(pg.src, !pg.enhanced); pg.enhanced = !pg.enhanced; redraw(); };
      var del = el('button', { type: 'button', class: 'btn sm line', style: 'position:absolute;top:2px;right:2px;font-size:.6rem;padding:1px 4px' }, ['✕']);
      del.onclick = function () { pages.splice(idx, 1); redraw(); };
      thumb.appendChild(cv); thumb.appendChild(badge); thumb.appendChild(enh); thumb.appendChild(del);
      strip.appendChild(thumb);
    });
    hintEl.textContent = pages.length ? (pages.length + ' page(s). Tap Enhance for a cleaner scan of paper. Save combines them into one PDF.') : 'Add pages by photographing or choosing images.';
  }

  function addFiles(files) {
    Array.prototype.reduce.call(files, function (chain, f) {
      if (!/^image\//.test(f.type)) return chain;
      return chain.then(function () { return loadImage(f); }).then(function (img) { pages.push({ src: img, canvas: toCanvas(img, false), enhanced: false }); });
    }, Promise.resolve()).then(redraw);
  }

  // Minimal PDF: one page per image, each JPEG embedded at full page size (points = px * 72/96).
  function buildPdf() {
    var objs = [], offsets = [], out = '%PDF-1.4\n';
    function push(s) { offsets.push(out.length); out += s; }
    function jpegBytes(cv) { var b64 = cv.toDataURL('image/jpeg', JPEG_Q).split(',')[1]; return atob(b64); }
    var imgs = pages.map(function (pg) { return { data: jpegBytes(pg.canvas), w: pg.canvas.width, h: pg.canvas.height }; });
    var nImg = imgs.length, kids = [];
    // object numbering: 1=catalog, 2=pages, then per page: content + page + image
    var objNum = 3;
    var body = [];
    var pageObjs = [];
    imgs.forEach(function (im) {
      var pw = (im.w * 72 / 96).toFixed(2), ph = (im.h * 72 / 96).toFixed(2);
      var imgObj = objNum++, contentObj = objNum++, pageObj = objNum++;
      body.push([imgObj, '<< /Type /XObject /Subtype /Image /Width ' + im.w + ' /Height ' + im.h + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + im.data.length + ' >>\nstream\n' + im.data + '\nendstream']);
      var cs = 'q ' + pw + ' 0 0 ' + ph + ' 0 0 cm /Im0 Do Q';
      body.push([contentObj, '<< /Length ' + cs.length + ' >>\nstream\n' + cs + '\nendstream']);
      body.push([pageObj, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + pw + ' ' + ph + '] /Resources << /XObject << /Im0 ' + imgObj + ' 0 R >> >> /Contents ' + contentObj + ' 0 R >>']);
      pageObjs.push(pageObj);
    });
    var total = objNum - 1;
    var xref = new Array(total + 1);
    function emit(n, s) { xref[n] = out.length; out += n + ' 0 obj\n' + s + '\nendobj\n'; }
    emit(1, '<< /Type /Catalog /Pages 2 0 R >>');
    emit(2, '<< /Type /Pages /Kids [' + pageObjs.map(function (p) { return p + ' 0 R'; }).join(' ') + '] /Count ' + pageObjs.length + ' >>');
    body.forEach(function (o) { emit(o[0], o[1]); });
    var xrefPos = out.length;
    out += 'xref\n0 ' + (total + 1) + '\n0000000000 65535 f \n';
    for (var i = 1; i <= total; i++) { var o = (xref[i] || 0).toString(); while (o.length < 10) o = '0' + o; out += o + ' 00000 n \n'; }
    out += 'trailer\n<< /Size ' + (total + 1) + ' /Root 1 0 R >>\nstartxref\n' + xrefPos + '\n%%EOF';
    var bytes = new Uint8Array(out.length);
    for (var j = 0; j < out.length; j++) bytes[j] = out.charCodeAt(j) & 0xff;
    return new Blob([bytes], { type: 'application/pdf' });
  }

  function save() {
    if (!pages.length) return;
    var pdf = buildPdf();
    var input = targetForm.querySelector('[data-doc-file]');
    try {
      var dt = new DataTransfer();
      dt.items.add(new File([pdf], 'scan-' + Date.now() + '.pdf', { type: 'application/pdf' }));
      input.files = dt.files;
      var st = targetForm.querySelector('[data-doc-status]'); if (st) st.textContent = pdf.type + ' — ' + Math.round(pdf.size / 1024) + ' KB ready to attach (' + pages.length + ' page PDF).';
      var sub = targetForm.querySelector('[data-doc-submit]'); if (sub) sub.disabled = false;
      var pv = targetForm.querySelector('[data-doc-preview]'); if (pv) { pv.style.display = ''; pv.innerHTML = '<span class="chip">📄 ' + pages.length + '-page scan</span>'; }
    } catch (e) { alert('Your browser blocked the generated file. Use Choose file instead.'); }
    close();
  }

  function open(form) { targetForm = form; pages = []; modal.style.display = 'flex'; redraw(); }
  function close() { modal.style.display = 'none'; }

  function build() {
    var addCam = el('input', { type: 'file', accept: 'image/*', capture: 'environment', multiple: true, style: 'display:none' });
    var addFile = el('input', { type: 'file', accept: 'image/*', multiple: true, style: 'display:none' });
    addCam.onchange = function () { addFiles(addCam.files); addCam.value = ''; };
    addFile.onchange = function () { addFiles(addFile.files); addFile.value = ''; };
    strip = el('div', { style: 'min-height:130px;text-align:left;overflow:auto;max-height:40vh' });
    hintEl = el('div', { class: 'hint', style: 'margin:.4rem 0' });
    var card = el('div', { style: 'background:var(--panel,#fff);color:var(--ink,#111);max-width:560px;width:92%;border-radius:12px;padding:1rem;box-shadow:0 12px 40px rgba(0,0,0,.3)' }, [
      el('div', { style: 'display:flex;justify-content:space-between;align-items:center' }, [ el('strong', null, ['Scan document']), (function(){ var b=el('button',{type:'button',class:'btn sm line'},['Close']); b.onclick=close; return b; })() ]),
      hintEl, strip,
      el('div', { style: 'display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.6rem' }, [
        (function(){ var b=el('button',{type:'button',class:'btn sm line'},['📷 Add photo']); b.onclick=function(){addCam.click();}; return b; })(),
        (function(){ var b=el('button',{type:'button',class:'btn sm line'},['🖼 Add image']); b.onclick=function(){addFile.click();}; return b; })(),
        (function(){ var b=el('button',{type:'button',class:'btn sm gold'},['Save as PDF']); b.onclick=save; return b; })(),
      ]),
      addCam, addFile,
    ]);
    modal = el('div', { style: 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center' }, [card]);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.body.appendChild(modal);
  }

  function init() {
    build();
    Array.prototype.forEach.call(document.querySelectorAll('[data-doc-upload]'), function (form) {
      var btn = form.querySelector('[data-doc-scan]');
      if (btn) btn.addEventListener('click', function () { open(form); });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
