/**
 * @file
 * S2 "Upload files": per-file transport over the native file input (D-11).
 *
 * The native <input type="file" multiple> stays the control. This script:
 * - wraps it in a drop zone and lists every chosen file in a row, flagging
 *   files the site can't import (never sent);
 * - on submit, checks the rights box first (D-25b) and sends nothing while
 *   it's unticked;
 * - sends each file in its own XMLHttpRequest to the one-file endpoint, with
 *   the X-CSRF-Token header from /session/token, so PHP's max_file_uploads
 *   and post_max_size never drop a file;
 * - shows per-row progress, "Retry upload", a summary line and "Stop
 *   remaining uploads", with a line saying files already uploaded will
 *   still be added. The summary changes with every file; a screen reader hears
 *   it through the screen's one polite region at most every 5 seconds (the
 *   latest count), then the final count (22-30 Q3);
 * - then fills the hidden fids / not_uploaded / refused fields and submits
 *   the form, which starts the import run.
 *
 * Every node is built with createElement and textContent: file names are
 * untrusted (T-22-39).
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const NAME_LIMIT = 48;
  const LICENCE_LIMIT = 255;

  /**
   * Minimum time between two progress announcements, as on S4 (22-30 Q3).
   */
  const ANNOUNCE_INTERVAL = 5000;

  /**
   * Middle-truncates a name to NAME_LIMIT characters.
   */
  function middleTruncate(name) {
    const chars = Array.from(name);
    if (chars.length <= NAME_LIMIT) {
      return name;
    }
    const keep = NAME_LIMIT - 1;
    const head = Math.ceil(keep / 2);
    const tail = Math.floor(keep / 2);
    return chars.slice(0, head).join('') + '…' + chars.slice(chars.length - tail).join('');
  }

  function extensionOf(name) {
    const dot = name.lastIndexOf('.');
    return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
  }

  function formatSize(bytes) {
    if (bytes >= 1048576) {
      return Drupal.t('@size MB', { '@size': (bytes / 1048576).toFixed(1) });
    }
    if (bytes >= 1024) {
      return Drupal.t('@size KB', { '@size': Math.max(1, Math.round(bytes / 1024)) });
    }
    return Drupal.formatPlural(bytes, '1 byte', '@count bytes');
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  /**
   * An inline SVG icon, hidden from assistive technology.
   */
  function icon(kind) {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '16');
    svg.setAttribute('height', '16');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    svg.setAttribute('class', 'localnodes-import-icon');
    const path = document.createElementNS(ns, 'path');
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', 'currentColor');
    path.setAttribute('stroke-width', '2');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('d', kind === 'close'
      ? 'M6 6l12 12M18 6L6 18'
      : 'M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18M9 9l6 6M15 9l-6 6');
    svg.appendChild(path);
    return svg;
  }

  function Uploader(form, settings) {
    this.form = form;
    this.settings = settings;
    this.entries = [];
    this.queue = [];
    this.uploading = false;
    this.stopped = false;
    this.current = null;
    this.input = form.querySelector('input[type="file"]');
    this.button = form.querySelector('.form-actions [type="submit"]');
    this.rights = form.querySelector('input[name="rights_confirmed"]');
    this.licence = form.querySelector('input[name="license_text"]');
    this.build();
  }

  Uploader.prototype.build = function () {
    const self = this;
    const input = this.input;

    // Drop zone: the input stays the control; the zone has no role.
    this.zone = el('div', 'localnodes-import-dropzone');
    input.parentNode.insertBefore(this.zone, input);
    this.zone.appendChild(el('span', 'localnodes-import-dropzone__text', Drupal.t('Drop files here, or')));
    this.zone.appendChild(input);
    ['dragenter', 'dragover'].forEach(function (type) {
      self.zone.addEventListener(type, function (event) {
        event.preventDefault();
        self.zone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'dragend'].forEach(function (type) {
      self.zone.addEventListener(type, function () {
        self.zone.classList.remove('is-dragover');
      });
    });
    this.zone.addEventListener('drop', function (event) {
      event.preventDefault();
      self.zone.classList.remove('is-dragover');
      if (!self.uploading && event.dataTransfer && event.dataTransfer.files) {
        self.addFiles(event.dataTransfer.files);
      }
    });
    input.addEventListener('change', function () {
      self.addFiles(input.files);
      // The list holds the files now; the input posts nothing itself.
      input.value = '';
    });

    // The chosen-files panel, after the file field.
    const item = input.closest('.form-item') || this.zone;
    this.panel = el('div', 'localnodes-import-chosen');
    this.panel.hidden = true;
    this.count = el('p', 'localnodes-import-chosen__count');
    this.note = el('p', 'localnodes-import-chosen__note');
    this.note.hidden = true;
    const progress = el('div', 'localnodes-import-chosen__progress');
    this.summary = el('p', 'localnodes-import-chosen__summary');
    this.summary.setAttribute('tabindex', '-1');
    this.stop = el('button', 'button btn btn-default localnodes-import-chosen__stop', Drupal.t('Stop remaining uploads'));
    this.stop.type = 'button';
    this.stop.hidden = true;
    this.stop.addEventListener('click', function () {
      self.stopUploading();
    });
    // Says what stopping keeps, before anyone presses it; the button names
    // this line as its description.
    this.stopNote = el('p', 'localnodes-import-chosen__stop-note', Drupal.t('Files already uploaded will still be added.'));
    this.stopNote.id = 'localnodes-import-stop-note';
    this.stopNote.hidden = true;
    this.stop.setAttribute('aria-describedby', this.stopNote.id);
    progress.appendChild(this.summary);
    progress.appendChild(this.stop);
    progress.appendChild(this.stopNote);
    this.list = el('ul', 'localnodes-import-chosen__list');
    this.panel.appendChild(this.count);
    this.panel.appendChild(this.note);
    this.panel.appendChild(progress);
    this.panel.appendChild(this.list);
    item.parentNode.insertBefore(this.panel, item.nextSibling);

    // The screen's one polite region: always on the page (a region inside
    // the hidden panel wouldn't be heard), empty until something changes.
    this.live = el('div', 'visually-hidden');
    this.live.setAttribute('aria-live', 'polite');
    this.live.setAttribute('data-ln-upload-live', '');
    this.panel.parentNode.insertBefore(this.live, this.panel);
    this.lastAnnounced = 0;
    this.announcedText = '';
    this.pendingText = null;
    this.announceTimer = null;

    this.spinner = el('span', 'localnodes-import-spinner');
    this.spinner.setAttribute('aria-hidden', 'true');
    this.spinner.hidden = true;
    if (this.button) {
      this.button.insertAdjacentElement('afterend', this.spinner);
    }

    this.form.addEventListener('submit', function (event) {
      if (self.form.dataset.lnSubmit === 'go') {
        return;
      }
      event.preventDefault();
      if (!self.uploading) {
        self.onSubmit();
      }
    });
  };

  /**
   * Sets the primary button's words.
   *
   * socialbase renders submits as <button>; its value stays the original
   * label so Drupal still recognises the triggering element.
   */
  Uploader.prototype.setButtonLabel = function (text) {
    if (!this.button) {
      return;
    }
    if (this.button.tagName === 'BUTTON') {
      this.button.textContent = text;
    }
    else {
      this.button.value = text;
    }
  };

  Uploader.prototype.refusalFor = function (file) {
    if (this.settings.extensions.indexOf(extensionOf(file.name)) === -1) {
      return 'unsupported_type';
    }
    if (file.size === 0) {
      return 'empty';
    }
    if (file.size > this.settings.maxBytes) {
      return 'too_big';
    }
    return null;
  };

  Uploader.prototype.refusalMessage = function (reason, name) {
    if (reason === 'too_big') {
      return Drupal.t('This file is bigger than @limit MB. Make it smaller or split it, then add it again.', { '@limit': this.settings.limitMb });
    }
    if (reason === 'empty') {
      return Drupal.t('This file is empty.');
    }
    if (this.settings.spreadsheetExtensions.indexOf(extensionOf(name)) !== -1) {
      return Drupal.t("Spreadsheets and slides can't be imported yet. Save it as a PDF, then add it again.");
    }
    return Drupal.t("This type of file can't be imported. Choose a PDF, Word, OpenDocument, text or Markdown file.");
  };

  Uploader.prototype.addFiles = function (files) {
    const skipped = [];
    for (let i = 0; i < files.length; i++) {
      const file = files[i];
      const already = this.entries.some(function (entry) {
        return entry.name === file.name && entry.size === file.size;
      });
      if (already) {
        skipped.push(file.name);
        continue;
      }
      const entry = {
        file: file,
        name: file.name,
        size: file.size,
        refusal: this.refusalFor(file),
        state: 'chosen',
        fid: null,
      };
      this.entries.push(entry);
      this.list.appendChild(this.buildRow(entry));
    }
    if (skipped.length) {
      this.note.textContent = Drupal.t('Already in your list') + ': ' + skipped.map(middleTruncate).join(', ');
      this.note.hidden = false;
    }
    else {
      this.note.hidden = true;
    }
    this.update();
    // Choosing files changes the page without a reload, and focus stays on
    // "Choose files", so say what changed (UI-SPEC Part 5; 22-30 SR-07):
    // the count line, and any names skipped as already in the list.
    if (this.count.textContent) {
      this.say(this.count.textContent + (this.note.hidden ? '' : ' ' + this.note.textContent));
    }
  };

  /**
   * Puts text in the screen's polite region now.
   */
  Uploader.prototype.say = function (text) {
    this.live.textContent = text;
    this.lastAnnounced = Date.now();
    this.announcedText = text;
  };

  /**
   * Announces upload progress at most every 5 seconds (22-30 Q3).
   *
   * Only the latest text waiting is kept, so a burst of files is heard once,
   * with the newest count. The visible summary still changes every file.
   */
  Uploader.prototype.announceProgress = function (text) {
    const self = this;
    if (text === this.announcedText) {
      return;
    }
    this.pendingText = text;
    const wait = this.lastAnnounced + ANNOUNCE_INTERVAL - Date.now();
    if (wait <= 0) {
      this.flushProgress();
    }
    else if (this.announceTimer === null) {
      this.announceTimer = window.setTimeout(function () {
        self.flushProgress();
      }, wait);
    }
  };

  Uploader.prototype.flushProgress = function () {
    if (this.announceTimer !== null) {
      window.clearTimeout(this.announceTimer);
    }
    this.announceTimer = null;
    if (this.pendingText !== null) {
      this.say(this.pendingText);
      this.pendingText = null;
    }
  };

  Uploader.prototype.buildRow = function (entry) {
    const self = this;
    const row = el('li', 'localnodes-import-chosen__row');
    entry.row = row;

    const name = el('span', 'localnodes-import-chosen__name');
    name.title = entry.name;
    const visible = el('span', '', middleTruncate(entry.name));
    visible.setAttribute('aria-hidden', 'true');
    name.appendChild(visible);
    name.appendChild(el('span', 'visually-hidden', entry.name));
    row.appendChild(name);
    row.appendChild(el('span', 'localnodes-import-chosen__size', formatSize(entry.size)));

    if (entry.refusal) {
      row.classList.add('is-refused');
      const badge = el('span', 'localnodes-import-badge localnodes-import-badge--danger');
      badge.appendChild(icon('warning'));
      badge.appendChild(el('span', '', Drupal.t("Can't import")));
      row.appendChild(badge);
      row.appendChild(el('p', 'localnodes-import-chosen__reason', this.refusalMessage(entry.refusal, entry.name)));
    }
    else {
      entry.status = el('span', 'localnodes-import-chosen__status');
      row.appendChild(entry.status);
      entry.bar = el('span', 'localnodes-import-progress');
      entry.bar.setAttribute('aria-hidden', 'true');
      entry.fill = el('span', 'localnodes-import-progress__fill');
      entry.bar.appendChild(entry.fill);
      entry.bar.hidden = true;
      row.appendChild(entry.bar);
      entry.error = el('p', 'localnodes-import-chosen__reason');
      entry.error.hidden = true;
      row.appendChild(entry.error);
      entry.retry = el('button', 'button btn btn-default localnodes-import-chosen__retry');
      entry.retry.type = 'button';
      entry.retry.appendChild(el('span', '', Drupal.t('Retry upload')));
      entry.retry.setAttribute('aria-label', Drupal.t('Retry upload of @name', { '@name': entry.name }));
      entry.retry.hidden = true;
      entry.retry.addEventListener('click', function () {
        self.retry(entry);
      });
      row.appendChild(entry.retry);
    }

    entry.remove = el('button', 'localnodes-import-chosen__remove');
    entry.remove.type = 'button';
    entry.remove.appendChild(icon('close'));
    entry.remove.appendChild(el('span', 'visually-hidden', Drupal.t('Remove @name', { '@name': entry.name })));
    entry.remove.addEventListener('click', function () {
      self.removeEntry(entry);
    });
    row.appendChild(entry.remove);
    return row;
  };

  Uploader.prototype.removeEntry = function (entry) {
    if (this.uploading) {
      return;
    }
    const index = this.entries.indexOf(entry);
    const next = this.entries[index + 1] || this.entries[index - 1];
    this.entries.splice(index, 1);
    entry.row.remove();
    this.update();
    // Keep focus in the list, or return it to the input.
    (next ? next.remove : this.input).focus();
  };

  Uploader.prototype.importable = function () {
    return this.entries.filter(function (entry) {
      return !entry.refusal;
    });
  };

  Uploader.prototype.update = function () {
    const total = this.entries.length;
    const importable = this.importable().length;
    this.panel.hidden = total === 0 && this.note.hidden;
    this.count.textContent = total === 0 ? '' : Drupal.formatPlural(total, '1 file chosen.', '@count files chosen.') + ' '
      + Drupal.formatPlural(importable, '1 can be imported.', '@count can be imported.');
    if (!this.uploading) {
      this.setButtonLabel(importable === 0
        ? Drupal.t('Import files')
        : Drupal.formatPlural(importable, 'Import 1 file', 'Import @count files'));
    }
  };

  Uploader.prototype.clearErrors = function () {
    this.form.querySelectorAll('[data-ln-error-summary]').forEach(function (node) {
      node.remove();
    });
    this.form.querySelectorAll('.localnodes-import-field-error').forEach(function (node) {
      node.remove();
    });
    this.form.querySelectorAll('[aria-invalid="true"]').forEach(function (node) {
      node.removeAttribute('aria-invalid');
      node.removeAttribute('aria-describedby');
    });
  };

  /**
   * Builds the "Fix these to continue" summary, as the server renders it.
   */
  Uploader.prototype.showErrors = function (errors) {
    const summary = el('div', 'localnodes-import-error-summary');
    summary.setAttribute('data-ln-error-summary', '');
    summary.setAttribute('tabindex', '-1');
    summary.setAttribute('aria-labelledby', 'localnodes-import-error-summary-heading');
    const heading = el('h2', '', Drupal.t('Fix these to continue'));
    heading.id = 'localnodes-import-error-summary-heading';
    summary.appendChild(heading);
    const list = el('ul');
    errors.forEach(function (error) {
      const li = el('li');
      const link = el('a', '', error.message);
      link.href = '#' + error.field.id;
      li.appendChild(link);
      list.appendChild(li);

      // The field's own message, under it.
      const message = el('div', 'localnodes-import-field-error form-item--error-message', error.message);
      message.id = error.field.id + '--ln-error';
      const item = error.field.closest('.form-item') || error.field;
      item.appendChild(message);
      error.field.setAttribute('aria-invalid', 'true');
      error.field.setAttribute('aria-describedby', message.id);
    });
    summary.appendChild(list);
    this.form.insertBefore(summary, this.form.firstChild);
    summary.focus();
  };

  Uploader.prototype.onSubmit = function () {
    this.clearErrors();
    const errors = [];
    if (this.entries.length === 0) {
      errors.push({ field: this.input, message: Drupal.t('Choose at least one file to import.') });
    }
    else if (this.importable().length === 0) {
      errors.push({ field: this.input, message: Drupal.t('None of these files can be imported. Choose PDF, Word, OpenDocument, text or Markdown files.') });
    }
    if (this.licence && Array.from(this.licence.value.trim()).length > LICENCE_LIMIT) {
      errors.push({ field: this.licence, message: Drupal.t('Shorten the licence to 255 characters or fewer.') });
    }
    // D-25b: nothing is sent until the box is ticked.
    if (!this.rights || !this.rights.checked) {
      errors.push({ field: this.rights, message: Drupal.t('Confirm you have the right to share these here before importing.') });
    }
    if (errors.length) {
      this.showErrors(errors);
      return;
    }
    this.start();
  };

  Uploader.prototype.start = function () {
    const self = this;
    this.uploading = true;
    this.stopped = false;
    this.input.disabled = true;
    this.button.disabled = true;
    this.setButtonLabel(Drupal.t('Uploading…'));
    this.spinner.hidden = false;
    this.stop.hidden = false;
    this.stopNote.hidden = false;
    this.entries.forEach(function (entry) {
      entry.remove.hidden = true;
    });
    this.queue = this.importable();
    this.total = this.queue.length;
    this.done = 0;
    this.queue.forEach(function (entry) {
      self.setState(entry, 'waiting');
    });
    this.summary.textContent = Drupal.t('Uploading @i of @n', { '@i': 1, '@n': this.total });
    // Focus reads the first count; it counts as the first announcement.
    this.summary.focus();
    this.lastAnnounced = Date.now();
    this.announcedText = this.summary.textContent;

    fetch(this.settings.tokenUrl, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('token');
        }
        return response.text();
      })
      .then(function (token) {
        self.token = token;
        self.next();
      })
      .catch(function () {
        self.queue.forEach(function (entry) {
          self.setState(entry, 'failed');
        });
        self.queue = [];
        self.finish();
      });
  };

  Uploader.prototype.next = function () {
    if (this.stopped) {
      return;
    }
    const entry = this.queue.shift();
    if (!entry) {
      this.finish();
      return;
    }
    this.done += 1;
    this.summary.textContent = Drupal.t('Uploading @i of @n', { '@i': Math.min(this.done, this.total), '@n': this.total });
    this.announceProgress(this.summary.textContent);
    this.send(entry);
  };

  Uploader.prototype.send = function (entry) {
    const self = this;
    const xhr = new XMLHttpRequest();
    this.current = { entry: entry, xhr: xhr };
    const data = new FormData();
    data.append('file', entry.file, entry.name);
    xhr.open('POST', this.settings.url);
    xhr.setRequestHeader('X-CSRF-Token', this.token);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.onprogress = function (event) {
      if (event.lengthComputable) {
        self.setState(entry, 'uploading', Math.round((event.loaded / event.total) * 100));
      }
    };
    xhr.onload = function () {
      let body = null;
      try {
        body = JSON.parse(xhr.responseText);
      }
      catch (e) {
        body = null;
      }
      if (xhr.status === 200 && body && typeof body.fid === 'number') {
        entry.fid = body.fid;
        self.setState(entry, 'uploaded');
      }
      else {
        self.setState(entry, 'failed', null, body && body.error ? body.error : '');
      }
      self.current = null;
      self.next();
    };
    xhr.onerror = function () {
      self.setState(entry, 'failed');
      self.current = null;
      self.next();
    };
    xhr.onabort = function () {
      self.current = null;
    };
    this.setState(entry, 'uploading', 0);
    xhr.send(data);
  };

  Uploader.prototype.retry = function (entry) {
    if (!this.uploading || this.stopped || entry.state !== 'failed') {
      return;
    }
    this.total += 1;
    this.setState(entry, 'waiting');
    this.queue.push(entry);
  };

  Uploader.prototype.setState = function (entry, state, percent, message) {
    entry.state = state;
    entry.row.dataset.state = state;
    entry.bar.hidden = state !== 'uploading' && state !== 'uploaded';
    entry.retry.hidden = !(state === 'failed' && this.uploading && !this.stopped);
    entry.error.hidden = state !== 'failed' || !message;
    if (message) {
      entry.error.textContent = message;
    }
    let text = '';
    let width = 0;
    switch (state) {
      case 'waiting':
        text = Drupal.t('Waiting');
        break;

      case 'uploading':
        width = percent || 0;
        text = Drupal.t('Uploading @p%', { '@p': width });
        break;

      case 'uploaded':
        width = 100;
        text = Drupal.t('Uploaded');
        break;

      case 'failed':
        text = Drupal.t("Couldn't upload");
        break;

      case 'stopped':
        text = Drupal.t('Not uploaded: you stopped the upload.');
        break;
    }
    entry.status.textContent = text;
    entry.fill.style.width = width + '%';
  };

  Uploader.prototype.stopUploading = function () {
    const self = this;
    this.stopped = true;
    if (this.current) {
      const entry = this.current.entry;
      this.current.xhr.abort();
      this.current = null;
      this.setState(entry, 'stopped');
    }
    this.queue.forEach(function (entry) {
      self.setState(entry, 'stopped');
    });
    this.queue = [];
    this.finish();
  };

  /**
   * Fills the hidden fields and submits: the import run starts on its own.
   */
  Uploader.prototype.finish = function () {
    if (this.finished) {
      return;
    }
    this.finished = true;
    this.stop.hidden = true;
    this.stopNote.hidden = true;
    const fids = [];
    const notUploaded = [];
    const refused = [];
    this.entries.forEach(function (entry) {
      if (entry.refusal) {
        refused.push({ name: entry.name, reason: entry.refusal });
      }
      else if (entry.state === 'uploaded' && entry.fid) {
        fids.push(entry.fid);
      }
      else {
        notUploaded.push({ name: entry.name, reason: entry.state === 'failed' ? 'failed' : 'stopped' });
      }
    });
    this.form.querySelector('input[name="fids"]').value = fids.join(',');
    this.form.querySelector('input[name="not_uploaded"]').value = JSON.stringify(notUploaded);
    this.form.querySelector('input[name="refused"]').value = JSON.stringify(refused);
    // The final count, now, whatever the 5-second wait: the latest count
    // if it wasn't heard yet, then that the import is starting.
    const latest = this.pendingText !== null ? this.pendingText : (this.announcedText !== this.summary.textContent ? this.summary.textContent : '');
    this.pendingText = null;
    this.flushProgress();
    const starting = Drupal.t('Starting the import');
    this.say(latest ? latest + '. ' + starting : starting);
    this.summary.textContent = starting;
    this.form.dataset.lnSubmit = 'go';
    // A disabled button isn't posted; Drupal then uses the form's only
    // button as the triggering element.
    this.form.submit();
  };

  Drupal.behaviors.localnodesImportUpload = {
    attach: function (context) {
      const settings = drupalSettings.localnodes_import && drupalSettings.localnodes_import.upload;
      if (settings) {
        once('localnodes-import-upload', 'form.localnodes-import-upload', context).forEach(function (form) {
          form.localnodesImportUploader = new Uploader(form, settings);
        });
      }
      // After a failed server-side submit, focus moves to the summary.
      once('localnodes-import-error-summary', '[data-ln-error-summary]', context).forEach(function (summary) {
        summary.focus();
      });
    },
  };

})(Drupal, drupalSettings, once);
