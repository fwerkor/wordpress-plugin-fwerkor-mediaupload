(() => {
  'use strict';
  const config = window.FWERKOR_MEDIAUPLOAD;
  const input = document.getElementById('fwerkor-mediaupload-file');
  const drop = document.getElementById('fwerkor-mediaupload-drop');
  const list = document.getElementById('fwerkor-mediaupload-list');
  if (!config || !input || !drop || !list) return;

  let busy = false;

  const id = () => {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  };

  const row = (file) => {
    const el = document.createElement('div');
    el.style.cssText = 'padding:12px 0;border-top:1px solid #eee';
    el.innerHTML = '<strong></strong><div style="height:7px;background:#eee;border-radius:999px;margin:8px 0;overflow:hidden"><i style="display:block;height:100%;width:0;background:#2271b1"></i></div><small></small>';
    el.querySelector('strong').textContent = file.name;
    list.appendChild(el);
    return el;
  };

  const upload = async (file) => {
    const el = row(file);
    const bar = el.querySelector('i');
    const status = el.querySelector('small');

    if (file.size > config.maxBytes) {
      status.textContent = 'Too large for the configured limit.';
      status.style.color = '#b32d2e';
      return;
    }

    const uploadId = id();
    const total = Math.ceil(file.size / config.chunkSize);

    for (let index = 0; index < total; index++) {
      const start = index * config.chunkSize;
      const blob = file.slice(start, Math.min(file.size, start + config.chunkSize));
      const form = new FormData();
      form.append('upload_id', uploadId);
      form.append('index', String(index));
      form.append('total', String(total));
      form.append('filename', file.name);
      form.append('filesize', String(file.size));
      form.append('chunk', blob, 'chunk');

      const response = await fetch(config.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': config.nonce },
        body: form
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(data?.message || ('Upload failed: HTTP ' + response.status));
      }

      const progress = Math.round(((index + 1) / total) * 100);
      bar.style.width = progress + '%';
      status.textContent = progress + '%';
      if (data.complete) {
        status.innerHTML = 'Uploaded · <a href="' + data.editUrl + '">Edit media</a>';
      }
    }
  };

  const run = async (files) => {
    if (busy) return;
    busy = true;
    input.disabled = true;
    try {
      for (const file of files) {
        try {
          await upload(file);
        } catch (error) {
          const last = list.lastElementChild;
          if (last) {
            const status = last.querySelector('small');
            status.textContent = error.message || String(error);
            status.style.color = '#b32d2e';
          }
        }
      }
    } finally {
      busy = false;
      input.disabled = false;
      input.value = '';
    }
  };

  input.addEventListener('change', () => run(Array.from(input.files || [])));
  ['dragenter', 'dragover'].forEach(type => drop.addEventListener(type, e => {
    e.preventDefault();
    drop.style.borderColor = '#2271b1';
  }));
  ['dragleave', 'drop'].forEach(type => drop.addEventListener(type, e => {
    e.preventDefault();
    drop.style.borderColor = '#c3c4c7';
  }));
  drop.addEventListener('drop', e => run(Array.from(e.dataTransfer?.files || [])));
})();
