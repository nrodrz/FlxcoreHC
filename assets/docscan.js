/* Mobile document capture & scan (client-side).
 * - Camera capture via a capture=environment input.
 * - HEIC/HEIF from iPhones is converted to JPEG (via heic2any if present, else the browser's
 *   own decode where supported), so the server only ever receives JPEG.
 * - Large photos are downscaled to <= 2200px on the long edge and re-encoded as JPEG ~0.82,
 *   turning an 8 MB photo into ~500-900 KB — faster on cellular, easier on the storage quota.
 * - A light "scan enhance" (grayscale + contrast) for photos of paper.
 * No external network calls; everything runs in the browser. The processed file is placed back
 * on the real upload input, so the existing server endpoint is unchanged.
 */
(function () {
  var MAX_EDGE = 2200, JPEG_Q = 0.82, MAX_BYTES = 10 * 1024 * 1024;

  function each(sel, root, fn) { Array.prototype.forEach.call((root || document).querySelectorAll(sel), fn); }

  function loadImage(blob) {
    return new Promise(function (res, rej) {
      var url = URL.createObjectURL(blob), img = new Image();
      img.onload = function () { URL.revokeObjectURL(url); res(img); };
      img.onerror = function () { URL.revokeObjectURL(url); rej(new Error('decode')); };
      img.src = url;
    });
  }

  function isHeic(file) {
    var n = (file.name || '').toLowerCase();
    return file.type === 'image/heic' || file.type === 'image/heif' || /\.hei[cf]$/.test(n);
  }

  function heicToJpeg(file) {
    // Prefer heic2any if the page bundled it; otherwise try the browser (Safari decodes HEIC).
    if (window.heic2any) {
      return window.heic2any({ blob: file, toType: 'image/jpeg', quality: JPEG_Q })
        .then(function (out) { return out instanceof Blob ? out : out[0]; });
    }
    return loadImage(file).then(function (img) { return draw(img, false); })
      .catch(function () { return file; }); // last resort: send as-is (server will reject if unreadable)
  }

  function draw(img, enhance) {
    var w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
    var scale = Math.min(1, MAX_EDGE / Math.max(w, h));
    var cw = Math.round(w * scale), ch = Math.round(h * scale);
    var c = document.createElement('canvas'); c.width = cw; c.height = ch;
    var ctx = c.getContext('2d');
    ctx.drawImage(img, 0, 0, cw, ch);
    if (enhance) {
      try {
        var d = ctx.getImageData(0, 0, cw, ch), p = d.data, k = 1.28, m = 128 * (1 - k);
        for (var i = 0; i < p.length; i += 4) {
          var g = 0.299 * p[i] + 0.587 * p[i + 1] + 0.114 * p[i + 2];
          g = g * k + m; g = g < 0 ? 0 : g > 255 ? 255 : g;
          p[i] = p[i + 1] = p[i + 2] = g;
        }
        ctx.putImageData(d, 0, 0);
      } catch (e) { /* tainted/large canvas — skip enhancement */ }
    }
    return new Promise(function (res) { c.toBlob(function (b) { res(b); }, 'image/jpeg', JPEG_Q); });
  }

  function processImage(file, enhance) {
    var pre = isHeic(file) ? heicToJpeg(file) : Promise.resolve(file);
    return pre.then(function (blob) { return loadImage(blob); }).then(function (img) { return draw(img, enhance); });
  }

  function setFile(form, blob, name) {
    var input = form.querySelector('[data-doc-file]');
    try {
      var dt = new DataTransfer();
      dt.items.add(new File([blob], name, { type: blob.type || 'image/jpeg', lastModified: Date.now() }));
      input.files = dt.files;
      return true;
    } catch (e) { return false; }
  }

  function kb(n) { return Math.round(n / 1024) + ' KB'; }

  function wire(form) {
    var fileIn = form.querySelector('[data-doc-file]');
    var camIn = form.querySelector('[data-doc-camera]');
    var submit = form.querySelector('[data-doc-submit]');
    var status = form.querySelector('[data-doc-status]');
    var preview = form.querySelector('[data-doc-preview]');
    var busy = false;

    function ready(name, size) {
      submit.disabled = false;
      status.textContent = name + ' — ' + kb(size) + ' ready to attach.';
    }
    function fail(msg) { submit.disabled = true; status.textContent = msg; }

    function handle(file, fromCamera) {
      if (!file) return;
      if (!/^image\//.test(file.type) && !isHeic(file)) {
        // non-image (PDF/DOCX): use as-is, enforce size
        if (file.size > MAX_BYTES) { fail('That file is larger than 10 MB.'); return; }
        preview.style.display = 'none'; preview.innerHTML = '';
        // ensure it's on the real input (camera input feeds a separate element)
        if (fromCamera) setFile(form, file, file.name);
        ready(file.name, file.size);
        return;
      }
      busy = true; submit.disabled = true; status.textContent = 'Processing photo…';
      processImage(file, fromCamera).then(function (blob) {
        busy = false;
        if (blob.size > MAX_BYTES) { fail('Still larger than 10 MB after processing — try a smaller capture.'); return; }
        var base = (file.name || 'scan').replace(/\.[^.]+$/, '') || 'scan';
        if (!setFile(form, blob, base + '.jpg')) { fail('Your browser blocked the processed file; use Choose file instead.'); return; }
        var u = URL.createObjectURL(blob);
        preview.innerHTML = '<img src="' + u + '" alt="preview" style="max-width:180px;max-height:180px;border-radius:8px;border:1px solid var(--line,#ddd)">';
        preview.style.display = '';
        ready(base + '.jpg', blob.size);
      }).catch(function () { busy = false; fail('Could not process that image. Use Choose file to upload the original.'); });
    }

    fileIn.addEventListener('change', function () { handle(fileIn.files[0], false); });
    if (camIn) camIn.addEventListener('change', function () { handle(camIn.files[0], true); });
    form.addEventListener('submit', function (e) { if (busy) e.preventDefault(); });
  }

  function init() { each('[data-doc-upload]', document, wire); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
