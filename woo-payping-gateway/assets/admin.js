/*
 * PayPing refund admin helpers: the live status card on the gateway
 * settings page and the amount-plus-wage confirmation shown before a
 * WooCommerce refund is sent to PayPing.
 */
(function () {
    'use strict';

    var cfg = window.paypingRefundAdmin || {};
    var i18n = cfg.i18n || {};

    function post(action, fields) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', cfg.nonce || '');
        Object.keys(fields || {}).forEach(function (key) {
            body.append(key, fields[key]);
        });

        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }).then(function (response) {
            return response.json();
        });
    }

    // Settings page: refresh the refund card without reloading.

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('.payping-refund-check') : null;
        if (!button) {
            return;
        }
        event.preventDefault();
        if (button.disabled) {
            return;
        }

        var card = button.closest('.payping-refund-card');
        var label = button.textContent;
        button.disabled = true;
        button.textContent = i18n.checking || '...';

        post('woo_payping_refund_check', {}).then(function (res) {
            if (res && res.success && res.data && res.data.html && card) {
                card.outerHTML = res.data.html;
                return;
            }
            button.disabled = false;
            button.textContent = label;
            window.alert((res && res.data && res.data.message) || i18n.checkFailed || '');
        }).catch(function () {
            button.disabled = false;
            button.textContent = label;
            window.alert(i18n.networkError || '');
        });
    });

    if (!cfg.confirmEnabled) {
        return;
    }

    // Order page: confirm the amount plus the wallet wage before Woo
    // fires the refund call. Runs in the capture phase so it sits in
    // front of the WooCommerce handler.

    var passThrough = false;

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('button.do-api-refund') : null;
        if (!button || passThrough) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        openDialog(button);
    }, true);

    function refundAmount() {
        var input = document.getElementById('refund_amount');
        var value = input ? input.value : '';
        // Let Woo's accounting helper strip the localized separators.
        if (value && window.accounting && window.woocommerce_admin) {
            try {
                value = window.accounting.unformat(value, window.woocommerce_admin.mon_decimal_point);
            } catch (e) {
                /* fall back to the raw value */
            }
        }
        return String(value || '').trim();
    }

    function openDialog(refundButton) {
        var overlay = document.createElement('div');
        overlay.className = 'payping-refund-dialog';
        overlay.dir = 'rtl';
        overlay.innerHTML =
            '<div class="payping-refund-dialog-box" role="dialog" aria-modal="true">' +
            '<h3>' + (i18n.title || '') + '</h3>' +
            '<ul class="payping-refund-dialog-rows">' +
            '<li data-row="order"><span>' + (i18n.orderLabel || '') + '</span><strong>' + (i18n.loading || '...') + '</strong></li>' +
            '<li data-row="amount"><span>' + (i18n.amountLabel || '') + '</span><strong>' + (i18n.loading || '...') + '</strong></li>' +
            '<li data-row="wage"><span>' + (i18n.wageLabel || '') + '</span><strong>' + (i18n.loading || '...') + '</strong></li>' +
            '<li data-row="total" class="payping-refund-dialog-total"><span>' + (i18n.totalLabel || '') + '</span><strong>' + (i18n.loading || '...') + '</strong></li>' +
            '</ul>' +
            '<p class="payping-refund-dialog-note">' + (i18n.note || '') + '</p>' +
            '<div class="payping-refund-dialog-actions">' +
            '<button type="button" class="button button-primary payping-refund-dialog-ok" disabled>' + (i18n.confirm || '') + '</button>' +
            '<button type="button" class="button payping-refund-dialog-cancel">' + (i18n.cancel || '') + '</button>' +
            '</div>' +
            '</div>';

        document.body.appendChild(overlay);

        var okButton = overlay.querySelector('.payping-refund-dialog-ok');

        function close() {
            overlay.remove();
            document.removeEventListener('keydown', onKey);
        }

        function onKey(event) {
            if (event.key === 'Escape') {
                close();
            }
        }

        function fill(data) {
            var note = overlay.querySelector('.payping-refund-dialog-note');

            // Empty box or a zero/negative entry: WooCommerce would
            // reject both with a raw English alert, so the dialog
            // blocks them with a Persian note instead.
            if (data.invalid) {
                overlay.querySelector('[data-row="order"] strong').textContent = data.total_label || '';
                overlay.querySelector('[data-row="amount"] strong').textContent = '—';
                overlay.querySelector('[data-row="wage"] strong').textContent = '—';
                overlay.querySelector('[data-row="total"] strong').textContent = '—';
                note.textContent = data.empty ? (i18n.emptyNote || '') : (i18n.invalidNote || '');
                note.classList.add('payping-refund-dialog-note-error');
                return;
            }

            // Amount above the refundable rest: block the refund here
            // instead of letting WooCommerce reject it later. A fully
            // refunded order always lands here; explain that case with
            // its own message so "above the total" reads clearly.
            if (data.over) {
                overlay.querySelector('[data-row="order"] strong').textContent = data.total_label || '';
                overlay.querySelector('[data-row="amount"] strong').textContent = data.entered_label || '—';
                overlay.querySelector('[data-row="wage"] strong').textContent = '—';
                overlay.querySelector('[data-row="total"] strong').textContent = (i18n.maxLabel || '') + ': ' + (data.remaining_label || '');
                note.textContent = data.fully ? (i18n.fullyNote || '') : (i18n.overNote || '');
                note.classList.add('payping-refund-dialog-note-error');
                return;
            }

            overlay.querySelector('[data-row="order"] strong').textContent = data.total_label || '';
            overlay.querySelector('[data-row="amount"] strong').textContent = data.amount_label || (i18n.wagePerTariff || '');
            overlay.querySelector('[data-row="wage"] strong').textContent = data.wage_label || (i18n.wagePerTariff || '');
            overlay.querySelector('[data-row="total"] strong').textContent = data.debit_label || (i18n.wagePerTariff || '');
            okButton.disabled = false;
            okButton.focus();
        }

        post('woo_payping_refund_quote', {
            order_id: cfg.orderId || 0,
            amount: refundAmount()
        }).then(function (res) {
            fill((res && res.success && res.data) ? res.data : {});
        }).catch(function () {
            fill({});
        });

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                close();
            }
        });
        document.addEventListener('keydown', onKey);
        overlay.querySelector('.payping-refund-dialog-cancel').addEventListener('click', close);

        okButton.addEventListener('click', function () {
            close();
            // Replay the click. The confirm stub keeps WooCommerce from
            // asking the same question a second time.
            passThrough = true;
            var nativeConfirm = window.confirm;
            window.confirm = function () {
                return true;
            };
            try {
                refundButton.click();
            } finally {
                window.confirm = nativeConfirm;
                passThrough = false;
            }
        });
    }
}());
