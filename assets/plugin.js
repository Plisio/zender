(function ($) {
    "use strict";
    $(function () {
        $(document).on("pluginModalEvent", function (e) {
            if ($("[zender-payments]").length) {
                $("[zender-payments]").prepend(`
                <div class="col-md-6">
                    <a href="${site_url}/plugin?name=plisio-gateway" class="btn btn-white btn-block mb-2 lift">
                    <img style="width: 105px; height: 55px;" src="${site_url}/system/plugins/installables/plisio-gateway/assets/logo.png">
                    </a>
                </div>
                `);
            }
        });
    });
})(jQuery);