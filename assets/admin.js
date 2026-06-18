/* WP SMS Panel — settings page: provider field toggle + test/credit AJAX */
(function ($) {
    "use strict";

    // Show only the active provider's field group.
    function toggle() {
        var active = $("#wpsp-provider").val();
        $(".wpsp-provider-fields").each(function () {
            $(this).toggle($(this).data("provider") === active);
        });
    }

    $(document).on("change", "#wpsp-provider", toggle);
    toggle();

    // Colour pickers.
    if ($.fn.wpColorPicker) {
        $(".wpsp-color").wpColorPicker();
    }

    function result(text, ok) {
        $("#wpsp-test-result")
            .css("color", ok ? "#1da06a" : "#d92c2c")
            .text(text || "");
    }

    function call(action, data, $btn, busyText) {
        var original = $btn.text();
        $btn.prop("disabled", true).text(busyText);
        result("", true);
        $.post(WPSMSPanelAdmin.ajaxurl, $.extend({ action: action, nonce: WPSMSPanelAdmin.nonce }, data))
            .done(function (res) {
                if (res && res.success) {
                    result(res.data.message, true);
                } else {
                    result((res.data && res.data.message) || WPSMSPanelAdmin.i18n.error, false);
                }
            })
            .fail(function () { result(WPSMSPanelAdmin.i18n.error, false); })
            .always(function () { $btn.prop("disabled", false).text(original); });
    }

    $("#wpsp-test-btn").on("click", function () {
        call("wp_sms_panel_test", { phone: $("#wpsp-test-phone").val() }, $(this), WPSMSPanelAdmin.i18n.testing);
    });

    $("#wpsp-credit-btn").on("click", function () {
        call("wp_sms_panel_credit", {}, $(this), WPSMSPanelAdmin.i18n.checking);
    });
})(jQuery);
