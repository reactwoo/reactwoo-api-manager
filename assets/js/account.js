(function () {
  'use strict';

  function getConfig() {
    return window.reactwooAccount || {};
  }

  function setButtonLabel(button, label) {
    if (button) {
      button.textContent = label;
    }
  }

  async function copyKey(button) {
    var cfg = getConfig();
    var subscriptionId = button.getAttribute('data-rw-copy-key');
    var i18n = cfg.i18n || {};
    if (!subscriptionId || !cfg.restUrl || !cfg.nonce) {
      window.alert(i18n.unavailable || 'Key temporarily unavailable.');
      return;
    }

    var original = button.textContent;
    button.disabled = true;
    setButtonLabel(button, i18n.pending || '…');

    try {
      var response = await fetch(cfg.restUrl + encodeURIComponent(subscriptionId) + '/key', {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'X-WP-Nonce': cfg.nonce,
          Accept: 'application/json',
        },
        cache: 'no-store',
      });

      if (!response.ok) {
        throw new Error('copy_failed');
      }

      var data = await response.json();
      var key = data && data.license_key ? String(data.license_key) : '';
      if (!key) {
        throw new Error('empty_key');
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(key);
      } else {
        var input = document.createElement('textarea');
        input.value = key;
        input.setAttribute('readonly', '');
        input.style.position = 'absolute';
        input.style.left = '-9999px';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
      }

      setButtonLabel(button, i18n.copied || 'Copied');
      window.setTimeout(function () {
        setButtonLabel(button, original);
        button.disabled = false;
      }, 1600);
    } catch (err) {
      setButtonLabel(button, original);
      button.disabled = false;
      window.alert(i18n.copyFailed || 'Could not copy key. Please try again.');
    }
  }

  function onClick(event) {
    var button = event.target.closest('[data-rw-copy-key]');
    if (!button) {
      return;
    }
    event.preventDefault();
    copyKey(button);
  }

  document.addEventListener('click', onClick);
})();
