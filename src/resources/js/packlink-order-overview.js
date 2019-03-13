var Packlink = window.Packlink || {};

document.addEventListener('DOMContentLoaded', function () {
    let buttons = document.querySelectorAll('.pl-print-label');
    let form = document.querySelector('#posts-filter');
    let downloadTimer, attempts = 30;
    let endpointUrl = document.querySelector('input[name="packlink-url-callback"]');

    if (endpointUrl && buttons) {
        [].forEach.call(buttons, function (button) {
            button.addEventListener('click', function (event) {
                let id = button.getAttribute('data-pl-id');
                let link = button.getAttribute('data-pl-label');
                event.stopPropagation();
                window.open(link, '_blank');
                Packlink.ajaxService.post(endpointUrl.value, {id: id}, function () {
                    button.classList.remove('button-primary');
                }, function () {
                });

            });
        });
    }

    if (form) {
        form.addEventListener('submit', function () {
            if (this.action.value === 'packlink_print_labels') {
                startCookieCheck(setFormToken(form));
            }
        })
    }

    /**
     * Sets form hidden input.
     *
     * @param form Form element.
     * @returns {number | *}
     */
    function setFormToken(form) {
        let downloadToken = document.createElement('input');

        downloadToken.type = 'hidden';
        downloadToken.name = 'packlink_download_token';
        downloadToken.value = new Date().getTime();

        form.appendChild(downloadToken);

        return downloadToken.value;
    }

    /**
     * Returns cookie value.
     *
     * @param {string} name Cookie name.
     * @returns {string} Cookie value.
     */
    function getCookie(name) {
        let parts = document.cookie.split(name + "=");
        if (parts.length === 2) return parts.pop().split(";").shift();
    }

    /**
     * Sets cookie as expired.
     *
     * @param {string} cName
     */
    function expireCookie(cName) {
        document.cookie = encodeURIComponent(cName) + "=deleted; expires=" + new Date(0).toUTCString();
    }

    /**
     * Prevents double-submits by waiting for a cookie from the server.
     *
     * @param {string} downloadToken
     */
    function startCookieCheck(downloadToken) {
        form.style.cursor = 'wait';
        downloadTimer = window.setInterval(function () {
            let token = getCookie("packlink_download_token");

            if (token === downloadToken || attempts === 0) {
                form.style.cursor = 'auto';
                expireCookie("packlink_download_token");
                window.location.reload(true);
            }

            attempts--;
        }, 1000);
    }

});