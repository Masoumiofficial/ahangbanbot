(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var confirmButton = event.target.closest('[data-ab-confirm]');
        if (confirmButton && !window.confirm(confirmButton.getAttribute('data-ab-confirm'))) {
            event.preventDefault();
            return;
        }

        var copyButton = event.target.closest('[data-ab-copy]');
        if (!copyButton) { return; }
        var target = document.getElementById(copyButton.getAttribute('data-ab-copy'));
        if (!target) { return; }

        var value = (target.textContent || '').trim();
        var status = document.querySelector('[data-ab-copy-status]');
        var finish = function (success) {
            if (status) { status.textContent = success ? 'نشانی کپی شد.' : 'کپی خودکار انجام نشد؛ نشانی را دستی انتخاب کنید.'; }
            copyButton.textContent = success ? 'کپی شد' : 'کپی نشد';
            window.setTimeout(function () { copyButton.textContent = 'کپی نشانی'; }, 1800);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(function () { finish(true); }).catch(function () { finish(false); });
            return;
        }

        var temporary = document.createElement('textarea');
        temporary.value = value;
        temporary.setAttribute('readonly', 'readonly');
        temporary.style.position = 'fixed';
        temporary.style.opacity = '0';
        document.body.appendChild(temporary);
        temporary.select();
        var copied = false;
        try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
        document.body.removeChild(temporary);
        finish(copied);
    });
}());
